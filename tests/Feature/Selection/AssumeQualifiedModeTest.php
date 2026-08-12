<?php

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
