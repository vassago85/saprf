<?php

use App\Models\Setting;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use App\Notifications\MemberInvitationNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\SettingsService;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    seedRoles();

    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

/**
 * Helper: run the NotificationSending event through the real dispatcher and
 * report whether Laravel would have sent the notification.
 *
 * Laravel's NotificationSender cancels a send when Event::until() returns false.
 */
function notificationWouldSend(User $user, $notification, string $channel = 'mail'): bool
{
    $result = Event::until(new NotificationSending($user, $notification, $channel));

    return $result !== false;
}

function setNotificationsEnabled(bool $enabled): void
{
    Setting::updateOrCreate(
        ['key' => 'notifications_enabled'],
        ['value' => $enabled ? '1' : '0', 'description' => 'test'],
    );
    app(SettingsService::class)->clearCache();
}

it('allows transactional notifications when notifications_enabled is true', function () {
    setNotificationsEnabled(true);

    expect(notificationWouldSend($this->user, new MemberInvitationNotification('token-abc')))
        ->toBeTrue();
});

it('suppresses transactional notifications when notifications_enabled is false', function () {
    setNotificationsEnabled(false);

    expect(notificationWouldSend($this->user, new MemberInvitationNotification('token-abc')))
        ->toBeFalse();
});

it('still sends the OTP notification when notifications_enabled is false (auth-critical)', function () {
    setNotificationsEnabled(false);

    expect(notificationWouldSend($this->user, new EmailOtpNotification('123456')))
        ->toBeTrue();
});

it('still sends the password-reset notification when notifications_enabled is false (auth-critical)', function () {
    setNotificationsEnabled(false);

    expect(notificationWouldSend($this->user, new ResetPasswordNotification('reset-token-abc')))
        ->toBeTrue();
});

it('leaves non-mail channels alone even when notifications_enabled is false', function () {
    setNotificationsEnabled(false);

    // The database channel (used by in-app notifications) shouldn't be gated
    // by the mail toggle.
    expect(notificationWouldSend($this->user, new MemberInvitationNotification('token-abc'), 'database'))
        ->toBeTrue();
});

it('defaults to sending when the setting has never been written (fail-open)', function () {
    // Fresh install: notifications_enabled row doesn't exist in the DB.
    Setting::where('key', 'notifications_enabled')->delete();
    app(SettingsService::class)->clearCache();

    expect(notificationWouldSend($this->user, new MemberInvitationNotification('token-abc')))
        ->toBeTrue();
});

it('actually prevents mail dispatch end-to-end when the toggle is off', function () {
    // End-to-end: use real Mail::fake() and dispatch through the notification
    // pipeline. This proves the listener plugs into the real send path (not
    // just the event contract).
    setNotificationsEnabled(false);
    Mail::fake();

    $this->user->notify(new MemberInvitationNotification('token-abc'));

    Mail::assertNothingSent();
});
