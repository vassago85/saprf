<?php

use App\Models\Club;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionRuleEvaluation;
use App\Models\User;
use App\Services\Selection\EligibilityEvaluator;

beforeEach(fn () => seedRoles());

function makeElgCycle(): SelectionCycle
{
    return SelectionCycle::create([
        'series' => 'PRS',
        'season' => '2026',
        'championship_name' => 'IPRF WCH 2026 (Centrefire)',
        'qualifying_period_start' => '2024-11-15',
        'qualifying_period_end' => '2025-11-30',
        'declaration_deadline' => '2025-09-30 23:59:00',
        'results_freeze' => '2026-03-01',
        'status' => 'draft',
        'evaluation_mode' => SelectionCycle::MODE_STRICT,
    ]);
}

function makeElgAthlete(User $user, SelectionCycle $cycle): SelectionAthlete
{
    return SelectionAthlete::create([
        'selection_cycle_id' => $cycle->id,
        'user_id' => $user->id,
        'state' => SelectionAthlete::STATE_REGISTERED,
    ]);
}

function paidMembership(User $user, string $saprfNumber): void
{
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => $saprfNumber,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2024-01-01',
        'expiry_date' => '2026-12-31',
    ]);
}

it('passes ELG-01..05 for a fully-configured resident SA-citizen athlete', function () {
    $province = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $club = Club::create(['name' => 'Test', 'slug' => 'test', 'province_id' => $province->id, 'saprf_recognised' => true]);
    $user = User::factory()->create([
        'province_id' => $province->id,
        'club_id' => $club->id,
        'sa_citizen' => true,
        'country_of_residence' => 'ZA',
    ]);
    paidMembership($user, 'M1');

    $out = app(EligibilityEvaluator::class)->evaluate(makeElgAthlete($user, makeElgCycle()));

    expect($out['ELG-01']['outcome'])->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect($out['ELG-02']['outcome'])->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect($out['ELG-03']['outcome'])->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect($out['ELG-04']['outcome'])->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect($out['ELG-05']['outcome'])->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
});

it('fails ELG-01 when the athletes membership is free-tier / not paid on cycle start', function () {
    $user = User::factory()->create(['sa_citizen' => true, 'country_of_residence' => 'ZA']);
    Membership::create([
        'user_id' => $user->id, 'saprf_number' => 'M2', 'membership_type' => 'free',
        'status' => 'active', 'payment_status' => 'paid', 'start_date' => '2024-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $out = app(EligibilityEvaluator::class)->evaluate(makeElgAthlete($user, makeElgCycle()));

    expect($out['ELG-01']['outcome'])->toBe(SelectionRuleEvaluation::OUTCOME_FAIL);
});

it('records ELG-02 MANUAL when citizenship flag is unknown', function () {
    $user = User::factory()->create(['sa_citizen' => null, 'country_of_residence' => 'ZA']);
    paidMembership($user, 'M3');

    $out = app(EligibilityEvaluator::class)->evaluate(makeElgAthlete($user, makeElgCycle()));

    expect($out['ELG-02']['outcome'])->toBe(SelectionRuleEvaluation::OUTCOME_MANUAL);
});

it('fails ELG-03 when the athletes home province does not match their clubs province', function () {
    $home = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $away = Province::create(['name' => 'WC', 'abbreviation' => 'WC']);
    $club = Club::create(['name' => 'Coastal', 'slug' => 'coastal', 'province_id' => $away->id, 'saprf_recognised' => true]);
    $user = User::factory()->create([
        'province_id' => $home->id, 'club_id' => $club->id,
        'sa_citizen' => true, 'country_of_residence' => 'ZA',
    ]);
    paidMembership($user, 'M4');

    $out = app(EligibilityEvaluator::class)->evaluate(makeElgAthlete($user, makeElgCycle()));

    expect($out['ELG-03']['outcome'])->toBe(SelectionRuleEvaluation::OUTCOME_FAIL);
});

it('fails ELG-04 for a non-resident non-citizen', function () {
    $user = User::factory()->create(['sa_citizen' => false, 'country_of_residence' => 'GB']);
    paidMembership($user, 'M5');

    $out = app(EligibilityEvaluator::class)->evaluate(makeElgAthlete($user, makeElgCycle()));

    expect($out['ELG-04']['outcome'])->toBe(SelectionRuleEvaluation::OUTCOME_FAIL);
    expect($out['ELG-04']['detail']['reason'])->toBe('non_resident_non_citizen');
});

it('passes ELG-04 via the built-in exception for a non-resident SA citizen who shot SA Champs', function () {
    $prov = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $user = User::factory()->create(['province_id' => $prov->id, 'sa_citizen' => true, 'country_of_residence' => 'GB']);
    paidMembership($user, 'M6');
    $cycle = makeElgCycle();

    $match = MatchEvent::create([
        'name' => 'SA Champs', 'match_type' => 'PRS', 'series' => 'PRS', 'season' => '2025',
        'series_level' => 'final', 'province_id' => $prov->id,
        'match_date' => '2025-10-01', 'status' => 'completed', 'created_by' => $user->id,
        'active_member_fee' => 500, 'published' => true,
    ]);
    Score::create([
        'match_id' => $match->id, 'shooter_name' => $user->name, 'user_id' => $user->id,
        'raw_score' => 80, 'status' => 'valid', 'match_date' => '2025-10-01', 'counts_for_season' => true,
    ]);

    $out = app(EligibilityEvaluator::class)->evaluate(makeElgAthlete($user, $cycle));

    expect($out['ELG-04']['outcome'])->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect($out['ELG-04']['detail']['exception_applied'])->toBeTrue();
});

it('fails ELG-05 when the athletes club is not SAPRF-recognised', function () {
    $prov = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $club = Club::create(['name' => 'Rogue', 'slug' => 'rogue', 'province_id' => $prov->id, 'saprf_recognised' => false]);
    $user = User::factory()->create([
        'province_id' => $prov->id, 'club_id' => $club->id,
        'sa_citizen' => true, 'country_of_residence' => 'ZA',
    ]);
    paidMembership($user, 'M7');

    $out = app(EligibilityEvaluator::class)->evaluate(makeElgAthlete($user, makeElgCycle()));

    expect($out['ELG-05']['outcome'])->toBe(SelectionRuleEvaluation::OUTCOME_FAIL);
});

it('records ELG-06 MANUAL when no declaration is on file', function () {
    $user = User::factory()->create(['sa_citizen' => true, 'country_of_residence' => 'ZA']);
    paidMembership($user, 'M8');

    $out = app(EligibilityEvaluator::class)->evaluate(makeElgAthlete($user, makeElgCycle()));

    expect($out['ELG-06']['outcome'])->toBe(SelectionRuleEvaluation::OUTCOME_MANUAL);
});

it('persists exactly 6 evaluations to the audit table (one per v1.4 ELG rule)', function () {
    $user = User::factory()->create(['sa_citizen' => true, 'country_of_residence' => 'ZA']);
    paidMembership($user, 'M9');

    $athlete = makeElgAthlete($user, makeElgCycle());
    app(EligibilityEvaluator::class)->evaluate($athlete);

    expect(SelectionRuleEvaluation::where('selection_athlete_id', $athlete->id)->count())->toBe(6);
});
