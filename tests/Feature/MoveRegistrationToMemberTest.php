<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\RifleConfiguration;
use App\Models\Score;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->holder = User::factory()->create([
        'name' => 'Natasha Britnell',
        'email' => 'natasha@britplast.co.za',
        'phone' => '0820000001',
    ]);
    $this->holder->assignRole('member');

    $this->target = User::factory()->create([
        'name' => 'Warren Britnell',
        'email' => 'warren@example.com',
        'phone' => '0820000002',
    ]);
    $this->target->assignRole('member');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->match = MatchEvent::create([
        'name' => 'Rimfire PR22 GP 2-Day National',
        'match_type' => 'PR22',
        'series_level' => 'national',
        'series' => 'PR22',
        'season' => '2026',
        'match_date' => Carbon::parse('2026-11-07'),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Eddie Kinnear',
        'active_member_fee' => 1400,
        'created_by' => $this->admin->id,
    ]);

    $this->rifle = RifleConfiguration::create([
        'user_id' => $this->holder->id,
        'nickname' => 'Natasha rifle',
    ]);

    $this->registration = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->holder->id,
        'rifle_configuration_id' => $this->rifle->id,
        'shooter_name' => 'Warren Britnell',
        'email' => $this->holder->email,
        'phone' => $this->holder->phone,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1400,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->payment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $this->registration->id,
        'user_id' => $this->holder->id,
        'amount' => 1400,
        'status' => 'completed',
        'm_payment_id' => 'REG-MOVE-1',
        'paid_at' => now(),
    ]);

    $this->score = Score::create([
        'match_id' => $this->match->id,
        'user_id' => $this->holder->id,
        'shooter_name' => 'Warren Britnell',
        'raw_score' => 42,
        'match_date' => $this->match->match_date,
        'status' => 'valid',
    ]);
});

it('moves an entry onto another member and leaves the payment where it was', function () {
    $this->actingAs($this->admin)
        ->put(route('registrations.move-member', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertRedirect(route('registrations.show', $this->registration))
        ->assertSessionHas('success');

    $registration = $this->registration->fresh();

    expect($registration->user_id)->toBe($this->target->id)
        ->and($registration->shooter_name)->toBe('Warren Britnell')
        ->and($registration->email)->toBe('warren@example.com')
        ->and($registration->phone)->toBe('0820000002')
        ->and($registration->registered_by_user_id)->toBe($this->holder->id)
        ->and($registration->rifle_configuration_id)->toBeNull()
        ->and((float) $registration->fee_amount)->toBe(1400.0)
        ->and($registration->payment_status)->toBe('paid')
        ->and($this->payment->fresh()->user_id)->toBe($this->holder->id)
        ->and($this->score->fresh()->user_id)->toBe($this->target->id)
        ->and($this->score->fresh()->shooter_name)->toBe('Warren Britnell');

    $this->actingAs($this->target)
        ->get(route('registrations.show', $registration))
        ->assertOk();

    $this->actingAs($this->holder)
        ->get(route('registrations.show', $registration))
        ->assertOk();
});

it('keeps an existing entered-by account when the entry is moved', function () {
    $sponsor = User::factory()->create(['name' => 'Sponsor']);
    $this->registration->update(['registered_by_user_id' => $sponsor->id]);

    $this->actingAs($this->admin)
        ->put(route('registrations.move-member', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertRedirect();

    expect($this->registration->fresh()->registered_by_user_id)->toBe($sponsor->id)
        ->and($this->registration->fresh()->user_id)->toBe($this->target->id);
});

it('refuses to move an entry onto a member who is already entered', function () {
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->target->id,
        'shooter_name' => $this->target->name,
        'email' => $this->target->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1400,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->put(route('registrations.move-member', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHasErrors('user_id');

    expect($this->registration->fresh()->user_id)->toBe($this->holder->id);
});

it('refuses a managed family account and an inactive member', function () {
    $this->target->forceFill(['is_managed_account' => true, 'parent_id' => $this->holder->id])->save();

    $this->actingAs($this->admin)
        ->from(route('registrations.show', $this->registration))
        ->put(route('registrations.move-member', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHasErrors('user_id');

    $this->target->forceFill(['is_managed_account' => false, 'parent_id' => null, 'is_active' => false])->save();

    $this->actingAs($this->admin)
        ->put(route('registrations.move-member', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHasErrors('user_id');

    expect($this->registration->fresh()->user_id)->toBe($this->holder->id);
});

it('refuses the member who currently holds the entry', function () {
    $this->actingAs($this->holder)
        ->put(route('registrations.move-member', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertForbidden();

    expect($this->registration->fresh()->user_id)->toBe($this->holder->id);
});

it('shows the move control to staff and hides it from the holder', function () {
    $this->actingAs($this->admin)
        ->get(route('registrations.show', $this->registration))
        ->assertOk()
        ->assertSee('Move this entry')
        ->assertSee('Natasha Britnell');

    $this->actingAs($this->holder)
        ->get(route('registrations.show', $this->registration))
        ->assertOk()
        ->assertDontSee('Move this entry');
});
