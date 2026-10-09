<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

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

it('refuses to let an MD cancel an entry paid online', function () {
    $registration = compedEntry($this->match, $this->shooter, $this->shooter);
    Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->shooter->id,
        'amount' => 500.00,
        'm_payment_id' => 'REG-MD-PAID-1',
        'status' => 'completed',
    ]);

    $this->actingAs($this->md)
        ->from(route('registrations.show', $registration))
        ->put(route('registrations.update-status', $registration), ['registration_status' => 'cancelled'])
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHas('error');

    expect($registration->fresh()->registration_status)->toBe('confirmed');
});

it('still lets an admin cancel an entry paid online', function () {
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole('admin');
    $registration = compedEntry($this->match, $this->shooter, $this->shooter);
    Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->shooter->id,
        'amount' => 500.00,
        'm_payment_id' => 'REG-ADMIN-PAID-1',
        'status' => 'completed',
    ]);

    $this->actingAs($admin)
        ->put(route('registrations.update-status', $registration), ['registration_status' => 'cancelled'])
        ->assertSessionHas('success');

    expect($registration->fresh()->registration_status)->toBe('cancelled');
});
