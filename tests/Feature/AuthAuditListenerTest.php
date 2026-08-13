<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

beforeEach(fn () => seedRoles());

/**
 * The AuthAuditListener sits on Laravel's built-in Login / Logout / Failed
 * events and writes an audit_logs row for each. These tests fire the events
 * directly (independent of whichever auth stack — Fortify, custom login,
 * OTP, etc. — actually triggered them) so the listener is guaranteed to
 * work regardless of how a user was signed in.
 */

it('logs a user.login row when a Login event fires', function () {
    $user = User::factory()->create();

    event(new Login('web', $user, remember: true));

    $row = AuditLog::query()
        ->where('user_id', $user->id)
        ->where('action_type', 'user.login')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->entity_type)->toBe('User');
    expect((int) $row->entity_id)->toBe($user->id);
    expect($row->new_value['guard'])->toBe('web');
    expect($row->new_value['remember'])->toBeTrue();
    expect($row->new_value)->toHaveKey('ip');
    expect($row->new_value)->toHaveKey('user_agent');
});

it('logs a user.logout row when a Logout event fires', function () {
    $user = User::factory()->create();

    event(new Logout('web', $user));

    $row = AuditLog::query()
        ->where('user_id', $user->id)
        ->where('action_type', 'user.logout')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->entity_type)->toBe('User');
    expect((int) $row->entity_id)->toBe($user->id);
    expect($row->new_value['guard'])->toBe('web');
});

it('skips a Logout event for a guest (no authenticated user)', function () {
    event(new Logout('web', null));

    expect(AuditLog::where('action_type', 'user.logout')->count())->toBe(0);
});

it('logs a user.login.failed row for a known email with wrong password', function () {
    $user = User::factory()->create(['email' => 'known@saprf.co.za']);

    event(new Failed('web', $user, ['email' => 'known@saprf.co.za', 'password' => 'wrong']));

    $row = AuditLog::query()
        ->where('action_type', 'user.login.failed')
        ->latest('id')
        ->firstOrFail();

    expect($row->user_id)->toBeNull(); // no authenticated actor
    expect((int) $row->entity_id)->toBe($user->id);
    expect($row->new_value['attempted_email'])->toBe('known@saprf.co.za');
    expect($row->new_value['user_exists'])->toBeTrue();
    expect($row->new_value)->not->toHaveKey('password');
});

it('logs a user.login.failed row for an unknown email without exposing whether the account exists', function () {
    event(new Failed('web', null, ['email' => 'ghost@nowhere.com', 'password' => 'anything']));

    $row = AuditLog::query()
        ->where('action_type', 'user.login.failed')
        ->latest('id')
        ->firstOrFail();

    expect($row->user_id)->toBeNull();
    expect($row->entity_id)->toBeNull();
    expect($row->new_value['attempted_email'])->toBe('ghost@nowhere.com');
    expect($row->new_value['user_exists'])->toBeFalse();
});

it('never persists the submitted password to the audit log', function () {
    event(new Failed('web', null, ['email' => 'someone@saprf.co.za', 'password' => 'SUPERSECRET!']));

    $row = AuditLog::query()
        ->where('action_type', 'user.login.failed')
        ->latest('id')
        ->firstOrFail();

    $serialised = json_encode($row->new_value);
    expect($serialised)->not->toContain('SUPERSECRET!');
    expect($serialised)->not->toContain('password');
});
