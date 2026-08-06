<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\StandingsCalculationService;

/*
|--------------------------------------------------------------------------
| scores:reevaluate integration
|--------------------------------------------------------------------------
|
| The command has two jobs: reconcile membership + score status flags,
| AND rebuild season standings. The season aggregation reads persisted
| `normalized_score` / `division_normalized_score` values off each Score
| row — so if a match's per-match ranking is stale (e.g. a raw score was
| edited after the initial rank pass, or a shooter's division/status was
| corrected later without a manual rerank), the season totals will keep
| reproducing the stale numbers no matter how many times you re-run the
| command.
|
| These tests pin that `scores:reevaluate` re-ranks every match before it
| aggregates, so a real production run always yields self-consistent
| overall + division standings.
*/

it('re-ranks every match before aggregating season standings, fixing stale division_normalized_score', function () {
    $province = Province::create(['name' => 'Western Cape', 'abbreviation' => 'WC']);
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);

    $dirk = User::factory()->create(['name' => 'Dirk', 'province_id' => $province->id]);
    $johan = User::factory()->create(['name' => 'Johan', 'province_id' => $province->id]);

    foreach ([$dirk, $johan] as $u) {
        Membership::create([
            'user_id' => $u->id, 'saprf_number' => 'T-'.$u->id, 'membership_type' => 'paid',
            'status' => 'active', 'payment_status' => 'paid',
            'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
        ]);
    }

    $match = MatchEvent::create([
        'name' => 'Centrefire WC 2-Day National', 'match_type' => 'PRS', 'series' => 'PRS',
        'series_level' => 'national', 'season' => '2026', 'province_id' => $province->id,
        'match_date' => '2026-05-16', 'status' => 'completed',
        'created_by' => $dirk->id, 'active_member_fee' => 500, 'published' => true,
    ]);

    // Dirk (112) is #1 Open, Johan (110) is #2 Open. Correct division-normalized
    // score for Johan is 110/112 * 100 = 98.21 — NOT 100.
    $dirkScore = Score::create([
        'match_id' => $match->id, 'user_id' => $dirk->id, 'shooter_name' => 'Dirk',
        'division_id' => $open->id, 'raw_score' => 112,
        'status' => 'valid', 'is_member' => true, 'match_date' => $match->match_date,
    ]);
    $johanScore = Score::create([
        'match_id' => $match->id, 'user_id' => $johan->id, 'shooter_name' => 'Johan',
        'division_id' => $open->id, 'raw_score' => 110,
        'status' => 'valid', 'is_member' => true, 'match_date' => $match->match_date,
    ]);

    // Simulate the real-world stale state: an earlier rank pass ran when Dirk
    // wasn't yet in the visible set, so Johan was persisted as top Open
    // (division_normalized_score = 100). Overall normalisation was later fixed
    // (98.21) but division-normalisation was never re-run.
    $johanScore->update([
        'normalized_score' => 98.21,
        'division_normalized_score' => 100.00,
        'overall_rank' => 2,
        'division_rank' => 1,
    ]);
    $dirkScore->update([
        'normalized_score' => 100.00,
        'division_normalized_score' => 100.00,
        'overall_rank' => 1,
        'division_rank' => 2, // deliberately wrong to prove rerank corrects it
    ]);

    // Sanity: no automatic rerank happens on a plain save.
    expect((float) $johanScore->fresh()->division_normalized_score)->toBe(100.00);

    $this->artisan('scores:reevaluate', ['--skip-free-fix' => true, '--skip-expiry-fix' => true])
        ->assertExitCode(0);

    // After reevaluate: match rankings have been recomputed, so Johan's
    // division_normalized_score reflects the actual top-Open score (Dirk's 112).
    $johanFresh = $johanScore->fresh();
    expect(round((float) $johanFresh->division_normalized_score, 2))->toBe(98.21);
    expect((int) $johanFresh->division_rank)->toBe(2);

    $dirkFresh = $dirkScore->fresh();
    expect(round((float) $dirkFresh->division_normalized_score, 2))->toBe(100.00);
    expect((int) $dirkFresh->division_rank)->toBe(1);

    // And the persisted season standing for Johan (Open division) now sums to
    // 98.21, not the 100.00 the stale rank would have produced.
    $johanOpenStanding = Standing::where('user_id', $johan->id)
        ->where('series', 'PRS')->where('season', '2026')
        ->whereNull('province_id')
        ->where('division_id', $open->id)
        ->first();
    expect($johanOpenStanding)->not->toBeNull();
    expect(round((float) $johanOpenStanding->points, 2))->toBe(98.21);
});

it('re-ranks PR22 matches too, so weighted-pool division standings are self-consistent', function () {
    // Same stale-division scenario as the PRS test, but for PR22 — PR22 uses
    // weighted_pools aggregation which reads division_normalized_score via a
    // different code path (contributionForPool → normalizedScoreForContext).
    // Any fix for PRS division drift must equally fix PR22.
    $province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);

    \App\Models\QualificationRule::create([
        'series' => 'PR22', 'season' => '2026',
        'scoring_mode' => 'weighted_pools',
        'best_of_count' => 3, 'total_qualifying_matches' => 3, 'min_out_of_province_matches' => 0,
        'provincial_pool_best_of' => 1, 'provincial_pool_weight_pct' => 30,
        'national_pool_best_of' => 1, 'national_pool_weight_pct' => 40,
        'champs_pool_best_of' => 1, 'champs_pool_weight_pct' => 30,
        'created_by' => User::factory()->create()->id,
    ]);

    $top = User::factory()->create(['name' => 'Top Open', 'province_id' => $province->id]);
    $second = User::factory()->create(['name' => 'Second Open', 'province_id' => $province->id]);
    foreach ([$top, $second] as $u) {
        Membership::create([
            'user_id' => $u->id, 'saprf_number' => 'T-'.$u->id, 'membership_type' => 'paid',
            'status' => 'active', 'payment_status' => 'paid',
            'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
        ]);
    }

    $match = MatchEvent::create([
        'name' => 'PR22 National', 'match_type' => 'PR22', 'series' => 'PR22',
        'series_level' => 'national', 'season' => '2026', 'province_id' => $province->id,
        'match_date' => '2026-05-20', 'status' => 'completed',
        'created_by' => $top->id, 'active_member_fee' => 500, 'published' => true,
    ]);

    Score::create([
        'match_id' => $match->id, 'user_id' => $top->id, 'shooter_name' => 'Top',
        'division_id' => $open->id, 'raw_score' => 100,
        'status' => 'valid', 'is_member' => true, 'match_date' => $match->match_date,
    ]);
    $secondScore = Score::create([
        'match_id' => $match->id, 'user_id' => $second->id, 'shooter_name' => 'Second',
        'division_id' => $open->id, 'raw_score' => 80,
        'status' => 'valid', 'is_member' => true, 'match_date' => $match->match_date,
    ]);

    // Simulate stale state: 2nd shooter's division-normalized was persisted
    // as 100 (top-Open at the time), but real ratio is 80/100 = 80%.
    $secondScore->update([
        'normalized_score' => 80.00,
        'division_normalized_score' => 100.00,
        'overall_rank' => 2,
        'division_rank' => 1,
    ]);

    $this->artisan('scores:reevaluate', ['--skip-free-fix' => true, '--skip-expiry-fix' => true])
        ->assertExitCode(0);

    $secondFresh = $secondScore->fresh();
    expect(round((float) $secondFresh->division_normalized_score, 2))->toBe(80.00);
    expect((int) $secondFresh->division_rank)->toBe(2);
});

it('leaves fresh, already-correct per-match rankings unchanged after reevaluate', function () {
    // Regression guard: a re-rank of a match that's already correctly ranked
    // must produce the same values — no drift on repeat runs.
    $province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);

    $a = User::factory()->create(['province_id' => $province->id]);
    $b = User::factory()->create(['province_id' => $province->id]);
    foreach ([$a, $b] as $u) {
        Membership::create([
            'user_id' => $u->id, 'saprf_number' => 'T-'.$u->id, 'membership_type' => 'paid',
            'status' => 'active', 'payment_status' => 'paid',
            'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
        ]);
    }

    $match = MatchEvent::create([
        'name' => 'GP National', 'match_type' => 'PRS', 'series' => 'PRS',
        'series_level' => 'national', 'season' => '2026', 'province_id' => $province->id,
        'match_date' => '2026-06-20', 'status' => 'completed',
        'created_by' => $a->id, 'active_member_fee' => 500, 'published' => true,
    ]);

    Score::create([
        'match_id' => $match->id, 'user_id' => $a->id, 'shooter_name' => 'A',
        'division_id' => $open->id, 'raw_score' => 100,
        'status' => 'valid', 'is_member' => true, 'match_date' => $match->match_date,
    ]);
    Score::create([
        'match_id' => $match->id, 'user_id' => $b->id, 'shooter_name' => 'B',
        'division_id' => $open->id, 'raw_score' => 80,
        'status' => 'valid', 'is_member' => true, 'match_date' => $match->match_date,
    ]);

    // First rank pass (canonical).
    app(StandingsCalculationService::class)->calculateMatchRankings($match);

    $bScoreBefore = Score::where('user_id', $b->id)->first();
    $expectedBNorm = round((float) $bScoreBefore->normalized_score, 4);
    $expectedBDivNorm = round((float) $bScoreBefore->division_normalized_score, 4);

    // Reevaluate must not drift the values.
    $this->artisan('scores:reevaluate', ['--skip-free-fix' => true, '--skip-expiry-fix' => true])
        ->assertExitCode(0);

    $bScoreAfter = $bScoreBefore->fresh();
    expect(round((float) $bScoreAfter->normalized_score, 4))->toBe($expectedBNorm);
    expect(round((float) $bScoreAfter->division_normalized_score, 4))->toBe($expectedBDivNorm);
});
