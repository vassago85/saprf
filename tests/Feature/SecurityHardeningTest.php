<?php

use App\Models\Membership;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;

beforeEach(function () {
    seedRoles();
    RateLimiter::clear('login:member@example.com|127.0.0.1');
});

/**
 * A pending membership payment belonging to $owner.
 */
function pendingPaymentFor(User $owner, string $reference): Payment
{
    $membership = Membership::create([
        'user_id' => $owner->id,
        'saprf_number' => 'SEC-'.$owner->id,
        'membership_type' => 'paid',
        'status' => 'pending',
        'payment_status' => 'unpaid',
    ]);

    return Payment::create([
        'payable_type' => Membership::class,
        'payable_id' => $membership->id,
        'user_id' => $owner->id,
        'amount' => 550.00,
        'm_payment_id' => $reference,
        'status' => 'pending',
    ]);
}

// ── Login throttling ──────────────────────────────────────────────────────

it('locks out login after five failed attempts from the same email and IP', function () {
    User::factory()->create(['email' => 'member@example.com']);

    $component = Volt::test('pages.auth.login')
        ->set('email', 'member@example.com')
        ->set('password', 'wrong-password');

    foreach (range(1, 5) as $ignored) {
        $component->call('login')->assertHasErrors('email');
    }

    $component->call('login');

    expect($component->errors()->first('email'))->toContain('Too many login attempts');
});

it('clears the throttle counter once the member signs in', function () {
    $user = User::factory()->create(['email' => 'member@example.com']);

    Volt::test('pages.auth.login')
        ->set('email', 'member@example.com')
        ->set('password', 'wrong-password')
        ->call('login')
        ->set('password', 'password')
        ->call('login');

    expect(auth()->id())->toBe($user->id)
        ->and(RateLimiter::attempts('login:member@example.com|127.0.0.1'))->toBe(0);
});

// ── Post-login redirect intent ────────────────────────────────────────────

it('returns the member to the page they were gated out of', function () {
    User::factory()->create(['email' => 'member@example.com']);

    Volt::test('pages.auth.login')
        ->set('intended', url('/events/7/register'))
        ->set('email', 'member@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/events/7/register');
});

it('ignores an off-site redirect target and falls back to the dashboard', function () {
    User::factory()->create(['email' => 'member@example.com']);

    Volt::test('pages.auth.login')
        ->set('intended', 'https://evil.example.com/harvest')
        ->set('email', 'member@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('dashboard'));
});

it('rejects a protocol-relative redirect target', function () {
    User::factory()->create(['email' => 'member@example.com']);

    Volt::test('pages.auth.login')
        ->set('intended', '//evil.example.com/harvest')
        ->set('email', 'member@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('dashboard'));
});

// ── Payment ownership ─────────────────────────────────────────────────────

it('refuses to show another member\'s payment redirect page', function () {
    $owner = User::factory()->create();
    $owner->assignRole('member');
    $stranger = User::factory()->create();
    $stranger->assignRole('member');

    $payment = pendingPaymentFor($owner, 'PAY-OWNER-ONLY');

    $this->actingAs($stranger)
        ->get(route('payments.redirect', $payment))
        ->assertForbidden();
});

it('will not let a stranger cancel a payment through the gateway landing page', function () {
    $owner = User::factory()->create();
    $owner->assignRole('member');
    $stranger = User::factory()->create();
    $stranger->assignRole('member');

    $payment = pendingPaymentFor($owner, 'PAY-NO-STRANGER-CANCEL');

    $this->actingAs($stranger)
        ->get(route('payments.cancel', ['m_payment_id' => 'PAY-NO-STRANGER-CANCEL']))
        ->assertOk();

    expect($payment->fresh()->status)->toBe('pending');
});

it('still lets the owner cancel their own payment', function () {
    $owner = User::factory()->create();
    $owner->assignRole('member');

    $payment = pendingPaymentFor($owner, 'PAY-OWNER-CANCEL');

    $this->actingAs($owner)
        ->get(route('payments.cancel', ['m_payment_id' => 'PAY-OWNER-CANCEL']))
        ->assertOk();

    expect($payment->fresh()->status)->toBe('cancelled');
});

// ── Layout / accessibility scaffolding ────────────────────────────────────

it('gives the guest and public layouts a skip link into a main landmark', function () {
    foreach (['/', route('login'), route('register')] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee('Skip to main content')
            ->assertSee('id="main"', escape: false);
    }
});

// ── Response headers ──────────────────────────────────────────────────────

it('sends the hardening headers on public responses', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain('payfast.co.za');
});

it('allows form submissions to every Payfast host in the checkout redirect chain', function () {
    // The initial POST goes to www.payfast.co.za or sandbox.payfast.co.za,
    // but Payfast immediately 302s to w1w/w2w — Chrome enforces `form-action`
    // across the whole chain, so every hop must be allow-listed.
    $csp = $this->get('/')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain('https://www.payfast.co.za')
        ->toContain('https://sandbox.payfast.co.za')
        ->toContain('https://w1w.payfast.co.za')
        ->toContain('https://w2w.payfast.co.za');
});

// ── Mass assignment ───────────────────────────────────────────────────────

it('refuses to mass-assign credential columns on a user', function () {
    $user = User::factory()->create();

    $user->fill([
        'email_otp' => '999999',
        'handover_token' => 'attacker-token',
        'invitation_token' => 'attacker-token',
    ]);

    expect($user->email_otp)->toBeNull()
        ->and($user->handover_token)->toBeNull()
        ->and($user->invitation_token)->toBeNull();
});

it('keeps identity numbers and tokens out of serialised output', function () {
    $user = User::factory()->create(['sa_id_number' => '9001015800085']);
    $user->generateEmailOtp();

    expect(array_keys($user->fresh()->toArray()))
        ->not->toContain('sa_id_number')
        ->not->toContain('passport_number')
        ->not->toContain('email_otp')
        ->not->toContain('handover_token')
        ->not->toContain('invitation_token');
});
