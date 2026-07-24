<?php

use App\Models\Membership;
use App\Models\User;
use App\Notifications\MemberInvitationNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;

beforeEach(function () {
    seedRoles();

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole('admin');
});

function invitee(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => null,
        'must_change_password' => true,
    ], $overrides));
}

function membershipFor(User $user): Membership
{
    return Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'TST-'.$user->id,
    ]);
}

// ── Email / token ──────────────────────────────────────────────────────────

it('stores only a hashed token and emails the raw activation link', function () {
    Notification::fake();

    $member = invitee();
    $membership = membershipFor($member);

    $this->actingAs($this->admin)
        ->post(route('memberships.invite', $membership))
        ->assertRedirect();

    $member->refresh();

    expect($member->invitation_token)->not->toBeNull()
        ->and($member->invitation_token)->toHaveLength(64) // sha256 hex
        ->and($member->invitation_sent_at)->not->toBeNull()
        ->and($member->invitation_expires_at)->not->toBeNull()
        ->and($member->invitation_accepted_at)->toBeNull();

    Notification::assertSentTo($member, MemberInvitationNotification::class, function ($notification) use ($member) {
        $mail = $notification->toMail($member);

        // The raw token in the link must NOT be the stored hash.
        expect($mail->actionUrl)->toContain('/invitation/')
            ->and($mail->actionUrl)->not->toContain($member->fresh()->invitation_token)
            ->and(parse_url($mail->actionUrl, PHP_URL_SCHEME))->not->toBeNull();

        return true;
    });
});

// ── Single invite authorisation & guards ────────────────────────────────────

it('lets an admin invite a member', function () {
    Notification::fake();

    $membership = membershipFor(invitee());

    $this->actingAs($this->admin)
        ->post(route('memberships.invite', $membership))
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentTo($membership->user, MemberInvitationNotification::class);
});

it('forbids a non-admin from inviting', function () {
    Notification::fake();

    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $membership = membershipFor(invitee());

    $this->actingAs($member)
        ->post(route('memberships.invite', $membership))
        ->assertForbidden();

    Notification::assertNothingSent();
});

it('will not invite a managed family account', function () {
    Notification::fake();

    $managed = invitee(['is_managed_account' => true]);
    $membership = membershipFor($managed);

    $this->actingAs($this->admin)
        ->post(route('memberships.invite', $membership))
        ->assertRedirect()
        ->assertSessionHas('error');

    Notification::assertNothingSent();
});

it('will not invite a member without an email', function () {
    Notification::fake();

    $member = invitee();
    $member->forceFill(['email' => ''])->save();
    $membership = membershipFor($member);

    $this->actingAs($this->admin)
        ->post(route('memberships.invite', $membership))
        ->assertRedirect()
        ->assertSessionHas('error');

    Notification::assertNothingSent();
});

// ── Bulk invite ──────────────────────────────────────────────────────────────

it('bulk-invites only members who have not yet onboarded', function () {
    Notification::fake();

    $unverified = invitee(['email_verified_at' => null, 'must_change_password' => false]);
    $starterPassword = invitee(['email_verified_at' => now(), 'must_change_password' => true]);
    $onboarded = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
    $managed = invitee(['is_managed_account' => true]);

    $this->actingAs($this->admin)
        ->post(route('memberships.invite-pending'))
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentTo($unverified, MemberInvitationNotification::class);
    Notification::assertSentTo($starterPassword, MemberInvitationNotification::class);
    Notification::assertNotSentTo($onboarded, MemberInvitationNotification::class);
    Notification::assertNotSentTo($managed, MemberInvitationNotification::class);
    // The acting admin is already onboarded and must not be invited.
    Notification::assertNotSentTo($this->admin, MemberInvitationNotification::class);
});

// ── Acceptance flow ──────────────────────────────────────────────────────────

it('activates the account: sets password, verifies email, logs in, clears token', function () {
    $member = invitee();
    $raw = $member->generateInvitationToken();

    Volt::test('pages.auth.accept-invitation', ['token' => $raw])
        ->assertSet('valid', true)
        ->set('password', 'Sup3r-Secret!')
        ->set('password_confirmation', 'Sup3r-Secret!')
        ->call('activate')
        ->assertRedirect(route('dashboard'));

    $member->refresh();

    expect(Hash::check('Sup3r-Secret!', $member->password))->toBeTrue()
        ->and($member->hasVerifiedEmail())->toBeTrue()
        ->and($member->must_change_password)->toBeFalse()
        ->and($member->invitation_token)->toBeNull()
        ->and($member->invitation_accepted_at)->not->toBeNull()
        ->and(auth()->id())->toBe($member->id);
});

it('rejects a mismatched password confirmation', function () {
    $member = invitee();
    $raw = $member->generateInvitationToken();

    Volt::test('pages.auth.accept-invitation', ['token' => $raw])
        ->set('password', 'Sup3r-Secret!')
        ->set('password_confirmation', 'different')
        ->call('activate')
        ->assertHasErrors('password');

    expect($member->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('is single-use — the token cannot activate a second account', function () {
    $member = invitee();
    $raw = $member->generateInvitationToken();

    Volt::test('pages.auth.accept-invitation', ['token' => $raw])
        ->set('password', 'Sup3r-Secret!')
        ->set('password_confirmation', 'Sup3r-Secret!')
        ->call('activate');

    // Same link opened again is no longer valid.
    Volt::test('pages.auth.accept-invitation', ['token' => $raw])
        ->assertSet('valid', false);
});

it('treats an expired invitation as invalid', function () {
    $member = invitee();
    $member->generateInvitationToken();
    $raw = 'known-raw-token';
    $member->forceFill([
        'invitation_token' => hash('sha256', $raw),
        'invitation_expires_at' => now()->subDay(),
    ])->save();

    Volt::test('pages.auth.accept-invitation', ['token' => $raw])
        ->assertSet('valid', false);
});

it('renders the activation page for a valid link and an error page for a bad one', function () {
    $member = invitee();
    $raw = $member->generateInvitationToken();

    $this->get(route('invitation.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee($member->email);

    $this->get(route('invitation.accept', ['token' => 'not-a-real-token']))
        ->assertOk()
        ->assertSee('Invitation Unavailable');
});
