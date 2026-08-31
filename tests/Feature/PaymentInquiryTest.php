<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;
use App\Notifications\PaymentInquiryNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    $this->md = User::factory()->create(['name' => 'Match Director']);
    $this->md->assignRole('match_director');

    $this->admin = User::factory()->create(['name' => 'Admin User']);
    $this->admin->assignRole('admin');

    $this->otherMd = User::factory()->create(['name' => 'Unrelated MD']);
    $this->otherMd->assignRole('match_director');

    $this->shooter = User::factory()->create([
        'name' => 'Grant Bower',
        'email' => 'grant@example.com',
    ]);
    $this->shooter->assignRole('member');

    $this->match = MatchEvent::create([
        'name' => 'Test Match 109',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => now()->subDay()->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->md->id,
        'active_member_fee' => 300,
        'non_member_fee' => 300,
        'lapsed_member_fee' => 300,
    ]);
});

function makeUnpaidRegistration(int $matchId, int $userId, array $overrides = []): MatchRegistration
{
    return MatchRegistration::create(array_merge([
        'match_id' => $matchId,
        'user_id' => $userId,
        'shooter_name' => 'Grant Bower',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 300,
        'saprf_fee' => 15,
        'platform_fee' => 15,
        'surcharge_amount' => 0,
        'gateway_fee' => 10,
        'md_net_amount' => 260,
        'payment_status' => 'pending',
        'registration_status' => 'pending',
        'registered_at' => now()->subWeek(),
    ], $overrides));
}

it('lets the match director send a payment inquiry email to an unpaid shooter', function () {
    Notification::fake();

    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id);

    $response = $this->actingAs($this->md)
        ->post(route('registrations.payment-inquiry', $registration));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    Notification::assertSentTo(
        $this->shooter,
        PaymentInquiryNotification::class
    );

    $registration->refresh();
    expect($registration->payment_inquiry_sent_at)->not()->toBeNull();
});

it('lets admins send an inquiry on any match, not only ones they created', function () {
    Notification::fake();

    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id);

    $this->actingAs($this->admin)
        ->post(route('registrations.payment-inquiry', $registration))
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentTo($this->shooter, PaymentInquiryNotification::class);
});

it('forbids an unrelated match director from sending an inquiry', function () {
    Notification::fake();

    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id);

    $this->actingAs($this->otherMd)
        ->post(route('registrations.payment-inquiry', $registration))
        ->assertForbidden();

    Notification::assertNothingSent();
});

it('forbids a plain member from triggering an inquiry against another shooter', function () {
    Notification::fake();

    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id);

    $someoneElse = User::factory()->create();
    $someoneElse->assignRole('member');

    $this->actingAs($someoneElse)
        ->post(route('registrations.payment-inquiry', $registration))
        ->assertForbidden();

    Notification::assertNothingSent();
});

it('refuses to send an inquiry if the entry is already paid', function () {
    Notification::fake();

    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id, [
        'payment_status' => 'paid',
    ]);

    $this->actingAs($this->md)
        ->post(route('registrations.payment-inquiry', $registration))
        ->assertRedirect();

    Notification::assertNothingSent();
});

it('refuses to send an inquiry if the entry fee is zero', function () {
    Notification::fake();

    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id, [
        'fee_amount' => 0,
    ]);

    $this->actingAs($this->md)
        ->post(route('registrations.payment-inquiry', $registration))
        ->assertRedirect();

    Notification::assertNothingSent();
});

it('dedupes: refuses to re-send an inquiry within 24 hours', function () {
    Notification::fake();

    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id, [
        'payment_inquiry_sent_at' => now()->subHours(3),
    ]);

    $this->actingAs($this->md)
        ->post(route('registrations.payment-inquiry', $registration))
        ->assertRedirect();

    Notification::assertNothingSent();
});

it('allows re-sending an inquiry after 24 hours', function () {
    Notification::fake();

    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id, [
        'payment_inquiry_sent_at' => now()->subHours(25),
    ]);

    $this->actingAs($this->md)
        ->post(route('registrations.payment-inquiry', $registration))
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentTo($this->shooter, PaymentInquiryNotification::class);
});

it('renders the signed old-site payment confirmation landing page', function () {
    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id);

    $url = URL::temporarySignedRoute(
        'registrations.confirm-old-site-payment.show',
        now()->addDays(30),
        ['registration' => $registration->id],
    );

    $response = $this->get($url);

    $response->assertOk();
    $response->assertSee('Confirm your legacy payment');
    $response->assertSee($this->match->name);
    $response->assertSee('R 300.00');
    // Both a Confirm submit button and a Cancel link should appear.
    $response->assertSee('Yes, I paid on the old site');
});

it('rejects the confirmation landing page without a valid signature', function () {
    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id);

    $this->get(route('registrations.confirm-old-site-payment.show', $registration))
        ->assertForbidden();
});

it('flips the row to waived + confirmed when the shooter POSTs a signed confirm', function () {
    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id);

    $url = URL::temporarySignedRoute(
        'registrations.confirm-old-site-payment.apply',
        now()->addDays(30),
        ['registration' => $registration->id],
    );

    $response = $this->post($url);

    $response->assertRedirect(route('registrations.confirm-old-site-payment.done', [
        'registration' => $registration->id,
    ]));

    $registration->refresh();
    expect($registration->payment_status)->toBe('waived')
        ->and($registration->registration_status)->toBe('confirmed')
        ->and($registration->fee_override_reason)->toContain('legacy SAPRF site');
});

it('is idempotent: revisiting the confirm URL after settlement redirects to the done page', function () {
    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id, [
        'payment_status' => 'waived',
    ]);

    $url = URL::temporarySignedRoute(
        'registrations.confirm-old-site-payment.show',
        now()->addDays(30),
        ['registration' => $registration->id],
    );

    $this->get($url)->assertRedirect(route('registrations.confirm-old-site-payment.done', [
        'registration' => $registration->id,
    ]));
});

it('rejects the confirm POST without a valid signature', function () {
    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id);

    $this->post(route('registrations.confirm-old-site-payment.apply', $registration))
        ->assertForbidden();

    $registration->refresh();
    expect($registration->payment_status)->toBe('pending');
});

it('shows the send inquiry button on the private registrations list to the MD', function () {
    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id);

    $this->actingAs($this->md)
        ->get(route('registrations.index', ['match_id' => $this->match->id]))
        ->assertOk()
        ->assertSee('Send payment inquiry email', false);
});

it('hides the send inquiry button from public visitors', function () {
    $registration = makeUnpaidRegistration($this->match->id, $this->shooter->id);

    // Unauthenticated request to the same route falls through the auth
    // middleware to login — the entry list is auth-gated.
    $this->get(route('registrations.index', ['match_id' => $this->match->id]))
        ->assertRedirect();

    // A regular signed-in member (viewing the public entry list) sees no
    // financials column and no send-inquiry action.
    $regular = User::factory()->create();
    $regular->assignRole('member');

    $this->actingAs($regular)
        ->get(route('registrations.index', ['match_id' => $this->match->id]))
        ->assertOk()
        ->assertDontSee('Send payment inquiry email', false);
});
