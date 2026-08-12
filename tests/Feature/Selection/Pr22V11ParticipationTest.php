<?php

use App\Models\Club;
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
 * PR22 v1.1 participation ruleset (2027 IPRF WCH cycle). The v1.1 model is
 * six discrete counts (not v1.4's capped counting): 3×1D provincial + 2×2D
 * nat/intl (1 out-of-home) + SA Champs + full-member-before-period.
 */
function makeV11PartCycle(): SelectionCycle
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
        'evaluation_mode' => SelectionCycle::MODE_STRICT,
    ]);

    app(PolicyImportService::class)->import(
        base_path('docs/selection/pr22/2027/policy.json'),
        $cycle,
    );

    return $cycle->fresh();
}

function makeV11PartProvince(string $abbr): Province
{
    return Province::firstOrCreate(['abbreviation' => $abbr], ['name' => $abbr]);
}

function makeV11PartAthlete(SelectionCycle $cycle, ?Province $home = null): SelectionAthlete
{
    $home = $home ?? makeV11PartProvince('GP');
    $club = Club::create([
        'name' => 'V11 Part Club '.uniqid(),
        'slug' => 'v11-part-club-'.uniqid(),
        'province_id' => $home->id,
        'saprf_recognised' => true,
    ]);
    $user = User::factory()->create([
        'province_id' => $home->id,
        'club_id' => $club->id,
        'sa_citizen' => true,
        'country_of_residence' => 'ZA',
    ]);

    return SelectionAthlete::create([
        'selection_cycle_id' => $cycle->id,
        'user_id' => $user->id,
        'state' => SelectionAthlete::STATE_REGISTERED,
    ]);
}

function makeV11Match(string $level, int $provinceId, int $creatorId, string $date, float $rawScore, int $userId): void
{
    $match = MatchEvent::create([
        'name' => "V11 {$level} {$date}",
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
        'user_id' => $userId,
        'shooter_name' => 'auto',
        'raw_score' => $rawScore,
        'normalized_score' => $rawScore,
        'status' => 'valid',
        'match_date' => $match->match_date,
        'counts_for_season' => true,
    ]);
}

function latestV11Part(SelectionAthlete $athlete, string $ruleId): ?SelectionRuleEvaluation
{
    return SelectionRuleEvaluation::query()
        ->where('selection_athlete_id', $athlete->id)
        ->where('rule_id', $ruleId)
        ->orderByDesc('id')
        ->first();
}

it('passes PART-02..05 for a shooter with 3 provincials, 2 nationals (1 out-of-home), and SA Champs', function () {
    $cycle = makeV11PartCycle();
    $home = makeV11PartProvince('GP');
    $away = makeV11PartProvince('WC');

    $athlete = makeV11PartAthlete($cycle, $home);
    $userId = $athlete->user_id;

    makeV11Match('provincial', $home->id, $userId, '2026-03-15', 80, $userId);
    makeV11Match('provincial', $home->id, $userId, '2026-05-15', 82, $userId);
    makeV11Match('provincial', $home->id, $userId, '2026-07-15', 78, $userId);
    makeV11Match('national', $home->id, $userId, '2026-04-10', 85, $userId);
    makeV11Match('national', $away->id, $userId, '2026-08-10', 88, $userId);
    makeV11Match('final', $home->id, $userId, '2026-11-10', 90, $userId);

    app(ParticipationEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy', 'user.club', 'user.membership']));

    expect(latestV11Part($athlete, 'PART-01')->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect(latestV11Part($athlete, 'PART-02')->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect(latestV11Part($athlete, 'PART-03')->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect(latestV11Part($athlete, 'PART-04')->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect(latestV11Part($athlete, 'PART-05')->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
});

it('fails PART-02 when fewer than 3 provincial 1-day matches were shot', function () {
    $cycle = makeV11PartCycle();
    $home = makeV11PartProvince('GP');
    $athlete = makeV11PartAthlete($cycle, $home);
    $userId = $athlete->user_id;

    makeV11Match('provincial', $home->id, $userId, '2026-03-15', 80, $userId);
    makeV11Match('provincial', $home->id, $userId, '2026-05-15', 82, $userId);
    makeV11Match('final', $home->id, $userId, '2026-11-10', 90, $userId);

    app(ParticipationEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy', 'user.club', 'user.membership']));

    $part02 = latestV11Part($athlete, 'PART-02');
    expect($part02->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_FAIL);
    expect($part02->detail['provincial_1d_shot'])->toBe(2);
    expect($part02->detail['minimum'])->toBe(3);
});

it('fails PART-04 when both 2-day matches are in the home province', function () {
    $cycle = makeV11PartCycle();
    $home = makeV11PartProvince('GP');
    $athlete = makeV11PartAthlete($cycle, $home);
    $userId = $athlete->user_id;

    makeV11Match('national', $home->id, $userId, '2026-04-10', 85, $userId);
    makeV11Match('national', $home->id, $userId, '2026-08-10', 88, $userId);
    makeV11Match('final', $home->id, $userId, '2026-11-10', 90, $userId);
    makeV11Match('provincial', $home->id, $userId, '2026-03-15', 80, $userId);
    makeV11Match('provincial', $home->id, $userId, '2026-05-15', 82, $userId);
    makeV11Match('provincial', $home->id, $userId, '2026-07-15', 78, $userId);

    app(ParticipationEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy', 'user.club', 'user.membership']));

    $part04 = latestV11Part($athlete, 'PART-04');
    expect($part04->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_FAIL);
    expect($part04->detail['out_of_home_2d_shot'])->toBe(0);
});

it('counts internationals as out-of-home regardless of province_id', function () {
    $cycle = makeV11PartCycle();
    $home = makeV11PartProvince('GP');
    $athlete = makeV11PartAthlete($cycle, $home);
    $userId = $athlete->user_id;

    makeV11Match('national', $home->id, $userId, '2026-04-10', 85, $userId);
    makeV11Match('international', $home->id, $userId, '2026-08-10', 88, $userId);
    makeV11Match('final', $home->id, $userId, '2026-11-10', 90, $userId);
    makeV11Match('provincial', $home->id, $userId, '2026-03-15', 80, $userId);
    makeV11Match('provincial', $home->id, $userId, '2026-05-15', 82, $userId);
    makeV11Match('provincial', $home->id, $userId, '2026-07-15', 78, $userId);

    app(ParticipationEvaluator::class)->evaluate($athlete->fresh(['cycle.activePolicy', 'user.club', 'user.membership']));

    $part04 = latestV11Part($athlete, 'PART-04');
    expect($part04->outcome)->toBe(SelectionRuleEvaluation::OUTCOME_PASS);
    expect($part04->detail['out_of_home_2d_shot'])->toBe(1);
});
