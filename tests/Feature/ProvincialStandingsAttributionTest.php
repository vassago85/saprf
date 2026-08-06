<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\StandingsCalculationService;

beforeEach(function () {
    $this->gp = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $this->wc = Province::create(['name' => 'Western Cape', 'abbreviation' => 'WC']);
    $this->division = Division::create(['slug' => 'open', 'name' => 'Open']);
    $this->service = app(StandingsCalculationService::class);
});

function provincialMatch(Province $host): MatchEvent
{
    return MatchEvent::create([
        'name' => 'Provincial '.$host->abbreviation,
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $host->id,
        'match_date' => '2026-03-15',
        'status' => 'completed',
        'created_by' => User::factory()->create()->id,
        'active_member_fee' => 500,
        'published' => true,
    ]);
}

function provincialScore(MatchEvent $match, User $user, float $raw, int $divisionId): Score
{
    return Score::create([
        'match_id' => $match->id,
        'shooter_name' => $user->name,
        'user_id' => $user->id,
        'raw_score' => $raw,
        'division_id' => $divisionId,
        'status' => 'valid',
        'counts_for_season' => true,
        'match_date' => $match->match_date->toDateString(),
    ]);
}

it('scores a shooter in their home province, not the host province', function () {
    $match = provincialMatch($this->wc); // hosted in Western Cape

    $gpShooter = User::factory()->create(['province_id' => $this->gp->id]);
    $wcShooter = User::factory()->create(['province_id' => $this->wc->id]);

    provincialScore($match, $gpShooter, 48, $this->division->id);
    provincialScore($match, $wcShooter, 40, $this->division->id);

    $this->service->recalculateForMatch($match);

    // Gauteng shooter is credited to Gauteng even though the match was in WC.
    expect(Standing::where('user_id', $gpShooter->id)->where('province_id', $this->gp->id)->whereNull('division_id')->exists())->toBeTrue()
        ->and(Standing::where('user_id', $gpShooter->id)->where('province_id', $this->wc->id)->exists())->toBeFalse();

    // Western Cape shooter stays in WC.
    expect(Standing::where('user_id', $wcShooter->id)->where('province_id', $this->wc->id)->whereNull('division_id')->exists())->toBeTrue()
        ->and(Standing::where('user_id', $wcShooter->id)->where('province_id', $this->gp->id)->exists())->toBeFalse();
});

it('gives each shooter first place in their own province — no duplicate podiums', function () {
    $match = provincialMatch($this->wc);

    $gpShooter = User::factory()->create(['province_id' => $this->gp->id]);
    $wcShooter = User::factory()->create(['province_id' => $this->wc->id]);

    provincialScore($match, $gpShooter, 48, $this->division->id);
    provincialScore($match, $wcShooter, 40, $this->division->id);

    $this->service->recalculateForMatch($match);

    expect(Standing::where('user_id', $gpShooter->id)->where('province_id', $this->gp->id)->whereNull('division_id')->value('rank'))->toBe(1)
        ->and(Standing::where('user_id', $wcShooter->id)->where('province_id', $this->wc->id)->whereNull('division_id')->value('rank'))->toBe(1);

    // Exactly one shooter in each provincial overall table.
    expect(Standing::where('province_id', $this->gp->id)->whereNull('division_id')->count())->toBe(1)
        ->and(Standing::where('province_id', $this->wc->id)->whereNull('division_id')->count())->toBe(1);
});

it('aggregates a shooter\'s out-of-province results into their home province only', function () {
    $gpShooter = User::factory()->create(['province_id' => $this->gp->id]);

    $homeMatch = provincialMatch($this->gp);
    provincialScore($homeMatch, $gpShooter, 50, $this->division->id);
    $this->service->recalculateForMatch($homeMatch);

    $awayMatch = provincialMatch($this->wc);
    provincialScore($awayMatch, $gpShooter, 30, $this->division->id);
    $this->service->recalculateForMatch($awayMatch);

    // One aggregated row in Gauteng, nothing in Western Cape.
    expect(Standing::where('user_id', $gpShooter->id)->where('province_id', $this->gp->id)->whereNull('division_id')->count())->toBe(1)
        ->and(Standing::where('user_id', $gpShooter->id)->where('province_id', $this->wc->id)->exists())->toBeFalse();
});

it('never feeds a national match into the provincial standing, even when the legacy dual-count flag is set', function () {
    // Rule with best_of_count = 3 (best-of-N provincial mode).
    \App\Models\QualificationRule::create([
        'series' => 'PR22',
        'season' => '2026',
        'scoring_mode' => 'best_of_n',
        'best_of_count' => 3,
        'total_qualifying_matches' => 3,
        'min_out_of_province_matches' => 0,
        'created_by' => User::factory()->create()->id,
    ]);

    $shooter = User::factory()->create(['province_id' => $this->gp->id]);

    // A 2-day national hosted in GP, flagged also_counts_for_provincial=true
    // and carrying a provincial_normalized_score — mimicking the legacy
    // dual-count setup. Under the new rules this MUST NOT contribute to the
    // provincial standing; day-1 belongs on its own separate provincial match.
    $nat = MatchEvent::create([
        'name' => '2-Day National GP',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'national',
        'season' => '2026',
        'province_id' => $this->gp->id,
        'match_date' => '2026-06-06',
        'status' => 'completed',
        'created_by' => User::factory()->create()->id,
        'active_member_fee' => 500,
        'published' => true,
        'also_counts_for_provincial' => true,
    ]);
    $top = User::factory()->create();
    Score::create([
        'match_id' => $nat->id, 'user_id' => $top->id, 'shooter_name' => $top->name,
        'raw_score' => 100, 'division_id' => $this->division->id,
        'status' => 'valid', 'counts_for_season' => true,
        'match_date' => $nat->match_date->toDateString(),
        'provincial_raw_score' => 100, 'provincial_normalized_score' => 100,
    ]);
    Score::create([
        'match_id' => $nat->id, 'user_id' => $shooter->id, 'shooter_name' => $shooter->name,
        'raw_score' => 90, 'division_id' => $this->division->id,
        'status' => 'valid', 'counts_for_season' => true,
        'match_date' => $nat->match_date->toDateString(),
        'provincial_raw_score' => 90, 'provincial_normalized_score' => 90,
    ]);
    $this->service->recalculateForMatch($nat);

    // No provincial standing row should have been created for either shooter
    // from this national-only match.
    expect(Standing::where('user_id', $shooter->id)->where('province_id', $this->gp->id)->exists())->toBeFalse()
        ->and(Standing::where('user_id', $top->id)->where('province_id', $this->gp->id)->exists())->toBeFalse();
});

it('records per-match provincial contribution in the standings pool_breakdown', function () {
    // Rule with best_of_count = 2 so we can force a drop.
    \App\Models\QualificationRule::create([
        'series' => 'PR22',
        'season' => '2026',
        'scoring_mode' => 'best_of_n',
        'best_of_count' => 2,
        'total_qualifying_matches' => 3,
        'min_out_of_province_matches' => 0,
        'created_by' => User::factory()->create()->id,
    ]);

    $shooter = User::factory()->create(['province_id' => $this->gp->id]);

    // Three provincial matches: 100, 50, 25. Best-of-2 keeps 100 and 50;
    // 25 is dropped (valid but non-contributing).
    $matches = [];
    foreach ([['A', 100], ['B', 50], ['C', 25]] as [$suffix, $normalized]) {
        $match = MatchEvent::create([
            'name' => 'Provincial GP '.$suffix,
            'match_type' => 'PR22',
            'series_level' => 'provincial',
            'series' => 'PR22',
            'season' => '2026',
            'province_id' => $this->gp->id,
            'match_date' => '2026-03-15',
            'status' => 'completed',
            'created_by' => User::factory()->create()->id,
            'active_member_fee' => 500,
            'published' => true,
        ]);
        // Sole shooter → normalized = 100. Add a bench shooter so we can
        // control the shooter's normalized pct.
        $top = User::factory()->create();
        Score::create([
            'match_id' => $match->id, 'user_id' => $top->id, 'shooter_name' => $top->name,
            'raw_score' => 100, 'division_id' => $this->division->id,
            'status' => 'valid', 'counts_for_season' => true, 'match_date' => $match->match_date->toDateString(),
        ]);
        Score::create([
            'match_id' => $match->id, 'user_id' => $shooter->id, 'shooter_name' => $shooter->name,
            'raw_score' => $normalized, 'division_id' => $this->division->id,
            'status' => 'valid', 'counts_for_season' => true, 'match_date' => $match->match_date->toDateString(),
        ]);
        $matches[$suffix] = $match;
        $this->service->recalculateForMatch($match);
    }

    $standing = Standing::where('user_id', $shooter->id)
        ->where('province_id', $this->gp->id)
        ->whereNull('division_id')
        ->firstOrFail();

    $breakdown = $standing->pool_breakdown;

    expect($breakdown['mode'] ?? null)->toBe('best_of_n')
        ->and($breakdown['scores_counted'] ?? null)->toBe(2)
        ->and($breakdown['matches'] ?? [])->toHaveCount(3);

    // Matches are sorted by contribution desc: 100, 50 counted; 25 dropped.
    $rows = collect($breakdown['matches']);

    $top = $rows->first();
    expect($top['match_id'])->toBe($matches['A']->id)
        ->and((bool) $top['counted'])->toBeTrue()
        ->and((float) $top['contribution'])->toBe(100.0);

    $mid = $rows[1];
    expect($mid['match_id'])->toBe($matches['B']->id)
        ->and((bool) $mid['counted'])->toBeTrue()
        ->and((float) $mid['contribution'])->toBe(50.0);

    $dropped = $rows->last();
    expect($dropped['match_id'])->toBe($matches['C']->id)
        ->and((bool) $dropped['counted'])->toBeFalse()
        ->and((float) $dropped['contribution'])->toBe(0.0);
});

it('uses the best 3 provincial scores for a weighted-pools PR22 rule (best_of_count null, provincial_pool_best_of 3)', function () {
    // This mirrors the REAL seeded PR22 rule: weighted_pools with a null
    // best_of_count. The provincial best-of lives in provincial_pool_best_of,
    // and the provincial standing table must honour it (top-3), not sum every
    // provincial match — matching the PRS provincial table's behaviour.
    \App\Models\QualificationRule::create([
        'series' => 'PR22',
        'season' => '2026',
        'scoring_mode' => 'weighted_pools',
        'best_of_count' => null,
        'total_qualifying_matches' => 6,
        'min_out_of_province_matches' => 1,
        'provincial_pool_best_of' => 3,
        'provincial_pool_weight_pct' => 30.00,
        'national_pool_best_of' => 2,
        'national_pool_weight_pct' => 40.00,
        'champs_pool_best_of' => 1,
        'champs_pool_weight_pct' => 30.00,
        'created_by' => User::factory()->create()->id,
    ]);

    $shooter = User::factory()->create(['province_id' => $this->gp->id]);

    // Four provincial matches: 100, 80, 60, 40. Best-of-3 keeps 100+80+60=240
    // and drops the 40.
    $matches = [];
    foreach ([['A', 100], ['B', 80], ['C', 60], ['D', 40]] as [$suffix, $normalized]) {
        $match = MatchEvent::create([
            'name' => 'Provincial GP '.$suffix,
            'match_type' => 'PR22',
            'series_level' => 'provincial',
            'series' => 'PR22',
            'season' => '2026',
            'province_id' => $this->gp->id,
            'match_date' => '2026-03-15',
            'status' => 'completed',
            'created_by' => User::factory()->create()->id,
            'active_member_fee' => 500,
            'published' => true,
        ]);
        // Bench shooter at 100 raw so the tracked shooter's normalized pct
        // equals $normalized.
        $top = User::factory()->create();
        Score::create([
            'match_id' => $match->id, 'user_id' => $top->id, 'shooter_name' => $top->name,
            'raw_score' => 100, 'division_id' => $this->division->id,
            'status' => 'valid', 'counts_for_season' => true, 'match_date' => $match->match_date->toDateString(),
        ]);
        Score::create([
            'match_id' => $match->id, 'user_id' => $shooter->id, 'shooter_name' => $shooter->name,
            'raw_score' => $normalized, 'division_id' => $this->division->id,
            'status' => 'valid', 'counts_for_season' => true, 'match_date' => $match->match_date->toDateString(),
        ]);
        $matches[$suffix] = $match;
        $this->service->recalculateForMatch($match);
    }

    $standing = Standing::where('user_id', $shooter->id)
        ->where('province_id', $this->gp->id)
        ->whereNull('division_id')
        ->firstOrFail();

    expect((float) $standing->points)->toBe(240.0)
        ->and($standing->pool_breakdown['mode'] ?? null)->toBe('best_of_n')
        ->and($standing->pool_breakdown['scores_counted'] ?? null)->toBe(3)
        ->and($standing->pool_breakdown['matches'] ?? [])->toHaveCount(4);

    // The 40 is present but dropped.
    $dropped = collect($standing->pool_breakdown['matches'])->last();
    expect($dropped['match_id'])->toBe($matches['D']->id)
        ->and((bool) $dropped['counted'])->toBeFalse();
});
