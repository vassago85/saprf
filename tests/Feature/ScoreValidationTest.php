<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\ScoreValidationService;
use Carbon\Carbon;

beforeEach(function () {
    Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1]);
    $province = Province::where('abbreviation', 'GP')->first();

    $this->service = app(ScoreValidationService::class);

    $this->match = MatchEvent::create([
        'name' => 'Score Validation Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today(),
        'status' => 'closed',
        'active_member_fee' => 250.00,
        'non_member_fee' => 500.00,
        'lapsed_member_fee' => 375.00,
        'created_by' => User::factory()->create()->id,
    ]);
});

function makeScore($match, $user, string $status = 'pending', ?Carbon $date = null, float $raw = 100.0): Score
{
    return Score::create([
        'match_id' => $match->id,
        'user_id' => $user?->id,
        'shooter_name' => $user?->name ?? 'Unknown',
        'raw_score' => $raw,
        'placement' => 1,
        'division_id' => Division::where('slug', 'open')->value('id'),
        'match_date' => $date ?? Carbon::today(),
        'status' => $status,
    ]);
}

test('active + paid member on match date → status=valid', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-001',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($this->match, $user));

    expect($result->status)->toBe('valid')
        ->and($result->is_member)->toBeTrue();
});

test('shooter with NO membership record → status=non_member (no grace)', function () {
    $user = User::factory()->create();

    $withinGrace = $this->service->evaluateScoreStatus(makeScore($this->match, $user, 'pending', Carbon::today()->subDays(2)));
    $pastGrace = $this->service->evaluateScoreStatus(makeScore($this->match, $user, 'pending', Carbon::today()->subDays(30)));

    expect($withinGrace->status)->toBe('non_member')
        ->and($pastGrace->status)->toBe('non_member')
        ->and($withinGrace->is_member)->toBeFalse();
});

test('free registrant (forced to register for one provincial) → status=non_member even if flagged active/paid', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-FREE',
        'membership_type' => 'free',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($this->match, $user));

    expect($result->status)->toBe('non_member')
        ->and($result->is_member)->toBeFalse();
});

test('a score earned inside the paid window stays valid even after the membership later expires', function () {
    $matchDate = Carbon::today()->subMonths(2);
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-EXPIRED',
        'membership_type' => 'paid',
        'status' => 'expired', // has since lapsed
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subMonths(8),
        'expiry_date' => Carbon::today()->subMonths(1), // covered the match date
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($this->match, $user, 'pending', $matchDate));

    expect($result->status)->toBe('valid')
        ->and($result->is_member)->toBeTrue();
});

test('shooter WITH a membership but lapsed at match, within 7-day grace → status=pending', function () {
    $matchDate = Carbon::today()->subDays(3);
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-002',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => $matchDate->copy()->subDay(),
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($this->match, $user, 'pending', $matchDate));

    expect($result->status)->toBe('pending')
        ->and($result->is_member)->toBeFalse();
});

test('shooter WITH a membership but lapsed, past 7-day grace → status=lapsed (not invalid)', function () {
    $matchDate = Carbon::today()->subDays(10);
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-003',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => $matchDate->copy()->subDay(),
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($this->match, $user, 'pending', $matchDate));

    expect($result->status)->toBe('lapsed')
        ->and($result->is_member)->toBeFalse();
});

test('score without user_id → status=invalid (orphan)', function () {
    $result = $this->service->evaluateScoreStatus(makeScore($this->match, null));

    expect($result->status)->toBe('invalid')
        ->and($result->validation_reason)->toBe('No linked member account.');
});

test('resolvePendingScoresForUser reclassifies pending scores in isolation', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-004',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    $score = makeScore($this->match, $user, 'pending', Carbon::today());

    $affectedMatchIds = $this->service->resolvePendingScoresForUser($user->id);

    expect($affectedMatchIds)->toContain($this->match->id)
        ->and($score->fresh()->status)->toBe('valid');

    $noopIds = $this->service->resolvePendingScoresForUser($user->id);
    expect($noopIds)->toBe([]);
});

test('MembershipObserver auto-promotes pending scores when membership becomes valid', function () {
    $matchDate = Carbon::today()->subDays(2);
    $user = User::factory()->create();
    $mship = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-005',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => $matchDate->copy()->subDay(),
    ]);

    $score = makeScore($this->match, $user, 'pending', $matchDate);
    $this->service->evaluateScoreStatus($score);
    expect($score->fresh()->status)->toBe('pending');

    $mship->update([
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    expect($score->fresh()->status)->toBe('valid');
});

test('non_member scores never get promoted, even inside grace window', function () {
    $matchDate = Carbon::today()->subDays(2);
    $user = User::factory()->create();

    $score = makeScore($this->match, $user, 'pending', $matchDate);
    $this->service->evaluateScoreStatus($score);
    expect($score->fresh()->status)->toBe('non_member');

    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-006',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    expect($score->fresh()->status)->toBe('non_member');
});

test('resolvePendingScores classifies each pending score correctly', function () {
    $validUser = User::factory()->create();
    Membership::create([
        'user_id' => $validUser->id,
        'saprf_number' => 'SAPRF-SCORE-100',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    $nonMemberUser = User::factory()->create();

    $lapsedUser = User::factory()->create();
    Membership::create([
        'user_id' => $lapsedUser->id,
        'saprf_number' => 'SAPRF-SCORE-101',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => Carbon::today()->subDays(30),
    ]);

    makeScore($this->match, $validUser, 'pending', Carbon::today());
    makeScore($this->match, $nonMemberUser, 'pending', Carbon::today()->subDays(30));
    makeScore($this->match, $lapsedUser, 'pending', Carbon::today()->subDays(30));

    $count = $this->service->resolvePendingScores();

    expect($count)->toBe(3);

    $statuses = Score::orderBy('id')->pluck('status')->toArray();
    expect($statuses)->toBe(['valid', 'non_member', 'lapsed']);
});
