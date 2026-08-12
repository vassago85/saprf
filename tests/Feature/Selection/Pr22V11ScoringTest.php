<?php

use App\Models\Club;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionRuleEvaluation;
use App\Models\User;
use App\Services\Selection\PolicyImportService;
use App\Services\Selection\ScoringEvaluator;

beforeEach(fn () => seedRoles());

/**
 * PR22 v1.1 weighted scoring formula: 30% (3×10% provincial) + 40% (2×20%
 * national/international) + 30% SA Champs = 100% raw. Division-relative %
 * is raw / division_top_raw * 100. Protea threshold sits at 85%.
 */
function makeV11ScoringCycle(): SelectionCycle
{
    $cycle = SelectionCycle::create([
        'series' => 'PR22',
        'season' => '2027',
        'championship_name' => 'IPRF PR22 Team World Championships 2027',
        'qualifying_period_start' => '2026-01-01',
        'qualifying_period_end' => '2026-12-31',
        'declaration_deadline' => '2026-11-30 23:59:00',
        'results_freeze' => '2026-12-31',
        'status' => 'draft',
    ]);
    app(PolicyImportService::class)->import(
        base_path('docs/selection/pr22/2027/policy.json'),
        $cycle,
    );

    return $cycle->fresh();
}

function makeV11ScoringProvince(string $abbr = 'GP'): Province
{
    return Province::firstOrCreate(['abbreviation' => $abbr], ['name' => $abbr]);
}

function makeV11ScoringAthlete(SelectionCycle $cycle, ?Division $division = null): SelectionAthlete
{
    $province = makeV11ScoringProvince();
    $club = Club::create([
        'name' => 'V11 Scr Club '.uniqid(),
        'slug' => 'v11-scr-club-'.uniqid(),
        'province_id' => $province->id,
        'saprf_recognised' => true,
    ]);
    $user = User::factory()->create([
        'province_id' => $province->id,
        'club_id' => $club->id,
        'sa_citizen' => true,
        'country_of_residence' => 'ZA',
    ]);

    return SelectionAthlete::create([
        'selection_cycle_id' => $cycle->id,
        'user_id' => $user->id,
        'claimed_division_id' => $division?->id,
        'state' => SelectionAthlete::STATE_REGISTERED,
    ]);
}

function makeV11ScoringMatch(string $level, string $date, User $user, float $normalized, int $provinceId, int $creatorId): void
{
    $match = MatchEvent::create([
        'name' => "V11scr {$level} {$date}",
        'match_type' => 'PR22',
        'series' => 'PR22',
        'season' => '2026',
        'series_level' => $level,
        'province_id' => $provinceId,
        'match_date' => $date,
        'status' => 'completed',
        'created_by' => $creatorId,
        'active_member_fee' => 500,
        'published' => true,
    ]);
    Score::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'raw_score' => $normalized,
        'normalized_score' => $normalized,
        'status' => 'valid',
        'match_date' => $match->match_date,
        'counts_for_season' => true,
    ]);
}

it('SCR-01 sums components to 100 when the athlete tops every counted match', function () {
    $cycle = makeV11ScoringCycle();
    $athlete = makeV11ScoringAthlete($cycle);
    $user = $athlete->user;
    $province = $user->province_id;

    makeV11ScoringMatch('provincial', '2026-02-01', $user, 100, $province, $user->id);
    makeV11ScoringMatch('provincial', '2026-03-01', $user, 100, $province, $user->id);
    makeV11ScoringMatch('provincial', '2026-04-01', $user, 100, $province, $user->id);
    makeV11ScoringMatch('national', '2026-05-01', $user, 100, $province, $user->id);
    makeV11ScoringMatch('international', '2026-06-01', $user, 100, $province, $user->id);
    makeV11ScoringMatch('final', '2026-11-15', $user, 100, $province, $user->id);

    app(ScoringEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy']));

    $scr01 = SelectionRuleEvaluation::where('selection_athlete_id', $athlete->id)
        ->where('rule_id', 'SCR-01')
        ->orderByDesc('id')
        ->first();

    expect($scr01->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect((float) $scr01->detail['raw_weighted_pct'])->toEqual(100.0);
    expect((float) $scr01->detail['components']['provincial_1d']['contribution_pct'])->toEqual(30.0);
    expect((float) $scr01->detail['components']['national_or_international_2d']['contribution_pct'])->toEqual(40.0);
    expect((float) $scr01->detail['components']['sa_champs']['contribution_pct'])->toEqual(30.0);
});

it('SCR-01 respects the 30/40/30 weights and takes only top 3 provincials + top 2 national/intl', function () {
    $cycle = makeV11ScoringCycle();
    $athlete = makeV11ScoringAthlete($cycle);
    $user = $athlete->user;
    $province = $user->province_id;

    makeV11ScoringMatch('provincial', '2026-02-01', $user, 100, $province, $user->id);
    makeV11ScoringMatch('provincial', '2026-03-01', $user, 90, $province, $user->id);
    makeV11ScoringMatch('provincial', '2026-04-01', $user, 80, $province, $user->id);
    makeV11ScoringMatch('provincial', '2026-04-15', $user, 50, $province, $user->id);
    makeV11ScoringMatch('national', '2026-05-01', $user, 100, $province, $user->id);
    makeV11ScoringMatch('national', '2026-05-15', $user, 60, $province, $user->id);
    makeV11ScoringMatch('final', '2026-11-15', $user, 100, $province, $user->id);

    app(ScoringEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy']));

    $scr01 = SelectionRuleEvaluation::where('selection_athlete_id', $athlete->id)
        ->where('rule_id', 'SCR-01')
        ->orderByDesc('id')
        ->first();

    // Provincial: (100*0.10) + (90*0.10) + (80*0.10) = 27.0
    // National:   (100*0.20) + (60*0.20)             = 32.0
    // SA Champs:  (100*0.30)                          = 30.0
    // Total:                                            89.0
    expect((float) $scr01->detail['components']['provincial_1d']['contribution_pct'])->toEqual(27.0);
    expect((float) $scr01->detail['components']['national_or_international_2d']['contribution_pct'])->toEqual(32.0);
    expect((float) $scr01->detail['components']['sa_champs']['contribution_pct'])->toEqual(30.0);
    expect((float) $scr01->detail['raw_weighted_pct'])->toEqual(89.0);
});

it('finalizeCycle rescales against division top and flags Protea eligibility at 85%', function () {
    $cycle = makeV11ScoringCycle();
    $division = Division::create([
        'slug' => 'test-open-'.uniqid(),
        'name' => 'Test Open',
        'display_order' => 1,
        'is_active' => true,
    ]);

    $topShooter = makeV11ScoringAthlete($cycle, $division);
    $borderline = makeV11ScoringAthlete($cycle, $division);
    $belowLine = makeV11ScoringAthlete($cycle, $division);

    // Top shooter: full 100.0 raw weighted
    foreach ([$topShooter] as $a) {
        $u = $a->user;
        $p = $u->province_id;
        makeV11ScoringMatch('provincial', '2026-02-01', $u, 100, $p, $u->id);
        makeV11ScoringMatch('provincial', '2026-03-01', $u, 100, $p, $u->id);
        makeV11ScoringMatch('provincial', '2026-04-01', $u, 100, $p, $u->id);
        makeV11ScoringMatch('national', '2026-05-01', $u, 100, $p, $u->id);
        makeV11ScoringMatch('national', '2026-06-01', $u, 100, $p, $u->id);
        makeV11ScoringMatch('final', '2026-11-15', $u, 100, $p, $u->id);
    }
    // Borderline: 85.0 raw weighted (exactly at threshold when normalized against 100 top)
    $u = $borderline->user;
    $p = $u->province_id;
    makeV11ScoringMatch('provincial', '2026-02-02', $u, 90, $p, $u->id);
    makeV11ScoringMatch('provincial', '2026-03-02', $u, 90, $p, $u->id);
    makeV11ScoringMatch('provincial', '2026-04-02', $u, 90, $p, $u->id);
    makeV11ScoringMatch('national', '2026-05-02', $u, 80, $p, $u->id);
    makeV11ScoringMatch('national', '2026-06-02', $u, 80, $p, $u->id);
    makeV11ScoringMatch('final', '2026-11-16', $u, 90, $p, $u->id);
    // Below line: 70.0 raw weighted
    $u = $belowLine->user;
    $p = $u->province_id;
    makeV11ScoringMatch('provincial', '2026-02-03', $u, 70, $p, $u->id);
    makeV11ScoringMatch('provincial', '2026-03-03', $u, 70, $p, $u->id);
    makeV11ScoringMatch('provincial', '2026-04-03', $u, 70, $p, $u->id);
    makeV11ScoringMatch('national', '2026-05-03', $u, 70, $p, $u->id);
    makeV11ScoringMatch('national', '2026-06-03', $u, 70, $p, $u->id);
    makeV11ScoringMatch('final', '2026-11-17', $u, 70, $p, $u->id);

    foreach ([$topShooter, $borderline, $belowLine] as $a) {
        app(ScoringEvaluator::class)->evaluate($a->fresh(['cycle.activePolicy']));
    }
    app(ScoringEvaluator::class)->finalizeCycle($cycle->fresh(['activePolicy']));

    $scr03Top = SelectionRuleEvaluation::where('selection_athlete_id', $topShooter->id)
        ->where('rule_id', 'SCR-03')
        ->orderByDesc('id')->first();
    $scr03Border = SelectionRuleEvaluation::where('selection_athlete_id', $borderline->id)
        ->where('rule_id', 'SCR-03')
        ->orderByDesc('id')->first();
    $scr03Below = SelectionRuleEvaluation::where('selection_athlete_id', $belowLine->id)
        ->where('rule_id', 'SCR-03')
        ->orderByDesc('id')->first();

    expect($scr03Top->detail['colour_eligibility'])->toBe('protea');
    expect($scr03Border->detail['colour_eligibility'])->toBe('protea');
    expect($scr03Below->detail['colour_eligibility'])->toBe('federation');
    expect($scr03Below->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_FAIL);
});
