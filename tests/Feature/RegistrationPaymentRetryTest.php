<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\PayFastService;
use App\Services\SettingsService;
use Carbon\Carbon;

/**
 * Failing card payments used to leave the member with no way to retry.
 * `MatchController::storeRegistration` created a single `Payment` at
 * registration time; if the member cancelled or the card was declined the
 * row moved to `cancelled`/`failed` and the Pay Now button disappeared
 * (the old blade only rendered a link when a `pending` payment still
 * existed). The only remaining action was Withdraw, which in turn quoted
 * a refund calculated purely from `fee_amount` — even though no money had
 * ever reached PayFast.
 *
 * These tests lock in the fix: a dedicated `payments.registration`
 * endpoint that reuses an existing pending payment or creates a fresh
 * one, and a `calculateRefund()` that returns zero for unpaid entries.
 */
beforeEach(function () {
    seedRoles();

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->match = MatchEvent::create([
        'name' => 'Retry Payment Test Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 1100.00,
        'non_member_fee' => 1300.00,
        'lapsed_member_fee' => 1200.00,
        'created_by' => User::factory()->create()->id,
    ]);

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    $this->member->assignRole('member');
});

function stubPayFastEnabled(bool $enabled = true): void
{
    $stub = new class($enabled) extends PayFastService {
        public function __construct(private readonly bool $enabled)
        {
            parent::__construct(app(SettingsService::class));
        }

        public function isEnabled(): bool
        {
            return $this->enabled;
        }
    };

    app()->instance(PayFastService::class, $stub);
}

function makeRegistration(User $user, MatchEvent $match, array $overrides = []): MatchRegistration
{
    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'email' => $user->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1100.00,
        'payment_status' => 'pending',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ], $overrides));
}

// ── PayRegistration endpoint ──

it('creates a fresh payment when the previous attempt was cancelled or failed', function () {
    stubPayFastEnabled();

    $registration = makeRegistration($this->member, $this->match);

    // The initial checkout attempt: PayFast cancel URL fired.
    $failedPayment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->member->id,
        'amount' => 1100.00,
        'm_payment_id' => 'REG-OLD-CANCELLED',
        'status' => 'cancelled',
    ]);

    $response = $this->actingAs($this->member)
        ->post(route('payments.registration', $registration));

    $latest = Payment::where('payable_id', $registration->id)->latest('id')->first();

    expect($latest->id)->not->toBe($failedPayment->id)
        ->and($latest->status)->toBe('pending')
        ->and($latest->m_payment_id)->toStartWith('REG-')
        ->and((float) $latest->amount)->toBe(1100.00);

    $response->assertRedirect(route('payments.redirect', $latest));
});

it('reuses the still-open pending payment instead of creating a duplicate', function () {
    stubPayFastEnabled();

    $registration = makeRegistration($this->member, $this->match);

    $pending = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->member->id,
        'amount' => 1100.00,
        'm_payment_id' => 'REG-STILL-PENDING',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($this->member)
        ->post(route('payments.registration', $registration));

    $response->assertRedirect(route('payments.redirect', $pending));

    expect(Payment::where('payable_id', $registration->id)->count())->toBe(1);
});

it('rejects a paid registration', function () {
    stubPayFastEnabled();

    $registration = makeRegistration($this->member, $this->match, [
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
    ]);

    $this->actingAs($this->member)
        ->post(route('payments.registration', $registration))
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHas('info');

    expect(Payment::where('payable_id', $registration->id)->count())->toBe(0);
});

it('rejects a cancelled registration', function () {
    stubPayFastEnabled();

    $registration = makeRegistration($this->member, $this->match, [
        'registration_status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    $this->actingAs($this->member)
        ->post(route('payments.registration', $registration))
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHas('error');

    expect(Payment::where('payable_id', $registration->id)->count())->toBe(0);
});

it('lets any member sponsor another member\'s unpaid registration', function () {
    stubPayFastEnabled();

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    $shooter->assignRole('member');

    $registration = makeRegistration($shooter, $this->match);

    $response = $this->actingAs($this->member)
        ->post(route('payments.registration', $registration));

    // Sponsor gets their own fresh payment row, not the shooter's pending one.
    $payment = Payment::where('payable_id', $registration->id)
        ->where('user_id', $this->member->id)
        ->firstOrFail();

    $response->assertRedirect(route('payments.redirect', $payment));
});

it('does not saddle a sponsor with someone else\'s pending payment reference', function () {
    stubPayFastEnabled();

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    $shooter->assignRole('member');

    $registration = makeRegistration($shooter, $this->match);

    // Shooter has a pending payment sitting around; a sponsor coming in must
    // not inherit it — PayFast's m_payment_id is single-use and the receipt
    // would be attributed to the wrong account.
    $shooterPayment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $shooter->id,
        'amount' => 1100.00,
        'm_payment_id' => 'REG-SHOOTER-PENDING',
        'status' => 'pending',
    ]);

    $this->actingAs($this->member)
        ->post(route('payments.registration', $registration))
        ->assertRedirect();

    $sponsorPayment = Payment::where('payable_id', $registration->id)
        ->where('user_id', $this->member->id)
        ->firstOrFail();

    expect($sponsorPayment->id)->not->toBe($shooterPayment->id)
        ->and($sponsorPayment->m_payment_id)->not->toBe('REG-SHOOTER-PENDING');
});

it('lets an admin pay on behalf of a member', function () {
    stubPayFastEnabled();

    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole('admin');

    $registration = makeRegistration($this->member, $this->match);

    $response = $this->actingAs($admin)
        ->post(route('payments.registration', $registration));

    $payment = Payment::where('payable_id', $registration->id)->firstOrFail();
    $response->assertRedirect(route('payments.redirect', $payment));
});

it('surfaces a friendly message when PayFast is switched off', function () {
    stubPayFastEnabled(false);

    $registration = makeRegistration($this->member, $this->match);

    $this->actingAs($this->member)
        ->from(route('registrations.show', $registration))
        ->post(route('payments.registration', $registration))
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHas('error');

    expect(Payment::where('payable_id', $registration->id)->count())->toBe(0);
});

// ── calculateRefund on unpaid registrations ──

it('quotes no refund and no admin fee for an unpaid withdrawal before deadline', function () {
    Setting::updateOrCreate(['key' => 'withdrawal_admin_fee'], ['value' => '100', 'description' => 'admin fee']);
    app(SettingsService::class)->clearCache();

    $registration = makeRegistration($this->member, $this->match, [
        'payment_status' => 'pending',
    ]);

    $calc = $registration->calculateRefund();

    expect($calc['reason'])->toBe('unpaid')
        ->and($calc['refund'])->toBe(0)
        ->and($calc['admin_fee'])->toBe(0);
});

it('quotes no refund for a registration whose payment failed', function () {
    $registration = makeRegistration($this->member, $this->match, [
        'payment_status' => 'unpaid',
    ]);

    expect($registration->calculateRefund()['reason'])->toBe('unpaid');
});

it('still applies the admin-fee split when the entry was actually paid', function () {
    Setting::updateOrCreate(['key' => 'withdrawal_admin_fee'], ['value' => '100', 'description' => 'admin fee']);
    Setting::updateOrCreate(['key' => 'withdrawal_deadline_hours'], ['value' => '72', 'description' => 'deadline hours']);
    app(SettingsService::class)->clearCache();

    $registration = makeRegistration($this->member, $this->match, [
        'payment_status' => 'paid',
    ]);

    $calc = $registration->calculateRefund();

    expect($calc['reason'])->toBe('before_deadline')
        ->and((float) $calc['refund'])->toBe(1000.0)
        ->and((float) $calc['admin_fee'])->toBe(100.0);
});

it('keeps the free-entry short-circuit ahead of the unpaid check', function () {
    $registration = makeRegistration($this->member, $this->match, [
        'fee_amount' => 0,
        'payment_status' => 'pending',
    ]);

    expect($registration->calculateRefund()['reason'])->toBe('free_entry');
});
