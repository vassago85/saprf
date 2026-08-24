<?php

use App\Models\Membership;
use App\Models\User;
use App\Notifications\MembershipExpiredNotification;
use App\Services\MembershipValidationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

/**
 * The one-off backfill sweep for the 90-day cutoff must:
 *   - flip long-lapsed memberships from 'lapsed' to 'expired'
 *   - dispatch MembershipExpiredNotification once per flipped member
 *   - leave in-grace, revoked, active, or free memberships untouched
 *   - be safe to re-run (idempotent)
 */

beforeEach(function () {
    Notification::fake();
});

it('flips lapsed memberships past the cutoff to expired and queues the notice', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-CUTOFF-BACKFILL-1',
        'membership_type' => 'paid',
        'status' => 'lapsed',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS + 5),
    ]);

    Artisan::call('saprf:apply-lapsed-cutoff');

    $user->refresh()->load('membership');
    expect($user->membership->status)->toBe('expired');
    Notification::assertSentTo($user, MembershipExpiredNotification::class);
});

it('leaves in-grace lapsed memberships alone', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-CUTOFF-BACKFILL-INGRACE',
        'membership_type' => 'paid',
        'status' => 'lapsed',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS - 10),
    ]);

    Artisan::call('saprf:apply-lapsed-cutoff');

    $user->refresh()->load('membership');
    expect($user->membership->status)->toBe('lapsed');
    Notification::assertNothingSentTo($user);
});

it('does not touch revoked or free memberships even when past the cutoff', function () {
    $revokedUser = User::factory()->create();
    Membership::create([
        'user_id' => $revokedUser->id,
        'saprf_number' => 'SAPRF-CUTOFF-BACKFILL-REVOKED',
        'membership_type' => 'paid',
        'status' => 'revoked',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS + 30),
    ]);

    $freeUser = User::factory()->create();
    Membership::create([
        'user_id' => $freeUser->id,
        'saprf_number' => 'SAPRF-CUTOFF-BACKFILL-FREE',
        'membership_type' => 'free',
        'status' => 'lapsed',
        'payment_status' => 'unpaid',
        'expiry_date' => Carbon::today()->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS + 30),
    ]);

    Artisan::call('saprf:apply-lapsed-cutoff');

    expect($revokedUser->fresh()->load('membership')->membership->status)->toBe('revoked');
    expect($freeUser->fresh()->load('membership')->membership->status)->toBe('lapsed');
    Notification::assertNothingSentTo($revokedUser);
    Notification::assertNothingSentTo($freeUser);
});

it('is idempotent: re-running does not re-email members already flipped', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-CUTOFF-BACKFILL-IDEMPOTENT',
        'membership_type' => 'paid',
        'status' => 'lapsed',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS + 5),
    ]);

    Artisan::call('saprf:apply-lapsed-cutoff');
    Notification::assertSentToTimes($user, MembershipExpiredNotification::class, 1);

    Artisan::call('saprf:apply-lapsed-cutoff');
    Notification::assertSentToTimes($user, MembershipExpiredNotification::class, 1);
});

it('sweeps already-expired members that missed the once-off cutoff notice', function () {
    // Members whose status was stamped straight to 'expired' by the older
    // scores:reevaluate housekeeping never received the cutoff email —
    // the backfill must catch them too, not only fresh 'lapsed' rows.
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-CUTOFF-BACKFILL-PREEXPIRED',
        'membership_type' => 'paid',
        'status' => 'expired',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS + 30),
    ]);

    Artisan::call('saprf:apply-lapsed-cutoff');

    Notification::assertSentTo($user, MembershipExpiredNotification::class);

    // Second run: the audit-log marker prevents a re-send even though the
    // status is unchanged.
    Artisan::call('saprf:apply-lapsed-cutoff');
    Notification::assertSentToTimes($user, MembershipExpiredNotification::class, 1);
});

it('dry-run writes nothing and sends nothing', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-CUTOFF-BACKFILL-DRYRUN',
        'membership_type' => 'paid',
        'status' => 'lapsed',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS + 5),
    ]);

    Artisan::call('saprf:apply-lapsed-cutoff', ['--dry-run' => true]);

    expect($user->fresh()->load('membership')->membership->status)->toBe('lapsed');
    Notification::assertNothingSentTo($user);
});

it('skip-email flag flips statuses but does not queue the notice', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-CUTOFF-BACKFILL-SKIPEMAIL',
        'membership_type' => 'paid',
        'status' => 'lapsed',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS + 5),
    ]);

    Artisan::call('saprf:apply-lapsed-cutoff', ['--skip-email' => true]);

    expect($user->fresh()->load('membership')->membership->status)->toBe('expired');
    Notification::assertNothingSentTo($user);
});
