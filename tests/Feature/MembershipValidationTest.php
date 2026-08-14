<?php

use App\Models\Membership;
use App\Models\User;
use App\Services\MembershipValidationService;
use Carbon\Carbon;

beforeEach(function () {
    $this->service = app(MembershipValidationService::class);
});

test('active paid membership is valid on date', function () {
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-TEST-001',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subYear(),
        'expiry_date' => Carbon::today()->addMonths(6),
    ]);
    expect($this->service->isMembershipValidOnDate($membership, Carbon::today()))->toBeTrue();
});

test('expired membership is not valid', function () {
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-TEST-002',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subYears(2),
        'expiry_date' => Carbon::today()->subDay(),
    ]);
    expect($this->service->isMembershipValidOnDate($membership, Carbon::today()))->toBeFalse();
});

test('unpaid membership is not valid', function () {
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-TEST-003',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);
    expect($this->service->isMembershipValidOnDate($membership, Carbon::today()))->toBeFalse();
});

test('null membership returns false', function () {
    expect($this->service->isMembershipValidOnDate(null, Carbon::today()))->toBeFalse();
});

test('expired-status membership is still valid for a date inside its old paid window', function () {
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-TEST-WINDOW',
        'membership_type' => 'paid',
        'status' => 'expired', // lapsed since, but was valid earlier
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subMonths(8),
        'expiry_date' => Carbon::today()->subMonths(1),
    ]);

    // A match two months ago fell inside the paid window -> valid.
    expect($this->service->isMembershipValidOnDate($membership, Carbon::today()->subMonths(2)))->toBeTrue()
        // But today, past expiry, it is not valid.
        ->and($this->service->isMembershipValidOnDate($membership, Carbon::today()))->toBeFalse();
});

test('revoked membership is never valid, even inside its window', function () {
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-TEST-REVOKED',
        'membership_type' => 'paid',
        'status' => 'revoked',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subMonths(2),
        'expiry_date' => Carbon::today()->addMonths(6),
    ]);
    expect($this->service->isMembershipValidOnDate($membership, Carbon::today()))->toBeFalse();
});

test('free membership is never valid, even when active + paid + unexpired', function () {
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-TEST-FREE',
        'membership_type' => 'free',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);
    expect($this->service->isMembershipValidOnDate($membership, Carbon::today()))->toBeFalse();
});

test('classifyRegistrationCategory returns active_member for valid member', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-TEST-004',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);
    $user->refresh();
    expect($this->service->classifyRegistrationCategory($user, Carbon::today()))->toBe('active_member');
});

test('classifyRegistrationCategory returns lapsed_member for expired member', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-TEST-005',
        'membership_type' => 'paid',
        'status' => 'expired',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subMonth(),
    ]);
    $user->refresh();
    expect($this->service->classifyRegistrationCategory($user, Carbon::today()))->toBe('lapsed_member');
});

test('classifyRegistrationCategory returns non_member for no membership', function () {
    $user = User::factory()->create();
    expect($this->service->classifyRegistrationCategory($user, Carbon::today()))->toBe('non_member');
});

test('classifyRegistrationCategory is based on signup date, not the match date', function () {
    // Member is valid TODAY (signup) but their membership expires BEFORE the
    // future match. Category must still be active_member — score validation
    // handles the match-day check separately and will downgrade the score if
    // they never renew.
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-TEST-SIGNUP',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subMonths(6),
        'expiry_date' => Carbon::today()->addMonth(),
    ]);
    $user->refresh();

    $futureMatch = Carbon::today()->addMonths(3);

    expect($this->service->classifyRegistrationCategory($user, $futureMatch))->toBe('active_member');
});
