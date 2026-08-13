<?php

use App\Models\Club;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionDeclaration;
use App\Models\SelectionParticipationSnapshot;
use App\Models\SelectionRuleEvaluation;
use App\Models\User;
use App\Services\Selection\EligibilityEvaluator;
use App\Services\Selection\ParticipationEvaluator;
use App\Services\Selection\PolicyImportService;
use App\Services\Selection\SelectionAthleteStateService;

beforeEach(fn () => seedRoles());

/**
 * "Assume qualified" cycles run through the AutoPass ruleset short-circuit
 * in RulesetResolver — every ELG-* and PART-* rule declared by the policy
 * gets an OUTCOME_PASS row (with reason=auto_pass_mode), a snapshot with
 * zero counts is written, and the DEC-01 nomination letter becomes the
 * sole gate that decides whether the athlete moves past `eligible`.
 */
function makeAutoPassCycle(): SelectionCycle
{
    $cycle = SelectionCycle::create([
        'series' => 'PRS',
        'season' => '2026',
        'championship_name' => 'IPRF WCH 2026 (Centrefire)',
        'qualifying_period_start' => '2024-11-15',
        'qualifying_period_end' => '2025-11-30',
        'declaration_deadline' => '2025-09-30 23:59:00',
        'results_freeze' => '2026-03-01',
        'status' => 'closed',
        'evaluation_mode' => SelectionCycle::MODE_ASSUME_QUALIFIED,
    ]);
    app(PolicyImportService::class)->import(
        base_path('docs/selection/prs/2026/policy.json'),
        $cycle,
    );

    return $cycle->fresh();
}

it('auto-passes every ELG and PART rule for an athlete with no data', function () {
    $cycle = makeAutoPassCycle();
    $user = User::factory()->create();
    $athlete = SelectionAthlete::create([
        'selection_cycle_id' => $cycle->id,
        'user_id' => $user->id,
        'state' => SelectionAthlete::STATE_REGISTERED,
    ]);

    app(EligibilityEvaluator::class)->evaluate($athlete);
    app(ParticipationEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy']));

    $ruleIds = SelectionRuleEvaluation::query()
        ->where('selection_athlete_id', $athlete->id)
        ->pluck('rule_id')
        ->sort()
        ->values()
        ->all();

    // v1.4 policy declares ELG-01..06 (6) + PART-01..07 (7) = 13 rules.
    expect($ruleIds)->toBe([
        'ELG-01', 'ELG-02', 'ELG-03', 'ELG-04', 'ELG-05', 'ELG-06',
        'PART-01', 'PART-02', 'PART-03', 'PART-04', 'PART-05', 'PART-06', 'PART-07',
    ]);

    $outcomes = SelectionRuleEvaluation::query()
        ->where('selection_athlete_id', $athlete->id)
        ->pluck('outcome')
        ->unique()
        ->values()
        ->all();
    expect($outcomes)->toBe([SelectionRuleEvaluation::OUTCOME_PASS]);

    $snapshot = SelectionParticipationSnapshot::where('selection_athlete_id', $athlete->id)->first();
    expect($snapshot)->not->toBeNull();
    expect($snapshot->national_2d_count)->toBe(0);
});

it('advances to eligible on ELG pass but stays there without the nomination letter', function () {
    $cycle = makeAutoPassCycle();
    $user = User::factory()->create();
    $athlete = SelectionAthlete::create([
        'selection_cycle_id' => $cycle->id,
        'user_id' => $user->id,
        'state' => SelectionAthlete::STATE_REGISTERED,
    ]);

    app(EligibilityEvaluator::class)->evaluate($athlete);
    app(ParticipationEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy']));
    app(SelectionAthleteStateService::class)->recompute($athlete->fresh(['cycle.activePolicy', 'declaration']));

    expect($athlete->fresh()->state)->toBe(SelectionAthlete::STATE_ELIGIBLE);
});

it('advances all the way to squad_qualified once the nomination letter is submitted', function () {
    $cycle = makeAutoPassCycle();
    $user = User::factory()->create();
    $athlete = SelectionAthlete::create([
        'selection_cycle_id' => $cycle->id,
        'user_id' => $user->id,
        'state' => SelectionAthlete::STATE_REGISTERED,
    ]);

    SelectionDeclaration::create([
        'selection_athlete_id' => $athlete->id,
        'submitted_at' => '2025-09-15 10:00:00',
        'status' => SelectionDeclaration::STATUS_SUBMITTED,
        'form_data' => ['eligibility_to_compete_received' => true],
    ]);

    app(EligibilityEvaluator::class)->evaluate($athlete);
    app(ParticipationEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy']));
    app(SelectionAthleteStateService::class)->recompute($athlete->fresh(['cycle.activePolicy', 'declaration']));

    expect($athlete->fresh()->state)->toBe(SelectionAthlete::STATE_SQUAD_QUALIFIED);
});

/**
 * The PR22 v1.1 2027 cycle is running in assume_qualified mode, but admins
 * still want to see the real participation numbers each athlete has racked
 * up (so they know who's actually hit the criteria vs who's coasting on
 * the nomination letter). AutoPassParticipationRuleset borrows the strict
 * PR22 counter's computeSnapshotPayload() to fill in the snapshot with
 * real numbers, while still emitting OUTCOME_PASS for every PART-* rule.
 */
function makeAutoPr22Cycle(): SelectionCycle
{
    $cycle = SelectionCycle::create([
        'series' => 'PR22',
        'season' => '2027',
        'championship_name' => 'IPRF PR22 Team World Championships 2027',
        'qualifying_period_start' => '2026-01-01',
        'qualifying_period_end' => '2026-12-31',
        'declaration_deadline' => '2026-11-30 23:59:00',
        'results_freeze' => '2026-12-31',
        'status' => 'open',
        'evaluation_mode' => SelectionCycle::MODE_ASSUME_QUALIFIED,
    ]);
    app(PolicyImportService::class)->import(
        base_path('docs/selection/pr22/2027/policy.json'),
        $cycle,
    );

    return $cycle->fresh();
}

function makeAutoPr22Province(string $abbr): Province
{
    return Province::firstOrCreate(['abbreviation' => $abbr], ['name' => $abbr]);
}

function makeAutoPr22Match(string $level, int $provinceId, string $date, float $rawScore, int $userId): void
{
    $match = MatchEvent::create([
        'name' => "AutoPass PR22 {$level} {$date}",
        'match_type' => 'PR22',
        'series' => 'PR22',
        'season' => '2026',
        'series_level' => $level,
        'province_id' => $provinceId,
        'match_date' => $date,
        'status' => 'completed',
        'created_by' => $userId,
        'active_member_fee' => 500,
        'published' => true,
    ]);
    Score::create([
        'match_id' => $match->id,
        'user_id' => $userId,
        'shooter_name' => 'auto',
        'raw_score' => $rawScore,
        'normalized_score' => $rawScore,
        'status' => 'valid',
        'match_date' => $match->match_date,
        'counts_for_season' => true,
    ]);
}

it('fills the snapshot with real informational counts even under assume_qualified', function () {
    $cycle = makeAutoPr22Cycle();
    $home = makeAutoPr22Province('GP');
    $away = makeAutoPr22Province('WC');

    $club = Club::create([
        'name' => 'AutoPass PR22 Club '.uniqid(),
        'slug' => 'auto-pr22-club-'.uniqid(),
        'province_id' => $home->id,
        'saprf_recognised' => true,
    ]);
    $user = User::factory()->create([
        'province_id' => $home->id,
        'club_id' => $club->id,
        'sa_citizen' => true,
        'country_of_residence' => 'ZA',
    ]);
    $athlete = SelectionAthlete::create([
        'selection_cycle_id' => $cycle->id,
        'user_id' => $user->id,
        'state' => SelectionAthlete::STATE_REGISTERED,
    ]);

    // Mirrors Kevin Goncalves's real 2026 shape: 6 provincial 1-day, 2
    // national 2-day (one out-of-home), no SA Champs yet.
    makeAutoPr22Match('provincial', $home->id, '2026-01-24', 73, $user->id);
    makeAutoPr22Match('provincial', $away->id, '2026-02-21', 54, $user->id);
    makeAutoPr22Match('national',   $away->id, '2026-02-21', 110, $user->id);
    makeAutoPr22Match('provincial', $home->id, '2026-03-07', 82, $user->id);
    makeAutoPr22Match('provincial', $home->id, '2026-04-11', 96, $user->id);
    makeAutoPr22Match('provincial', $home->id, '2026-05-23', 79, $user->id);
    makeAutoPr22Match('provincial', $home->id, '2026-06-06', 82, $user->id);
    makeAutoPr22Match('national',   $home->id, '2026-06-06', 171, $user->id);

    app(EligibilityEvaluator::class)->evaluate($athlete);
    app(ParticipationEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy', 'user']));

    $snapshot = SelectionParticipationSnapshot::where('selection_athlete_id', $athlete->id)->first();
    expect($snapshot)->not->toBeNull();
    expect($snapshot->provincial_1d_count)->toBe(6);
    expect($snapshot->national_2d_count)->toBe(2);
    expect($snapshot->international_2d_count)->toBe(0);
    expect($snapshot->out_of_home_province_2d_count)->toBe(1);
    expect($snapshot->sa_champs_shot)->toBeFalse();
    expect($snapshot->counted_score_ids)->toHaveCount(8);

    // Every PART rule still auto-passes — the informational count is
    // display-only and must not affect the gate.
    $partOutcomes = SelectionRuleEvaluation::query()
        ->where('selection_athlete_id', $athlete->id)
        ->where('rule_id', 'like', 'PART-%')
        ->pluck('outcome')
        ->unique()
        ->values()
        ->all();
    expect($partOutcomes)->toBe([SelectionRuleEvaluation::OUTCOME_PASS]);
});
