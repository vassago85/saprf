<?php

use App\Models\AccountCredit;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Province;
use App\Models\User;
use App\Notifications\EntryRemovedCreditNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
    Notification::fake();

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'MD Removal Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'active_member_fee' => 500.00,
        'non_member_fee' => 750.00,
        'created_by' => $this->md->id,
    ]);

    $this->shooter = User::factory()->create(['email_verified_at' => now()]);
    $this->shooter->assignRole('member');
});

function compedEntry(MatchEvent $match, User $shooter, User $addedBy): MatchRegistration
{
    return MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'registered_by_user_id' => $addedBy->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500.00,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);
}

it('lets an MD remove a comped shooter from their own match', function () {
    $registration = compedEntry($this->match, $this->shooter, $this->md);

    $this->actingAs($this->md)
        ->put(route('registrations.update-status', $registration), ['registration_status' => 'cancelled'])
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHas('success');

    $registration->refresh();
    expect($registration->registration_status)->toBe('cancelled')
        ->and($registration->cancelled_at)->not->toBeNull();
});

it('blocks an MD from changing entries on a match they do not own', function () {
    $otherMd = User::factory()->create(['email_verified_at' => now()]);
    $otherMd->assignRole('match_director');
    $registration = compedEntry($this->match, $this->shooter, $this->md);

    $this->actingAs($otherMd)
        ->put(route('registrations.update-status', $registration), ['registration_status' => 'cancelled'])
        ->assertForbidden();

    expect($registration->fresh()->registration_status)->toBe('confirmed');
});

it('does not credit a comped shooter when the MD removes them', function () {
    $registration = compedEntry($this->match, $this->shooter, $this->md);

    $this->actingAs($this->md)
        ->put(route('registrations.update-status', $registration), ['registration_status' => 'cancelled'])
        ->assertSessionHas('success');

    expect(AccountCredit::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('credits the payer when an MD removes a paid shooter with a reason', function () {
    $parent = User::factory()->create(['email_verified_at' => now()]);
    $registration = compedEntry($this->match, $this->shooter, $parent);
    onlinePayment($registration, $parent, 500.00);

    $this->actingAs($this->md)
        ->put(route('registrations.update-status', $registration), [
            'registration_status' => 'cancelled',
            'cancellation_reason' => 'Shooter can no longer attend.',
        ])
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHas('success');

    $registration->refresh();
    expect($registration->registration_status)->toBe('cancelled')
        ->and($registration->cancellation_reason)->toBe('Shooter can no longer attend.')
        ->and((float) $registration->refund_amount)->toBe(500.0)
        ->and($parent->accountCreditSummary()['posted'])->toBe(500.0)
        ->and($this->shooter->accountCreditSummary()['posted'])->toBe(0.0);

    Notification::assertSentTo($parent, EntryRemovedCreditNotification::class);
});

it('requires a reason before an MD can remove a paid shooter', function () {
    $registration = compedEntry($this->match, $this->shooter, $this->shooter);
    onlinePayment($registration, $this->shooter, 500.00);

    $this->actingAs($this->md)
        ->from(route('registrations.show', $registration))
        ->put(route('registrations.update-status', $registration), ['registration_status' => 'cancelled'])
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHasErrors('cancellation_reason');

    expect($registration->fresh()->registration_status)->toBe('confirmed')
        ->and(AccountCredit::query()->count())->toBe(0);
});

it('credits both the card and redeemed-credit parts of a split payment', function () {
    $registration = compedEntry($this->match, $this->shooter, $this->shooter);
    $payment = onlinePayment($registration, $this->shooter, 300.00);
    AccountCredit::create([
        'user_id' => $this->shooter->id,
        'amount' => -200.00,
        'status' => AccountCredit::STATUS_POSTED,
        'type' => AccountCredit::TYPE_REDEMPTION,
        'match_id' => $this->match->id,
        'applied_to_registration_id' => $registration->id,
        'payment_id' => $payment->id,
    ]);

    $this->actingAs($this->md)
        ->put(route('registrations.update-status', $registration), [
            'registration_status' => 'cancelled',
            'cancellation_reason' => 'Shooter injured.',
        ])
        ->assertSessionHas('success');

    expect((float) AccountCredit::query()->where('issued_for_registration_id', $registration->id)->value('amount'))
        ->toBe(500.0);
});

it('will not reinstate an entry that was already credited', function () {
    $registration = compedEntry($this->match, $this->shooter, $this->shooter);
    onlinePayment($registration, $this->shooter, 500.00);

    $this->actingAs($this->md)->put(route('registrations.update-status', $registration), [
        'registration_status' => 'cancelled',
        'cancellation_reason' => 'Shooter can no longer attend.',
    ]);

    $this->actingAs($this->md)
        ->from(route('registrations.show', $registration))
        ->put(route('registrations.update-status', $registration), ['registration_status' => 'confirmed'])
        ->assertSessionHas('error');

    expect($registration->fresh()->registration_status)->toBe('cancelled')
        ->and(AccountCredit::query()->where('issued_for_registration_id', $registration->id)->count())->toBe(1);
});

function onlinePayment(MatchRegistration $registration, User $payer, float $amount): Payment
{
    return Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $payer->id,
        'amount' => $amount,
        'gateway' => 'payfast',
        'm_payment_id' => Payment::generateReference('REG'),
        'status' => 'completed',
        'paid_at' => now(),
    ]);
}
