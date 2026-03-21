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
