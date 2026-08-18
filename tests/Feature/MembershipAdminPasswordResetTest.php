<?php

/**
 * Admin-side "set a temporary password" flow for members who can't receive
 * email (invitation/password-reset emails bouncing, wrong address, spam
 * filter). Covers:
 *   - only admins/owners/exco/developer can invoke it
 *   - reason is required (audit signal)
 *   - either a custom password or an auto-generated one is applied
 *   - the user's password hash actually changes
 *   - must_change_password is set so ForcePasswordChange kicks in next login
 *   - the plaintext password is flashed to the session for one render only
 *   - an audit log entry is written without the password anywhere in it
 */

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    seedRoles();

    $this->member = User::factory()->create([
        'email' => 'member@example.com',
        'password' => Hash::make('the-original-password'),
        'must_change_password' => false,
    ]);
    $this->member->assignRole('member');

    $this->membership = Membership::create([
        'user_id' => $this->member->id,
        'saprf_number' => 'TEMP-001',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
    ]);
});

it('lets an admin set a temporary password and flashes it once', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->post(
        route('memberships.reset-password', $this->membership),
        [
            'reason' => 'Member says invitation email never arrived.',
            'custom_password' => 'ManualTempPass123',
        ]
    );

    $response->assertRedirect(route('memberships.show', $this->membership));
    $response->assertSessionHas('temp_password', 'ManualTempPass123');
    $response->assertSessionHas('temp_password_for', $this->member->name);
    $response->assertSessionHas('temp_password_reason', 'Member says invitation email never arrived.');

    $this->member->refresh();
    expect(Hash::check('ManualTempPass123', $this->member->password))->toBeTrue()
        ->and(Hash::check('the-original-password', $this->member->password))->toBeFalse()
        ->and($this->member->must_change_password)->toBeTrue();
});

it('auto-generates a 16-char alphanumeric password when none is supplied', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->post(
        route('memberships.reset-password', $this->membership),
        ['reason' => 'No email delivery.']
    );

    $response->assertRedirect();
    $temp = session('temp_password');

    expect($temp)->toBeString()
        ->and(strlen($temp))->toBe(16)
        // Alphanumeric only — no symbols/spaces so it's safe to relay by
        // phone/WhatsApp without any character getting mangled.
        ->and($temp)->toMatch('/^[A-Za-z0-9]{16}$/');

    $this->member->refresh();
    expect(Hash::check($temp, $this->member->password))->toBeTrue()
        ->and($this->member->must_change_password)->toBeTrue();
});

it('records an audit log entry with the reason but not the password', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->post(
        route('memberships.reset-password', $this->membership),
        [
            'reason' => 'Bounced from Mailgun three times.',
            'custom_password' => 'SecretTempPass456',
        ]
    );

    $audit = AuditLog::where('action_type', 'user.admin_password_reset')
        ->where('entity_type', 'User')
        ->where('entity_id', $this->member->id)
        ->latest()
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->new_value['reason'] ?? null)->toBe('Bounced from Mailgun three times.');

    // The plaintext MUST NOT end up in the audit under any key. Guarding
    // this explicitly because a well-meaning refactor could easily start
    // storing the "new state" of the user row including the hash.
    $serialised = json_encode($audit->toArray());
    expect($serialised)->not->toContain('SecretTempPass456');
});

it('rejects the reset when no reason is provided', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->post(
        route('memberships.reset-password', $this->membership),
        ['custom_password' => 'ValidLongEnough123']
    );

    $response->assertSessionHasErrors('reason');

    $this->member->refresh();
    expect(Hash::check('the-original-password', $this->member->password))->toBeTrue()
        ->and($this->member->must_change_password)->toBeFalse();
});

it('rejects a custom password shorter than 12 characters', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->post(
        route('memberships.reset-password', $this->membership),
        [
            'reason' => 'Testing validation.',
            'custom_password' => 'short',
        ]
    );

    $response->assertSessionHasErrors('custom_password');

    $this->member->refresh();
    expect(Hash::check('the-original-password', $this->member->password))->toBeTrue();
});

it('forbids a plain member from resetting anyone else\'s password', function () {
    $otherMember = User::factory()->create();
    $otherMember->assignRole('member');

    $this->actingAs($otherMember)
        ->post(route('memberships.reset-password', $this->membership), [
            'reason' => 'trying to reset',
            'custom_password' => 'shouldNotWork123',
        ])
        ->assertForbidden();

    $this->member->refresh();
    expect(Hash::check('the-original-password', $this->member->password))->toBeTrue();
});

it('allows owner, exco, and developer roles to reset a password', function () {
    foreach (['owner', 'exco', 'developer'] as $role) {
        $actor = User::factory()->create();
        $actor->assignRole($role);

        $this->actingAs($actor)->post(
            route('memberships.reset-password', $this->membership),
            [
                'reason' => "Reset by {$role}",
                'custom_password' => "TempFrom{$role}999",
            ]
        )->assertRedirect(route('memberships.show', $this->membership));

        $this->member->refresh();
        expect(Hash::check("TempFrom{$role}999", $this->member->password))->toBeTrue();
    }
});
