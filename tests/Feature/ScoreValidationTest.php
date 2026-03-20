<?php

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use App\Services\ScoreValidationService;
use Carbon\Carbon;

beforeEach(function () {
    Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
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

test('valid member score gets valid status', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-001',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    $score = Score::create([
        'match_id' => $this->match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'raw_score' => 95.500,
        'placement' => 1,
        'division' => 'Open',
        'match_date' => Carbon::today(),
        'status' => 'pending',
    ]);

    $this->actingAs($user);
    $result = $this->service->evaluateScoreStatus($score);

    expect($result->status)->toBe('valid')
        ->and($result->is_member)->toBeTrue()
        ->and($result->validation_reason)->toBe('Valid paid member.');
});

test('non-member score beyond grace window gets invalid status', function () {
    $matchDate = Carbon::today()->subDays(10);
    $user = User::factory()->create();

    $score = Score::create([
        'match_id' => $this->match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'raw_score' => 80.000,
        'placement' => 5,
        'division' => 'Open',
        'match_date' => $matchDate,
        'status' => 'pending',
    ]);

    $this->actingAs($user);
    $result = $this->service->evaluateScoreStatus($score);

    expect($result->status)->toBe('invalid')
        ->and($result->is_member)->toBeFalse()
        ->and($result->validation_reason)->toBe('Membership not valid on match date.');
});

test('lapsed member within 7-day grace window gets pending status', function () {
    $matchDate = Carbon::today()->subDays(3);
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-002',
        'status' => 'expired',
        'payment_status' => 'paid',
        'expiry_date' => $matchDate->copy()->subDay(),
    ]);

    $score = Score::create([
        'match_id' => $this->match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'raw_score' => 88.000,
        'placement' => 3,
        'division' => 'Open',
        'match_date' => $matchDate,
        'status' => 'pending',
    ]);

    $this->actingAs($user);
    $result = $this->service->evaluateScoreStatus($score);

    expect($result->status)->toBe('pending')
        ->and($result->is_member)->toBeFalse()
        ->and($result->validation_reason)->toBe('Within 7-day regularisation window.');
});

test('lapsed member beyond 7-day grace window gets invalid status', function () {
    $matchDate = Carbon::today()->subDays(10);
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-003',
        'status' => 'expired',
        'payment_status' => 'paid',
        'expiry_date' => $matchDate->copy()->subDay(),
    ]);

    $score = Score::create([
        'match_id' => $this->match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'raw_score' => 72.000,
        'placement' => 8,
        'division' => 'Open',
        'match_date' => $matchDate,
        'status' => 'pending',
    ]);

    $this->actingAs($user);
    $result = $this->service->evaluateScoreStatus($score);

    expect($result->status)->toBe('invalid')
        ->and($result->is_member)->toBeFalse()
        ->and($result->validation_reason)->toBe('Membership not valid on match date.');
});

test('score without user_id gets invalid status', function () {
    $score = Score::create([
        'match_id' => $this->match->id,
        'user_id' => null,
        'shooter_name' => 'Unknown Shooter',
        'raw_score' => 60.000,
        'placement' => 10,
        'division' => 'Open',
        'match_date' => Carbon::today(),
        'status' => 'pending',
    ]);

    $this->actingAs(User::factory()->create());
    $result = $this->service->evaluateScoreStatus($score);

    expect($result->status)->toBe('invalid')
        ->and($result->is_member)->toBeFalse()
        ->and($result->validation_reason)->toBe('No linked member account.');
});

test('resolvePendingScores processes all pending scores', function () {
    $validUser = User::factory()->create();
    Membership::create([
        'user_id' => $validUser->id,
        'saprf_number' => 'SAPRF-SCORE-004',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    $invalidUser = User::factory()->create();

    Score::create([
        'match_id' => $this->match->id,
        'user_id' => $validUser->id,
        'shooter_name' => $validUser->name,
        'raw_score' => 90.000,
        'placement' => 2,
        'division' => 'Open',
        'match_date' => Carbon::today(),
        'status' => 'pending',
    ]);

    Score::create([
        'match_id' => $this->match->id,
        'user_id' => $invalidUser->id,
        'shooter_name' => $invalidUser->name,
        'raw_score' => 70.000,
        'placement' => 7,
        'division' => 'Open',
        'match_date' => Carbon::today()->subDays(10),
        'status' => 'pending',
    ]);

    $this->actingAs(User::factory()->create());
    $count = $this->service->resolvePendingScores();

    expect($count)->toBe(2);

    $scores = Score::orderBy('id')->get();
    expect($scores[0]->status)->toBe('valid')
        ->and($scores[1]->status)->toBe('invalid');
});
