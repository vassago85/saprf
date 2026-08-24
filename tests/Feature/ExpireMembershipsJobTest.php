<?php

use App\Jobs\ExpireMembershipsJob;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\MembershipExpiredNotification;
use App\Notifications\MembershipExpiringSoonNotification;
use App\Notifications\MembershipLapsedNotification;
use App\Services\MembershipValidationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Exercises the daily membership housekeeping job. Three passes:
 *   1. Auto-lapse memberships whose expiry_date has just passed.
 *   2. Send -30 / -7 day expiry reminders.
 *   3. Send the once-off "you've now crossed the lapsed cutoff" notice
 *      exactly LAPSED_CUTOFF_DAYS days after expiry_date.
 */

beforeEach(function () {
    Notification::fake();
});

it('sends the lapsed-cutoff notice on the exact day the cutoff is crossed', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-CUTOFF-1',
        'membership_type' => 'paid',
        'status' => 'lapsed',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS),
    ]);

    (new ExpireMembershipsJob())->handle(app(App\Services\AuditLogService::class));

    Notification::assertSentTo($user, MembershipExpiredNotification::class);
});

it('does not send the lapsed-cutoff notice one day before the cutoff', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-CUTOFF-2',
        'membership_type' => 'paid',
        'status' => 'lapsed',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS - 1),
    ]);

    (new ExpireMembershipsJob())->handle(app(App\Services\AuditLogService::class));

    Notification::assertNothingSentTo($user);
});

it('does not re-send the lapsed-cutoff notice the day after the cutoff', function () {
    // Idempotency: the query is keyed on the exact cutoff date, so a shooter
    // who received the notice yesterday must not receive it again today.
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-CUTOFF-3',
        'membership_type' => 'paid',
        'status' => 'lapsed',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS + 1),
    ]);

    (new ExpireMembershipsJob())->handle(app(App\Services\AuditLogService::class));

    Notification::assertNothingSentTo($user);
});

it('auto-lapses an overdue active membership and emails the lapsed notice', function () {
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-LAPSE-1',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::yesterday(),
    ]);

    (new ExpireMembershipsJob())->handle(app(App\Services\AuditLogService::class));

    expect($membership->refresh()->status)->toBe('lapsed');
    Notification::assertSentTo($user, MembershipLapsedNotification::class);
});

it('sends the -30 day expiring-soon reminder', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-REMIND-30',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addDays(30),
    ]);

    (new ExpireMembershipsJob())->handle(app(App\Services\AuditLogService::class));

    Notification::assertSentTo(
        $user,
        MembershipExpiringSoonNotification::class,
    );
});
