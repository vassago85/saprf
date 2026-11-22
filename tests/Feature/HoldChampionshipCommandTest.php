<?php

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\ImpactScoring\ImpactLiveSyncService;
use App\Services\StandingsCalculationService;

beforeEach(function () {
    $this->province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $this->owner = User::factory()->create();

    QualificationRule::create([
        'series' => 'PRS',
        'season' => '2026',
        'scoring_mode' => QualificationRule::MODE_NATIONAL_SERIES_2027,
        'min_out_of_province_matches' => 1,
        'best_of_count' => 3,
        'total_qualifying_matches' => 4,
        'created_by' => $this->owner->id,
    ]);
});

it('takes the championship off the year log and leaves the day 1 provincial', function () {
    $shooter = holdShooter('Championship Shooter', $this->province);
    $rival = holdShooter('Rival Shooter', $this->province);

    $national = holdMatch('Centrefire WC National', 'national', '2026-08-08');
    holdScore($national, $rival, 100);
    holdScore($national, $shooter, 80);

    $champs = holdMatch('Centrefire GP 2-Day National Championship Match', 'final', '2026-11-21', [
        'match_end_date' => '2026-11-22',
        'scores_finalized_at' => now(),
        'scores_finalized_by' => $this->owner->id,
    ]);
    holdScore($champs, $rival, 100);
    holdScore($champs, $shooter, 100);

    $day1 = holdMatch('Centrefire GP 2-Day National Championship Match — Provincial (Day 1)', 'provincial', '2026-11-21', [
        'source_national_match_id' => $champs->id,
    ]);
    holdScore($day1, $shooter, 40);

    app(StandingsCalculationService::class)->calculateMatchRankings($national);
    app(StandingsCalculationService::class)->calculateMatchRankings($champs);
    app(StandingsCalculationService::class)->recalculateSeasonStandings('PRS', '2026');

    $before = holdOverall($shooter);
    expect((float) $before->points)->toBe(280.0)
        ->and($before->pool_breakdown['champs_pct'])->toEqual(200.0);

    $this->artisan('scores:hold-championship', ['match' => $champs->id])->assertSuccessful();

    $champsScore = Score::where('match_id', $champs->id)->where('user_id', $shooter->id)->first();
    $day1Score = Score::where('match_id', $day1->id)->where('user_id', $shooter->id)->first();
    $after = holdOverall($shooter);

    expect(Score::where('match_id', $champs->id)->count())->toBe(2)
        ->and($champs->fresh()->scores_finalized_at)->toBeNull()
        ->and($champsScore->counts_for_season)->toBeFalse()
        ->and($champsScore->counts_for_log)->toBeFalse()
        ->and((float) $champsScore->raw_score)->toBe(100.0)
        ->and($day1Score->counts_for_season)->toBeTrue()
        ->and((float) $after->points)->toBe(80.0)
        ->and($after->pool_breakdown['champs'])->toBeNull()
        ->and($after->pool_breakdown['champs_pct'])->toEqual(0.0);

    app(ImpactLiveSyncService::class)->setConfirmed($champs->fresh(), true);

    expect($champsScore->fresh()->counts_for_season)->toBeTrue()
        ->and((float) holdOverall($shooter)->points)->toBe(280.0);
});

it('holds the only championship for the season when no match id is given', function () {
    $champs = holdMatch('SA Championships', 'final', '2026-11-21');
    $shooter = holdShooter('Only Final Shooter', $this->province);
    holdScore($champs, $shooter, 90);

    $this->artisan('scores:hold-championship')->assertSuccessful();

    expect(Score::where('match_id', $champs->id)->value('counts_for_season'))->toBeFalse();
});

it('refuses a day 1 provincial and a season with two championships', function () {
    $day1 = holdMatch('Day 1 provincial', 'provincial', '2026-11-21');
    $shooter = holdShooter('Provincial Shooter', $this->province);
    holdScore($day1, $shooter, 40);

    $this->artisan('scores:hold-championship', ['match' => $day1->id])->assertFailed();
    expect(Score::where('match_id', $day1->id)->value('counts_for_season'))->toBeTrue();

    holdMatch('Champs A', 'final', '2026-11-21');
    holdMatch('Champs B', 'final', '2026-11-22');

    $this->artisan('scores:hold-championship')->assertFailed();
    expect(Score::where('match_id', $day1->id)->value('counts_for_season'))->toBeTrue();
});

function holdShooter(string $name, Province $province): User
{
    $user = User::factory()->create(['name' => $name, 'province_id' => $province->id]);
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-HOLD-'.$user->id,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    return $user;
}

function holdMatch(string $name, string $level, string $date, array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => $name,
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => $level,
        'season' => '2026',
        'match_date' => $date,
        'province_id' => test()->province->id,
        'status' => 'closed',
        'created_by' => test()->owner->id,
        'published' => true,
    ], $overrides));
}

function holdScore(MatchEvent $match, User $user, float $raw): Score
{
    return Score::create([
        'match_id' => $match->id,
        'shooter_name' => $user->name,
        'user_id' => $user->id,
        'day1_raw_score' => $raw,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_season' => true,
        'counts_for_log' => true,
        'match_date' => $match->match_date->toDateString(),
    ]);
}

function holdOverall(User $user): Standing
{
    return Standing::where('user_id', $user->id)
        ->where('series', 'PRS')
        ->where('season', '2026')
        ->whereNull('province_id')
        ->whereNull('division_id')
        ->firstOrFail();
}
