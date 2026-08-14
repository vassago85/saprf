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

test('classifyRegistrationCategory matches the admin listing "Active" pill for imported members without payment_status=paid', function () {
    // Real bug: imported members (e.g. Asharuf Moorad SAPRF 399) show up as
    // "Active" in the admin membership listing (which reads
    // Membership::effective_status — expiry + type + not-revoked, no
    // payment_status check) but were classified as "Lapsed Member" on the
    // registration form and charged the surcharge. The two views must agree:
    // if the admin panel is willing to call the shooter Active, the fee
    // engine must too. Anything else is unfixable from a member's POV.
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => '399',
        'membership_type' => 'full',
        'status' => 'active',
        'payment_status' => 'unpaid', // legacy import — never got flipped to 'paid'
        'start_date' => Carbon::parse('2026-02-08'),
        'expiry_date' => Carbon::parse('2027-02-08'),
    ]);
    $user->refresh();

    expect($user->membership->effective_status)->toBe('active');
    expect($this->service->classifyRegistrationCategory($user))->toBe('active_member');
});

test('classifyRegistrationCategory still lapses a truly expired membership regardless of payment_status', function () {
    // The tolerance above must NOT accidentally let expired memberships slip
    // through as active — the expiry_date remains authoritative.
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-TEST-EXPIRED-LEGACY',
        'membership_type' => 'full',
        'status' => 'active', // legacy row never flipped from active
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subYears(2),
        'expiry_date' => Carbon::today()->subMonths(2),
    ]);
    $user->refresh();

    expect($this->service->classifyRegistrationCategory($user))->toBe('lapsed_member');
});

test('classifyRegistrationCategory returns non_member for free-type members even when they look active', function () {
    // A "free" registrant (forced to register just to shoot a provincial) is
    // NEVER member-rate. This mirrors Membership::effective_status returning
    // 'non_member' for the same record.
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-TEST-FREE-ACTIVE',
        'membership_type' => 'free',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);
    $user->refresh();

    expect($this->service->classifyRegistrationCategory($user))->toBe('non_member');
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
