<?php

use App\Models\Club;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionRuleEvaluation;
use App\Models\User;
use App\Services\Selection\EligibilityEvaluator;
use App\Services\Selection\PolicyImportService;

beforeEach(fn () => seedRoles());

/**
 * PR22 v1.1 policy (2027 IPRF WCH cycle) — dispatched via RulesetResolver.
 * These tests focus on the differences from v1.4:
 *   - Only 5 ELG rules (not 6).
 *   - ELG-03 is OR (province-affiliated OR SAPRF-recognised-club).
 *   - ELG-04 exception routes through the 2026 SA Champs (not 2025).
 */
function makeV11Cycle(): SelectionCycle
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

function makeV11Province(string $abbr = 'GP'): Province
{
    return Province::firstOrCreate(['abbreviation' => $abbr], ['name' => $abbr]);
}

function makeV11Club(int $provinceId, bool $recognised = true): Club
{
    return Club::create([
        'name' => 'V11 Club '.uniqid(),
        'slug' => 'v11-club-'.uniqid(),
        'province_id' => $provinceId,
        'saprf_recognised' => $recognised,
    ]);
}

function makeV11Athlete(SelectionCycle $cycle, array $userOverrides = [], ?Club $club = null): SelectionAthlete
{
    $province = makeV11Province();
    $club = $club ?? makeV11Club($province->id, true);

    $user = User::factory()->create(array_merge([
        'province_id' => $province->id,
        'club_id' => $club->id,
        'sa_citizen' => true,
        'country_of_residence' => 'ZA',
    ], $userOverrides));

    return SelectionAthlete::create([
        'selection_cycle_id' => $cycle->id,
        'user_id' => $user->id,
        'state' => SelectionAthlete::STATE_REGISTERED,
    ]);
}

function latestV11Rule(SelectionAthlete $athlete, string $ruleId): ?SelectionRuleEvaluation
{
    return SelectionRuleEvaluation::query()
        ->where('selection_athlete_id', $athlete->id)
        ->where('rule_id', $ruleId)
        ->orderByDesc('id')
        ->first();
}

it('emits exactly 5 ELG rows (v1.1 has no separate club-recognition rule)', function () {
    $cycle = makeV11Cycle();
    $athlete = makeV11Athlete($cycle);

    app(EligibilityEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy', 'user.club', 'user.membership']));

    $rules = SelectionRuleEvaluation::query()
        ->where('selection_athlete_id', $athlete->id)
        ->pluck('rule_id')
        ->sort()
        ->values()
        ->all();

    expect($rules)->toEqual(['ELG-01', 'ELG-02', 'ELG-03', 'ELG-04', 'ELG-05']);
});

it('passes ELG-03 when a shooter is out-of-province but belongs to a SAPRF-recognised club', function () {
    $cycle = makeV11Cycle();
    $province = makeV11Province('GP');
    $otherProvince = makeV11Province('WC');

    $club = makeV11Club($otherProvince->id, true);

    $athlete = makeV11Athlete($cycle, [
        'province_id' => $province->id,
    ], $club);

    app(EligibilityEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy', 'user.club', 'user.membership']));

    $elg03 = latestV11Rule($athlete, 'ELG-03');
    expect($elg03->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect($elg03->detail['branch_province_pass'])->toBeFalse();
    expect($elg03->detail['branch_club_pass'])->toBeTrue();
});

it('fails ELG-03 when neither branch passes (out-of-province AND unrecognised club)', function () {
    $cycle = makeV11Cycle();
    $province = makeV11Province('GP');
    $otherProvince = makeV11Province('WC');

    $club = makeV11Club($otherProvince->id, false);

    $athlete = makeV11Athlete($cycle, [
        'province_id' => $province->id,
    ], $club);

    app(EligibilityEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy', 'user.club', 'user.membership']));

    $elg03 = latestV11Rule($athlete, 'ELG-03');
    expect($elg03->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_FAIL);
});

it('passes ELG-04 for a non-resident SA citizen who shot the 2026 SA Champs', function () {
    $cycle = makeV11Cycle();
    $athlete = makeV11Athlete($cycle, [
        'country_of_residence' => 'GB',
        'sa_citizen' => true,
    ]);

    $province = makeV11Province('GP');
    $championship = MatchEvent::create([
        'name' => 'SAPRF PR22 SA Championships 2026',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'season' => '2026',
        'series_level' => 'final',
        'province_id' => $province->id,
        'match_date' => '2026-10-15',
        'status' => 'completed',
        'created_by' => $athlete->user_id,
        'active_member_fee' => 500,
        'published' => true,
    ]);
    Score::create([
        'match_id' => $championship->id,
        'user_id' => $athlete->user_id,
        'shooter_name' => $athlete->user->name,
        'raw_score' => 50,
        'status' => 'valid',
        'match_date' => $championship->match_date,
        'counts_for_season' => true,
    ]);

    app(EligibilityEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy', 'user.club', 'user.membership']));

    $elg04 = latestV11Rule($athlete, 'ELG-04');
    expect($elg04->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect($elg04->detail['exception_applied'])->toBeTrue();
});
