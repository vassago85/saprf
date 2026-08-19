<?php

/**
 * Match directors can attach a WhatsApp group invite to a match. Confirmed
 * shooters who no longer owe payment see it on their registration page,
 * the payment success page, and the payment emails. Unpaid / waitlisted
 * shooters and the public event page never see it.
 */

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Province;
use App\Models\User;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\SponsoredEntryPaidNotification;
use Carbon\Carbon;

const WHATSAPP_INVITE_URL = 'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrSt';

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->md = User::factory()->create();
    $this->md->assignRole('match_director');

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    $this->member->assignRole('member');
});

function makeInviteMatch(User $creator, Province $province, array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'WhatsApp Invite Match',
        'match_type' => 'PRS',
        'series_level' => 'club',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => $creator->name,
        'active_member_fee' => 250,
        'non_member_fee' => 250,
        'lapsed_member_fee' => 250,
        'created_by' => $creator->id,
        'whatsapp_invite_url' => WHATSAPP_INVITE_URL,
    ], $overrides));
}

function makeInviteRegistration(User $user, MatchEvent $match, array $overrides = []): MatchRegistration
{
    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'email' => $user->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 250.00,
        'payment_status' => 'pending',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ], $overrides));
}

function matchFormPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'WhatsApp Setup Match',
        'match_type' => 'PRS',
        'series_level' => 'club',
        'match_date' => now()->addMonth()->format('Y-m-d'),
        'active_member_fee' => 250,
        'status' => 'open',
    ], $overrides);
}

// ── Match setup ──────────────────────────────────────────────────────

it('lets a match director save a WhatsApp group invite on create', function () {
    $this->actingAs($this->md)
        ->post(route('matches.store'), matchFormPayload([
            'whatsapp_invite_url' => WHATSAPP_INVITE_URL,
        ]))
        ->assertRedirect();

    $match = MatchEvent::query()->where('name', 'WhatsApp Setup Match')->first();

    expect($match)->not->toBeNull()
        ->and($match->whatsapp_invite_url)->toBe(WHATSAPP_INVITE_URL);
});

it('lets a match director update the WhatsApp group invite', function () {
    $match = makeInviteMatch($this->md, $this->province, ['whatsapp_invite_url' => null]);

    $this->actingAs($this->md)
        ->put(route('matches.update', $match), matchFormPayload([
            'name' => $match->name,
            'whatsapp_invite_url' => WHATSAPP_INVITE_URL,
        ]))
        ->assertRedirect(route('matches.show', $match));

    expect($match->fresh()->whatsapp_invite_url)->toBe(WHATSAPP_INVITE_URL);
});

it('rejects a non-WhatsApp URL as the invite', function () {
    $this->actingAs($this->md)
        ->post(route('matches.store'), matchFormPayload([
            'whatsapp_invite_url' => 'https://t.me/+not-whatsapp',
        ]))
        ->assertSessionHasErrors('whatsapp_invite_url');
});

it('rejects a WhatsApp-looking host that is not chat.whatsapp.com', function () {
    $this->actingAs($this->md)
        ->post(route('matches.store'), matchFormPayload([
            'whatsapp_invite_url' => 'https://chat.whatsapp.com.evil.example/AbCdEfGh',
        ]))
        ->assertSessionHasErrors('whatsapp_invite_url');
});

it('allows clearing the WhatsApp invite', function () {
    $match = makeInviteMatch($this->md, $this->province);

    $this->actingAs($this->md)
        ->put(route('matches.update', $match), matchFormPayload([
            'name' => $match->name,
            'whatsapp_invite_url' => '',
        ]))
        ->assertRedirect(route('matches.show', $match));

    expect($match->fresh()->whatsapp_invite_url)->toBeNull();
});

it('shows the WhatsApp invite field on the match create form', function () {
    $this->actingAs($this->md)
        ->get(route('matches.create'))
        ->assertOk()
        ->assertSee('WhatsApp group invite')
        ->assertSee('chat.whatsapp.com');
});

// ── Shooter visibility ───────────────────────────────────────────────

it('shows the join link on the registration page when the entry is paid and confirmed', function () {
    $match = makeInviteMatch($this->md, $this->province);
    $registration = makeInviteRegistration($this->member, $match, [
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
    ]);

    $this->actingAs($this->member)
        ->get(route('registrations.show', $registration))
        ->assertOk()
        ->assertSee('Join match WhatsApp group')
        ->assertSee(WHATSAPP_INVITE_URL);
});

it('shows the join link for a waived confirmed entry', function () {
    $match = makeInviteMatch($this->md, $this->province);
    $registration = makeInviteRegistration($this->member, $match, [
        'payment_status' => 'waived',
        'registration_status' => 'confirmed',
    ]);

    $this->actingAs($this->member)
        ->get(route('registrations.show', $registration))
        ->assertOk()
        ->assertSee(WHATSAPP_INVITE_URL);
});

it('shows the join link for a free confirmed entry that is still pending payment', function () {
    $match = makeInviteMatch($this->md, $this->province);
    $registration = makeInviteRegistration($this->member, $match, [
        'fee_amount' => 0,
        'payment_status' => 'pending',
        'registration_status' => 'confirmed',
    ]);

    $this->actingAs($this->member)
        ->get(route('registrations.show', $registration))
        ->assertOk()
        ->assertSee(WHATSAPP_INVITE_URL);
});

it('hides the join link when payment is still outstanding', function () {
    $match = makeInviteMatch($this->md, $this->province);
    $registration = makeInviteRegistration($this->member, $match, [
        'payment_status' => 'pending',
        'registration_status' => 'pending',
    ]);

    $this->actingAs($this->member)
        ->get(route('registrations.show', $registration))
        ->assertOk()
        ->assertDontSee('Join match WhatsApp group')
        ->assertDontSee(WHATSAPP_INVITE_URL);
});

it('hides the join link from a waitlisted shooter even if they have paid', function () {
    $match = makeInviteMatch($this->md, $this->province);
    $registration = makeInviteRegistration($this->member, $match, [
        'payment_status' => 'paid',
        'registration_status' => 'waitlisted',
    ]);

    $this->actingAs($this->member)
        ->get(route('registrations.show', $registration))
        ->assertOk()
        ->assertDontSee('Join match WhatsApp group')
        ->assertDontSee(WHATSAPP_INVITE_URL);
});

it('does not show the invite on the public event page', function () {
    $match = makeInviteMatch($this->md, $this->province);

    $this->get(route('events.show', $match))
        ->assertOk()
        ->assertDontSee(WHATSAPP_INVITE_URL)
        ->assertDontSee('Join match WhatsApp group');
});

it('shows the join link on the payment success page once PayFast has completed', function () {
    $match = makeInviteMatch($this->md, $this->province);
    $registration = makeInviteRegistration($this->member, $match, [
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
    ]);

    $payment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->member->id,
        'amount' => 250.00,
        'm_payment_id' => 'REG-WA-SUCCESS',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $this->actingAs($this->member)
        ->get(route('payments.return', ['m_payment_id' => $payment->m_payment_id]))
        ->assertOk()
        ->assertSee('Join match WhatsApp group')
        ->assertSee(WHATSAPP_INVITE_URL);
});

it('hides the join link on the payment success page for a waitlisted entry', function () {
    $match = makeInviteMatch($this->md, $this->province);
    $registration = makeInviteRegistration($this->member, $match, [
        'payment_status' => 'paid',
        'registration_status' => 'waitlisted',
    ]);

    $payment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->member->id,
        'amount' => 250.00,
        'm_payment_id' => 'REG-WA-WAITLIST',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $this->actingAs($this->member)
        ->get(route('payments.return', ['m_payment_id' => $payment->m_payment_id]))
        ->assertOk()
        ->assertDontSee('Join match WhatsApp group')
        ->assertDontSee(WHATSAPP_INVITE_URL);
});

it('renders the payment success page for a membership payment without blowing up on the WhatsApp lookup', function () {
    // Regression: membership payments have Membership as the polymorphic
    // payable, which has no `match` relation. The success page must still
    // render — the WhatsApp invite branch only applies to MatchRegistration.
    // Mirror the real scenario: the ITN webhook typically hasn't landed by
    // the time the user is back on the return URL, so the membership is
    // still pending/unpaid and the payment row is still pending too.
    $membership = Membership::create([
        'user_id' => $this->member->id,
        'saprf_number' => Membership::nextSaprfNumber(),
        'membership_type' => 'paid',
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'start_date' => now()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    $payment = Payment::create([
        'payable_type' => Membership::class,
        'payable_id' => $membership->id,
        'user_id' => $this->member->id,
        'amount' => 850.00,
        'm_payment_id' => 'MEM-WA-SUCCESS',
    ]);

    $this->actingAs($this->member)
        ->get(route('payments.return', ['m_payment_id' => $payment->m_payment_id]))
        ->assertOk()
        ->assertDontSee('Join match WhatsApp group');
});

// ── Emails ───────────────────────────────────────────────────────────

it('includes the WhatsApp invite in the match payment receipt email', function () {
    $match = makeInviteMatch($this->md, $this->province);
    $registration = makeInviteRegistration($this->member, $match, [
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
    ]);

    $payment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->member->id,
        'amount' => 250.00,
        'm_payment_id' => 'REG-WA-MAIL',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $mail = (new PaymentReceivedNotification($payment->fresh(['payable.match']), 'registration'))
        ->toMail($this->member);

    $body = implode("\n", array_merge($mail->introLines, $mail->outroLines));

    expect($body)->toContain(WHATSAPP_INVITE_URL);
});

it('includes the WhatsApp invite in the sponsored-entry paid email', function () {
    $match = makeInviteMatch($this->md, $this->province);
    $registration = makeInviteRegistration($this->member, $match, [
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
    ]);

    $sponsor = User::factory()->create(['name' => 'Sponsor Shooter']);
    $payment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $sponsor->id,
        'amount' => 250.00,
        'm_payment_id' => 'REG-WA-SPONSOR',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $mail = (new SponsoredEntryPaidNotification($registration->fresh(['match']), $payment, $sponsor))
        ->toMail($this->member);

    $body = implode("\n", array_merge($mail->introLines, $mail->outroLines));

    expect($body)->toContain(WHATSAPP_INVITE_URL);
});

it('does not mention WhatsApp on a membership payment receipt', function () {
    $payment = Payment::create([
        'payable_type' => \App\Models\Membership::class,
        'payable_id' => 1,
        'user_id' => $this->member->id,
        'amount' => 500.00,
        'm_payment_id' => 'MEM-WA-NONE',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $mail = (new PaymentReceivedNotification($payment, 'membership'))->toMail($this->member);
    $body = implode("\n", array_merge($mail->introLines, $mail->outroLines));

    expect($body)->not->toContain('WhatsApp')
        ->and($body)->not->toContain('chat.whatsapp.com');
});
