<?php

use App\Models\Club;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionDeclaration;
use App\Models\User;
use App\Services\Selection\EligibilityEvaluator;
use App\Services\Selection\ParticipationEvaluator;
use App\Services\Selection\PolicyImportService;
use App\Services\Selection\SelectionAthleteStateService;

beforeEach(fn () => seedRoles());

function fullyEligibleUserForState(int $provinceId, int $clubId): User
{
    $user = User::factory()->create([
        'province_id' => $provinceId,
        'club_id' => $clubId,
        'sa_citizen' => true,
        'country_of_residence' => 'ZA',
    ]);
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'MX'.$user->id,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2024-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    return $user;
}

function makeStateCycle(): SelectionCycle
{
    $cycle = SelectionCycle::create([
        'series' => 'PR22', 'season' => '2026', 'championship_name' => 'IPRF PR22 WCH',
        'qualifying_period_start' => '2024-11-15', 'qualifying_period_end' => '2025-11-30',
        'declaration_deadline' => '2025-09-30 23:59:00', 'results_freeze' => '2026-03-01',
        'status' => 'open',
    ]);

    app(PolicyImportService::class)->import(
        base_path('docs/selection/pr22/2026/policy.json'),
        $cycle,
    );

    return $cycle->fresh();
}

it('stays registered when an ELG rule fails', function () {
    $user = User::factory()->create(['sa_citizen' => false, 'country_of_residence' => 'ZA']);
    Membership::create(['user_id' => $user->id, 'saprf_number' => 'S1', 'membership_type' => 'paid', 'status' => 'active', 'payment_status' => 'paid', 'start_date' => '2024-01-01', 'expiry_date' => '2026-12-31']);
    $cycle = makeStateCycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    app(EligibilityEvaluator::class)->evaluate($athlete);
    app(SelectionAthleteStateService::class)->recompute($athlete->fresh(['cycle.activePolicy', 'declaration']));

    expect($athlete->fresh()->state)->toBe(SelectionAthlete::STATE_REGISTERED);
});

it('advances to eligible when all 6 ELG rules pass but no declaration is on file', function () {
    $prov = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $club = Club::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'province_id' => $prov->id, 'saprf_recognised' => true]);
    $user = fullyEligibleUserForState($prov->id, $club->id);
    $cycle = makeStateCycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    // Attach a placeholder declaration with the eligibility-form flag set,
    // so ELG-06 reports PASS. State should still be ELIGIBLE (not DECLARED)
    // because the declaration itself is not yet in the submitted state.
    SelectionDeclaration::create([
        'selection_athlete_id' => $athlete->id,
        'status' => SelectionDeclaration::STATUS_DRAFT,
        'form_data' => ['eligibility_to_compete_received' => true],
    ]);

    app(EligibilityEvaluator::class)->evaluate($athlete);
    app(SelectionAthleteStateService::class)->recompute($athlete->fresh(['cycle.activePolicy', 'declaration']));

    expect($athlete->fresh()->state)->toBe(SelectionAthlete::STATE_ELIGIBLE);
});

it('advances to declared when a submitted declaration exists before the deadline', function () {
    $prov = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $club = Club::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'province_id' => $prov->id, 'saprf_recognised' => true]);
    $user = fullyEligibleUserForState($prov->id, $club->id);
    $cycle = makeStateCycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    SelectionDeclaration::create([
        'selection_athlete_id' => $athlete->id,
        'submitted_at' => '2025-09-15 10:00:00',
        'status' => SelectionDeclaration::STATUS_SUBMITTED,
        'form_data' => ['eligibility_to_compete_received' => true],
    ]);

    app(EligibilityEvaluator::class)->evaluate($athlete);
    app(SelectionAthleteStateService::class)->recompute($athlete->fresh(['cycle.activePolicy', 'declaration']));

    expect($athlete->fresh()->state)->toBe(SelectionAthlete::STATE_DECLARED);
});

it('advances to squad_qualified when every ELG passes, declaration is submitted, and v1.4 PART rules pass', function () {
    $prov = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $other = Province::create(['name' => 'WC', 'abbreviation' => 'WC']);
    $club = Club::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'province_id' => $prov->id, 'saprf_recognised' => true]);
    $user = fullyEligibleUserForState($prov->id, $club->id);
    $cycle = makeStateCycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    SelectionDeclaration::create([
        'selection_athlete_id' => $athlete->id,
        'submitted_at' => '2025-09-15 10:00:00',
        'status' => SelectionDeclaration::STATUS_SUBMITTED,
        'form_data' => ['eligibility_to_compete_received' => true],
    ]);

    // SA Champs 2025 + 1 in-province + 2 out-of-province nationals = 4 counted.
    // 1 (the SA Champs on 2025-11-01) falls inside the 3-month close window.
    $inputs = [
        ['final',    $prov->id,  '2025-11-01'],
        ['national', $prov->id,  '2025-04-01'],
        ['national', $other->id, '2025-06-01'],
        ['national', $other->id, '2025-08-01'],
    ];
    foreach ($inputs as [$level, $pid, $date]) {
        $m = MatchEvent::create([
            'name' => "$level $date", 'match_type' => 'PR22', 'series' => 'PR22', 'season' => '2025',
            'series_level' => $level, 'province_id' => $pid, 'match_date' => $date,
            'status' => 'completed', 'created_by' => $user->id, 'active_member_fee' => 500, 'published' => true,
        ]);
        Score::create([
            'match_id' => $m->id, 'user_id' => $user->id, 'shooter_name' => $user->name,
            'raw_score' => 50, 'status' => 'valid', 'match_date' => $date, 'counts_for_season' => true,
        ]);
    }

    app(EligibilityEvaluator::class)->evaluate($athlete);
    app(ParticipationEvaluator::class)->evaluate($athlete->fresh(['user.membership', 'user.club', 'cycle.activePolicy', 'declaration']));
    app(SelectionAthleteStateService::class)->recompute($athlete->fresh(['cycle.activePolicy', 'declaration']));

    expect($athlete->fresh()->state)->toBe(SelectionAthlete::STATE_SQUAD_QUALIFIED);
});

it('stays declared when PART rules fail and no waiver is granted', function () {
    $prov = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $club = Club::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'province_id' => $prov->id, 'saprf_recognised' => true]);
    $user = fullyEligibleUserForState($prov->id, $club->id);
    $cycle = makeStateCycle();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $user->id, 'state' => 'registered']);

    SelectionDeclaration::create([
        'selection_athlete_id' => $athlete->id,
        'submitted_at' => '2025-09-15 10:00:00',
        'status' => SelectionDeclaration::STATUS_SUBMITTED,
        'form_data' => ['eligibility_to_compete_received' => true],
    ]);

    // No scores at all → PART-01 and PART-02 fail; PART-06 is BLOCKED
    // (fail-open). PART-01 alone is enough to prevent squad qualification.
    app(EligibilityEvaluator::class)->evaluate($athlete);
    app(ParticipationEvaluator::class)->evaluate($athlete->fresh(['user.membership', 'user.club', 'cycle.activePolicy', 'declaration']));
    app(SelectionAthleteStateService::class)->recompute($athlete->fresh(['cycle.activePolicy', 'declaration']));

    expect($athlete->fresh()->state)->toBe(SelectionAthlete::STATE_DECLARED);
});
