<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\User;
use App\Services\StandingsCalculationService;

/**
 * PRS annual "national log":
 *   total = best 3 regular (national) match %s + fixed champs (final) %  (max 400)
 * Only national + final matches count; no provincial dimension.
 */

function prsRule(): QualificationRule
{
    return QualificationRule::create([
        'series' => 'PRS',
        'season' => '2026',
        'scoring_mode' => 'best_n_plus_champs',
        'min_out_of_province_matches' => 0,
        'best_of_count' => 3,
        'total_qualifying_matches' => 4,
        'created_by' => User::factory()->create()->id,
    ]);
}

function prsMatch(string $name, string $level, string $date): MatchEvent
{
    return MatchEvent::create([
        'name' => $name,
        'match_type' => 'PRS',
        'series_level' => $level,
        'series' => 'PRS',
        'season' => '2026',
        'match_date' => $date,
        'status' => 'completed',
        'created_by' => User::factory()->create()->id,
        'active_member_fee' => 500,
        'published' => true,
    ]);
}

function prsScore(MatchEvent $match, User $user, float $raw, int $divisionId): Score
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

function recalcPrs(): void
{
    $service = app(StandingsCalculationService::class);
    foreach (MatchEvent::all() as $m) {
        $service->calculateMatchRankings($m);
    }
    // National (annual log) + every provincial table. PRS provincial follows
    // the same shooter's-home-province attribution rule as PR22.
    $service->recalculateSeasonStandings('PRS', '2026');
    $service->recalculateProvincialStandings('PRS', '2026');
}

beforeEach(function () {
    $this->open = Division::create(['slug' => 'open', 'name' => 'Open']);
    prsRule();
});

it('normalises within a match: winner = 100, others relative to winner', function () {
    $match = prsMatch('N1', 'national', '2026-03-01');
    $winner = User::factory()->create(['name' => 'Winner']);
    $second = User::factory()->create(['name' => 'Second']);

    prsScore($match, $winner, 50.0, $this->open->id); // 100%
    prsScore($match, $second, 42.0, $this->open->id); // 42/50 = 84.00%

    recalcPrs();

    $log = app(StandingsCalculationService::class)->annualLog('PRS', '2026', $this->open->id);

    expect($log->firstWhere('user_id', $winner->id)['total'])->toBe(100.00);
    expect($log->firstWhere('user_id', $second->id)['total'])->toBe(84.00);
});

it('gives every tied top raw score 100% and normalises others against it', function () {
    $match = prsMatch('N-tie', 'national', '2026-03-01');
    $a = User::factory()->create();
    $b = User::factory()->create();
    $c = User::factory()->create();

    prsScore($match, $a, 48.0, $this->open->id); // tied top -> 100
    prsScore($match, $b, 48.0, $this->open->id); // tied top -> 100
    prsScore($match, $c, 24.0, $this->open->id); // 24/48 = 50

    recalcPrs();
    $log = app(StandingsCalculationService::class)->annualLog('PRS', '2026', $this->open->id);

    expect($log->firstWhere('user_id', $a->id)['total'])->toBe(100.00);
    expect($log->firstWhere('user_id', $b->id)['total'])->toBe(100.00);
    expect($log->firstWhere('user_id', $c->id)['total'])->toBe(50.00);
});

it('scores a raw 0 as 0% for the match', function () {
    $match = prsMatch('N-zero', 'national', '2026-03-01');
    $top = User::factory()->create();
    $zero = User::factory()->create();

    prsScore($match, $top, 40.0, $this->open->id);
    prsScore($match, $zero, 0.0, $this->open->id);

    recalcPrs();
    $log = app(StandingsCalculationService::class)->annualLog('PRS', '2026', $this->open->id);

    expect($log->firstWhere('user_id', $zero->id)['total'])->toBe(0.00);
});

it('takes only the best 3 regular matches and excludes the 4th best', function () {
    // Shooter shoots 4 nationals. In each, they are the sole shooter of that
    // match, so their percentage is controlled by making a stronger co-shooter.
    $shooter = User::factory()->create(['name' => 'Multi']);

    // Build 4 nationals where the shooter's normalized % is 100, 90, 80, 70.
    $pcts = [100, 90, 80, 70];
    foreach ($pcts as $i => $pct) {
        $match = prsMatch("N{$i}", 'national', '2026-0'.($i + 1).'-01');
        // top raw = 100 so the shooter's raw == pct gives exactly pct%.
        $top = User::factory()->create();
        prsScore($match, $top, 100.0, $this->open->id);
        prsScore($match, $shooter, (float) $pct, $this->open->id);
    }

    recalcPrs();
    $log = app(StandingsCalculationService::class)->annualLog('PRS', '2026', $this->open->id);
    $row = $log->firstWhere('user_id', $shooter->id);

    // Best 3 = 100 + 90 + 80 = 270. The 70 (4th best) is dropped. No champs.
    expect($row['total'])->toBe(270.00);
    expect($row['champs_pct'])->toBe(0.00);
    $countedPcts = collect($row['regular'])->pluck('pct')->map(fn ($v) => (float) $v);
    expect($countedPcts->all())->toBe([100.00, 90.00, 80.00]);
    // 4th best explicitly excluded
    expect($countedPcts->contains(70.00))->toBeFalse();
});

it('adds a non-droppable champs on top of best 3 regulars (max 400)', function () {
    $shooter = User::factory()->create(['name' => 'Champ']);

    foreach ([0, 1, 2] as $i) {
        $match = prsMatch("N{$i}", 'national', '2026-0'.($i + 1).'-01');
        $top = User::factory()->create();
        prsScore($match, $top, 100.0, $this->open->id);
        prsScore($match, $shooter, 100.0, $this->open->id); // 100%
    }

    $champs = prsMatch('SA Champs', 'final', '2026-11-01');
    $topC = User::factory()->create();
    prsScore($champs, $topC, 100.0, $this->open->id);
    prsScore($champs, $shooter, 100.0, $this->open->id); // 100% champs

    recalcPrs();
    $row = app(StandingsCalculationService::class)->annualLog('PRS', '2026', $this->open->id)
        ->firstWhere('user_id', $shooter->id);

    expect($row['total'])->toBe(400.00);
    expect($row['champs_pct'])->toBe(100.00);
    expect($row['max'])->toBe(400);
});

it('caps at 300 when a shooter has four 100% regulars but no champs', function () {
    $shooter = User::factory()->create(['name' => 'NoChamps']);

    foreach ([0, 1, 2, 3] as $i) {
        $match = prsMatch("N{$i}", 'national', '2026-0'.($i + 1).'-01');
        $top = User::factory()->create();
        prsScore($match, $top, 100.0, $this->open->id);
        prsScore($match, $shooter, 100.0, $this->open->id); // four 100% regulars
    }

    recalcPrs();
    $row = app(StandingsCalculationService::class)->annualLog('PRS', '2026', $this->open->id)
        ->firstWhere('user_id', $shooter->id);

    // Champs is fixed at 0 and cannot be replaced by the 4th 100% regular.
    expect($row['total'])->toBe(300.00);
    expect($row['champs_pct'])->toBe(0.00);
});

it('totals champs only when a shooter shot champs but zero regulars', function () {
    $shooter = User::factory()->create(['name' => 'OnlyChamps']);

    $champs = prsMatch('SA Champs', 'final', '2026-11-01');
    $top = User::factory()->create();
    prsScore($champs, $top, 100.0, $this->open->id);
    prsScore($champs, $shooter, 80.0, $this->open->id); // 80% champs

    recalcPrs();
    $row = app(StandingsCalculationService::class)->annualLog('PRS', '2026', $this->open->id)
        ->firstWhere('user_id', $shooter->id);

    expect($row['total'])->toBe(80.00);
    expect($row['regular'])->toBe([]);
    expect($row['champs_pct'])->toBe(80.00);
});

it('sums whatever exists when fewer than 3 regular matches are shot', function () {
    $shooter = User::factory()->create(['name' => 'TwoOnly']);

    foreach ([[0, 100.0], [1, 50.0]] as [$i, $raw]) {
        $match = prsMatch("N{$i}", 'national', '2026-0'.($i + 1).'-01');
        $top = User::factory()->create();
        prsScore($match, $top, 100.0, $this->open->id);
        prsScore($match, $shooter, $raw, $this->open->id);
    }

    recalcPrs();
    $row = app(StandingsCalculationService::class)->annualLog('PRS', '2026', $this->open->id)
        ->firstWhere('user_id', $shooter->id);

    // 100 + 50, no penalty for the missing third match, no champs.
    expect($row['total'])->toBe(150.00);
    expect($row['regular_total'])->toBe(150.00);
});

it('ranks ties with standard competition ranking (1, 2, 2, 4)', function () {
    $match = prsMatch('N1', 'national', '2026-03-01');

    // Raw scores 100, 80, 80, 40 -> %s 100, 80, 80, 40 (top raw = 100).
    $u1 = User::factory()->create(['name' => 'First']);
    $u2 = User::factory()->create(['name' => 'TieA']);
    $u3 = User::factory()->create(['name' => 'TieB']);
    $u4 = User::factory()->create(['name' => 'Last']);

    prsScore($match, $u1, 100.0, $this->open->id);
    prsScore($match, $u2, 80.0, $this->open->id);
    prsScore($match, $u3, 80.0, $this->open->id);
    prsScore($match, $u4, 40.0, $this->open->id);

    recalcPrs();
    $log = app(StandingsCalculationService::class)->annualLog('PRS', '2026', $this->open->id)
        ->keyBy('user_id');

    expect($log[$u1->id]['rank'])->toBe(1);
    expect($log[$u2->id]['rank'])->toBe(2);
    expect($log[$u3->id]['rank'])->toBe(2);
    expect($log[$u4->id]['rank'])->toBe(4); // 3 is skipped
});

it('keeps a separate, independent log per division', function () {
    $prod = Division::create(['slug' => 'production', 'name' => 'Production']);
    $match = prsMatch('N1', 'national', '2026-03-01');

    // Open: 50 (top) & 25 (=50%). Production: 10 (top of its own division = 100%).
    $openTop = User::factory()->create(['name' => 'OpenTop']);
    $openLow = User::factory()->create(['name' => 'OpenLow']);
    $prodOnly = User::factory()->create(['name' => 'ProdOnly']);

    prsScore($match, $openTop, 50.0, $this->open->id);
    prsScore($match, $openLow, 25.0, $this->open->id);
    prsScore($match, $prodOnly, 10.0, $prod->id);

    recalcPrs();
    $service = app(StandingsCalculationService::class);

    $openLog = $service->annualLog('PRS', '2026', $this->open->id)->keyBy('user_id');
    $prodLog = $service->annualLog('PRS', '2026', $prod->id)->keyBy('user_id');

    // Within Open, winner = 100, other = 50.
    expect($openLog[$openTop->id]['total'])->toBe(100.00);
    expect($openLog[$openLow->id]['total'])->toBe(50.00);

    // Production shooter is the winner of their OWN division = 100, despite a
    // low raw score, and never appears in the Open log.
    expect($prodLog[$prodOnly->id]['total'])->toBe(100.00);
    expect($openLog->has($prodOnly->id))->toBeFalse();
    expect($prodLog->has($openTop->id))->toBeFalse();
});

it('ignores provincial PRS matches for the NATIONAL annual log — only nationals and champs count', function () {
    // Note the clarified test title: PRS provincial matches are still ignored
    // by the *national* annual log (regular + champs). They now feed a
    // *separate* provincial standing (see the "PRS provincial" tests below).
    $shooter = User::factory()->create(['name' => 'Prov']);

    // A provincial match with a huge score that must NOT count toward national.
    $prov = prsMatch('Provincial', 'provincial', '2026-02-01');
    $provTop = User::factory()->create();
    prsScore($prov, $provTop, 100.0, $this->open->id);
    prsScore($prov, $shooter, 100.0, $this->open->id);

    // One national worth 60%.
    $nat = prsMatch('National', 'national', '2026-03-01');
    $natTop = User::factory()->create();
    prsScore($nat, $natTop, 100.0, $this->open->id);
    prsScore($nat, $shooter, 60.0, $this->open->id);

    recalcPrs();
    $row = app(StandingsCalculationService::class)->annualLog('PRS', '2026', $this->open->id)
        ->firstWhere('user_id', $shooter->id);

    // Only the national counts toward the annual log: total = 60,
    // provincial 100% is ignored on this side.
    expect($row['total'])->toBe(60.00);
    expect(collect($row['regular'])->pluck('match_id')->all())->toBe([$nat->id]);
});

/*
|--------------------------------------------------------------------------
| PRS provincial standings — sum of best-3 provincial-level scores.
|--------------------------------------------------------------------------
|
| Mirrors the PR22 provincial rule (sum of best-N provincials) so both series
| share one behaviour. Only PRS matches with series_level='provincial' feed
| this — nationals and finals stay on the annual-log side and never leak in.
| Attribution follows the shooter's home province.
*/

it('builds a PRS provincial standing from the shooter\'s best 3 provincial scores', function () {
    $gp = \App\Models\Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);

    $shooter = User::factory()->create(['name' => 'PRS Prov Shooter', 'province_id' => $gp->id]);
    \App\Models\Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'T-PRS-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    // Four PRS provincial matches. The shooter's own scores are 100 / 80 /
    // 60 / 40 (as raw impacts; a matching "top" shooter drops each to a
    // normalized %). Best 3 kept: 100 + 80 + 60 = 240; the 40 is dropped.
    $rawScores = [100, 80, 60, 40];
    $months = ['02', '03', '04', '05'];
    foreach ($rawScores as $i => $raw) {
        $match = prsMatch('PRS Prov '.($i + 1), 'provincial', '2026-'.$months[$i].'-15');
        // A rival at 100 impacts in every match so the shooter's normalized
        // % equals their raw score. Rival is a fresh user each match so no
        // one else builds a big provincial total in this province.
        $rival = User::factory()->create();
        prsScore($match, $rival, 100.0, $this->open->id);
        prsScore($match, $shooter, (float) $raw, $this->open->id);
    }

    recalcPrs();

    $standing = \App\Models\Standing::where('user_id', $shooter->id)
        ->where('series', 'PRS')->where('season', '2026')
        ->where('province_id', $gp->id)
        ->whereNull('division_id')
        ->first();

    expect($standing)->not->toBeNull()
        ->and(round((float) $standing->points, 2))->toBe(240.00);

    // pool_breakdown records the four attempts, with the lowest marked
    // as dropped.
    $matches = collect($standing->pool_breakdown['matches'] ?? [])->sortByDesc('pct')->values();
    expect($matches)->toHaveCount(4);
    expect((bool) $matches[0]['counted'])->toBeTrue()
        ->and((bool) $matches[1]['counted'])->toBeTrue()
        ->and((bool) $matches[2]['counted'])->toBeTrue()
        ->and((bool) $matches[3]['counted'])->toBeFalse();
});

it('never leaks a PRS national match into the PRS provincial standing', function () {
    $gp = \App\Models\Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);

    $shooter = User::factory()->create(['name' => 'PRS Mixed', 'province_id' => $gp->id]);
    \App\Models\Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'T-PRS-2', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    // One PRS national with a huge score.
    $nat = prsMatch('PRS Nat Only', 'national', '2026-02-15');
    $rival = User::factory()->create();
    prsScore($nat, $rival, 100.0, $this->open->id);
    prsScore($nat, $shooter, 100.0, $this->open->id);

    recalcPrs();

    // No provincial row should exist — national never feeds provincial.
    $provincial = \App\Models\Standing::where('user_id', $shooter->id)
        ->where('series', 'PRS')->where('season', '2026')
        ->where('province_id', $gp->id)
        ->whereNull('division_id')
        ->first();

    expect($provincial)->toBeNull();
});
