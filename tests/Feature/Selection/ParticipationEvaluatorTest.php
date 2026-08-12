<?php

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionRuleEvaluation;
use App\Models\User;
use App\Services\Selection\ParticipationEvaluator;
use App\Services\Selection\PolicyImportService;

beforeEach(fn () => seedRoles());

/**
 * Every test in this file exercises the PRS v1.4 policy imported from
 * docs/selection/prs/2026/policy.json. The evaluator reads its thresholds
 * from that policy, so we import it into each cycle to keep tests honest
 * to what production actually sees. Cycles are created in 'strict' mode so
 * the real ruleset runs rather than the auto-pass short-circuit.
 */
function makeV14Cycle(): SelectionCycle
{
    $cycle = SelectionCycle::create([
        'series' => 'PRS',
        'season' => '2026',
        'championship_name' => 'IPRF World Championships 2026 (Centrefire)',
        'qualifying_period_start' => '2024-11-15',
        'qualifying_period_end' => '2025-11-30',
        'declaration_deadline' => '2025-09-30 23:59:00',
        'results_freeze' => '2026-03-01',
        'status' => 'draft',
        'evaluation_mode' => SelectionCycle::MODE_STRICT,
    ]);

    app(PolicyImportService::class)->import(
        base_path('docs/selection/prs/2026/policy.json'),
        $cycle,
    );

    return $cycle->fresh();
}

function makePartScore(MatchEvent $match, User $user): Score
{
    return Score::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'raw_score' => 50,
        'status' => 'valid',
        'match_date' => $match->match_date,
        'counts_for_season' => true,
    ]);
}

function makePartMatch(string $level, int $provinceId, int $creatorId, string $date, string $season = '2025'): MatchEvent
{
    return MatchEvent::create([
        'name' => "Match {$level} {$date}",
        'match_type' => 'PRS',
        'series' => 'PRS',
        'season' => $season,
        'series_level' => $level,
        'province_id' => $provinceId,
        'match_date' => $date,
        'status' => 'completed',
        'created_by' => $creatorId,
        'active_member_fee' => 500,
        'published' => true,
    ]);
}

function latestRule(SelectionAthlete $athlete, string $ruleId): ?SelectionRuleEvaluation
{
    return SelectionRuleEvaluation::query()
        ->where('selection_athlete_id', $athlete->id)
        ->where('rule_id', $ruleId)
        ->orderByDesc('id')
        ->first();
}

it('PART-01 fails when SA Champs 2025 was not shot', function () {
    $prov = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $user = User::factory()->create(['province_id' => $prov->id]);
    $cycle = makeV14Cycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    makePartScore(makePartMatch('national', $prov->id, $user->id, '2025-03-01'), $user);

    app(ParticipationEvaluator::class)->evaluate($athlete);

    expect(latestRule($athlete, 'PART-01')?->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_FAIL);
});

it('PART-01 passes when SA Champs (series_level=final) is shot', function () {
    $prov = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $user = User::factory()->create(['province_id' => $prov->id]);
    $cycle = makeV14Cycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    makePartScore(makePartMatch('final', $prov->id, $user->id, '2025-11-15'), $user);

    app(ParticipationEvaluator::class)->evaluate($athlete);

    expect(latestRule($athlete, 'PART-01')?->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
});

it('PART-02 counts SA Champs + capped nationals + all internationals toward the 4 minimum', function () {
    $home = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $away = Province::create(['name' => 'WC', 'abbreviation' => 'WC']);
    $user = User::factory()->create(['province_id' => $home->id]);
    $cycle = makeV14Cycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    makePartScore(makePartMatch('final', $home->id, $user->id, '2025-11-01'), $user);
    makePartScore(makePartMatch('national', $home->id, $user->id, '2025-02-01'), $user);
    makePartScore(makePartMatch('national', $away->id, $user->id, '2025-04-01'), $user);
    makePartScore(makePartMatch('international', $away->id, $user->id, '2025-08-01'), $user);

    $snap = app(ParticipationEvaluator::class)->evaluate($athlete);

    expect($snap->sa_champs_shot)->toBeTrue();
    expect($snap->national_2d_count)->toBe(2);
    expect($snap->international_2d_count)->toBe(1);

    $part02 = latestRule($athlete, 'PART-02');
    expect($part02->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect($part02->detail['counted'])->toBe(4);
    expect($part02->detail['minimum'])->toBe(4);
});

it('PART-02 fails when counted total is below 4', function () {
    $home = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $user = User::factory()->create(['province_id' => $home->id]);
    $cycle = makeV14Cycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    makePartScore(makePartMatch('final', $home->id, $user->id, '2025-11-01'), $user);
    makePartScore(makePartMatch('national', $home->id, $user->id, '2025-04-01'), $user);

    app(ParticipationEvaluator::class)->evaluate($athlete);

    $part02 = latestRule($athlete, 'PART-02');
    expect($part02->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_FAIL);
    expect($part02->detail['counted'])->toBe(2);
});

it('PART-03 fails when no counted matches fall within 3 months of qualifying-period close', function () {
    $home = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $away = Province::create(['name' => 'WC', 'abbreviation' => 'WC']);
    $user = User::factory()->create(['province_id' => $home->id]);
    $cycle = makeV14Cycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    // All matches dated well before the 30 Aug – 30 Nov 2025 window.
    makePartScore(makePartMatch('national', $away->id, $user->id, '2025-01-01'), $user);
    makePartScore(makePartMatch('national', $away->id, $user->id, '2025-02-01'), $user);
    makePartScore(makePartMatch('international', $away->id, $user->id, '2025-03-01'), $user);
    makePartScore(makePartMatch('international', $away->id, $user->id, '2025-04-01'), $user);

    app(ParticipationEvaluator::class)->evaluate($athlete);

    expect(latestRule($athlete, 'PART-03')?->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_FAIL);
});

it('PART-03 passes when at least one counted match falls within the close window', function () {
    $away = Province::create(['name' => 'WC', 'abbreviation' => 'WC']);
    $user = User::factory()->create(['province_id' => Province::create(['name' => 'GP', 'abbreviation' => 'GP'])->id]);
    $cycle = makeV14Cycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    makePartScore(makePartMatch('international', $away->id, $user->id, '2025-10-05'), $user);

    app(ParticipationEvaluator::class)->evaluate($athlete);

    $part03 = latestRule($athlete, 'PART-03');
    expect($part03->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect($part03->detail['within_close_window'])->toBe(1);
});

it('PART-04 discards in-province nationals beyond the cap of 1 (SA Champs exempt)', function () {
    $home = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $user = User::factory()->create(['province_id' => $home->id]);
    $cycle = makeV14Cycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    // SA Champs in home province + 3 additional in-province nationals: only
    // 1 of the 3 additional nationals should count against the cap.
    makePartScore(makePartMatch('final', $home->id, $user->id, '2025-11-01'), $user);
    makePartScore(makePartMatch('national', $home->id, $user->id, '2025-02-01'), $user);
    makePartScore(makePartMatch('national', $home->id, $user->id, '2025-05-01'), $user);
    makePartScore(makePartMatch('national', $home->id, $user->id, '2025-08-01'), $user);

    $snap = app(ParticipationEvaluator::class)->evaluate($athlete);

    $part04 = latestRule($athlete, 'PART-04');
    expect($part04->detail['in_province_shot'])->toBe(3);
    expect($part04->detail['counted'])->toBe(1);
    expect($part04->detail['discarded_by_cap'])->toBe(2);

    // Counted total: SA Champs + 1 capped in-province = 2. Well short of 4.
    expect(latestRule($athlete, 'PART-02')?->detail['counted'])->toBe(2);
});

it('PART-05 discards out-of-province nationals beyond the cap of 3', function () {
    $home = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $away = Province::create(['name' => 'WC', 'abbreviation' => 'WC']);
    $user = User::factory()->create(['province_id' => $home->id]);
    $cycle = makeV14Cycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    makePartScore(makePartMatch('final', $home->id, $user->id, '2025-11-01'), $user);
    foreach (['2025-02-01', '2025-04-01', '2025-06-01', '2025-08-01', '2025-10-01'] as $d) {
        makePartScore(makePartMatch('national', $away->id, $user->id, $d), $user);
    }

    app(ParticipationEvaluator::class)->evaluate($athlete);

    $part05 = latestRule($athlete, 'PART-05');
    expect($part05->detail['out_of_province_shot'])->toBe(5);
    expect($part05->detail['counted'])->toBe(3);
    expect($part05->detail['discarded_by_cap'])->toBe(2);

    // SA Champs + 3 capped out-of-province = 4 → PART-02 pass.
    expect(latestRule($athlete, 'PART-02')?->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
});

it('PART-06 is BLOCKED until matches carry a sanctioning_body field', function () {
    $prov = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $user = User::factory()->create(['province_id' => $prov->id]);
    $cycle = makeV14Cycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    app(ParticipationEvaluator::class)->evaluate($athlete);

    expect(latestRule($athlete, 'PART-06')?->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_BLOCKED);
});

it('SA Champs shot in the athletes home province does not consume the in-province cap', function () {
    $home = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $user = User::factory()->create(['province_id' => $home->id]);
    $cycle = makeV14Cycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    // SA Champs (home province) + 1 additional in-province national + 2 out-of-province
    $away = Province::create(['name' => 'WC', 'abbreviation' => 'WC']);
    makePartScore(makePartMatch('final', $home->id, $user->id, '2025-11-01'), $user);
    makePartScore(makePartMatch('national', $home->id, $user->id, '2025-05-01'), $user);
    makePartScore(makePartMatch('national', $away->id, $user->id, '2025-06-01'), $user);
    makePartScore(makePartMatch('national', $away->id, $user->id, '2025-07-01'), $user);

    $snap = app(ParticipationEvaluator::class)->evaluate($athlete);

    // In-province cap is 1; the 1 additional in-province national fits.
    // SA Champs is separate. Total counted = 4.
    $part04 = latestRule($athlete, 'PART-04');
    expect($part04->detail['in_province_shot'])->toBe(1);
    expect($part04->detail['counted'])->toBe(1);
    expect($part04->detail['discarded_by_cap'])->toBe(0);

    expect(latestRule($athlete, 'PART-02')?->detail['counted'])->toBe(4);
});
