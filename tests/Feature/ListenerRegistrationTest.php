<?php

/**
 * Guardrail for the "double listener registration" bug that produced
 * duplicate rows in email_logs (queued orphan + delivered) and
 * audit_logs (two user.login rows per successful login).
 *
 * Laravel 12's listener auto-discovery scans app/Listeners/ and
 * registers ANY method whose first argument type-hints an event class
 * — not just `handle`. That means AuthAuditListener::handleLogin,
 * handleLogout, and handleFailed are ALL auto-registered. Manually
 * calling Event::listen() for the same class in AppServiceProvider
 * would double every listener → double every side-effect.
 *
 * These tests dispatch each event and assert exactly one downstream
 * row is written. If a future refactor re-adds Event::listen(), one
 * of these will fail with the assertion "expected 1, got 2".
 */

use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    seedRoles();
});

it('writes exactly one audit row for a Login event', function () {
    $user = User::factory()->create();
    $user->assignRole('member');

    $before = AuditLog::query()->where('action_type', 'user.login')->count();
    event(new Login('web', $user, false));
    $after = AuditLog::query()->where('action_type', 'user.login')->count();

    expect($after - $before)->toBe(1, 'Login event double-fired — check AppServiceProvider is not manually binding AuthAuditListener.');
});

it('writes exactly one audit row for a Logout event', function () {
    $user = User::factory()->create();
    $user->assignRole('member');

    $before = AuditLog::query()->where('action_type', 'user.logout')->count();
    event(new Logout('web', $user));
    $after = AuditLog::query()->where('action_type', 'user.logout')->count();

    expect($after - $before)->toBe(1);
});

it('writes exactly one audit row for a Failed login event', function () {
    $user = User::factory()->create(['email' => 'audit-once@example.test']);
    $user->assignRole('member');

    $before = AuditLog::query()->where('action_type', 'user.login.failed')->count();
    event(new Failed('web', $user, ['email' => 'audit-once@example.test']));
    $after = AuditLog::query()->where('action_type', 'user.login.failed')->count();

    expect($after - $before)->toBe(1);
});

it('writes exactly one email_logs row per notification send', function () {
    Config::set('mail.default', 'array');

    $user = User::factory()->create(['email' => 'only-one@example.test', 'email_verified_at' => now()]);
    $user->assignRole('member');

    $before = EmailLog::query()->where('to_email', 'only-one@example.test')->count();
    $user->notifyNow(new ResetPasswordNotification('token'));
    $after = EmailLog::query()->where('to_email', 'only-one@example.test')->count();

    expect($after - $before)->toBe(1, 'LogSendingMail or LogSentMail double-registered — check AppServiceProvider.');
});
