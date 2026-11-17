<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\RifleConfiguration;
use App\Models\User;
use App\Notifications\EntryPaymentRequiredNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();

    $this->division = Division::create([
        'slug' => 'open',
        'name' => 'Open',
        'is_active' => true,
        'display_order' => 1,
    ]);

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole('admin');

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    $this->member->assignRole('member');

    $this->otherDirector = User::factory()->create(['email_verified_at' => now()]);
    $this->otherDirector->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Closed Entry Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'match_date' => Carbon::today()->addWeek(),
        'registration_close_date' => Carbon::yesterday()->toDateString(),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Test Director',
        'active_member_fee' => 550,
        'non_member_fee' => 750,
        'created_by' => $this->admin->id,
    ]);
});

it('lets staff enter a shooter after registration closes and leaves the fee unpaid', function () {
    $this->actingAs($this->admin)
        ->post(route('events.register.store', $this->match), [
            'division_id' => $this->division->id,
        ])
        ->assertRedirect();

    $registration = MatchRegistration::where('user_id', $this->admin->id)->first();

    expect($registration)->not->toBeNull()
        ->and($registration->payment_status)->toBe('pending')
        ->and($registration->payment_status)->not->toBe('paid')
        ->and((float) $registration->fee_amount)->toBeGreaterThan(0);
});

it('still refuses a member and a match director of another match after registration closes', function () {
    $this->actingAs($this->member)
        ->from(route('events.show', $this->match))
        ->post(route('events.register.store', $this->match), [
            'division_id' => $this->division->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->actingAs($this->otherDirector)
        ->post(route('events.register.store', $this->match), [
            'division_id' => $this->division->id,
        ])
        ->assertSessionHas('error');

    expect(MatchRegistration::query()->count())->toBe(0);
});

it('emails a late shooter that payment is required and lets them choose a division before paying', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->member->id,
            'division_id' => $this->division->id,
            'collection' => 'due',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $registration = MatchRegistration::where('user_id', $this->member->id)->firstOrFail();

    expect($registration->division_id)->toBeNull()
        ->and($registration->payment_status)->toBe('pending')
        ->and((float) $registration->fee_amount)->toBeGreaterThan(0);

    Notification::assertSentTo($this->member, EntryPaymentRequiredNotification::class, function (EntryPaymentRequiredNotification $notification) {
        $mail = $notification->toMail($this->member);
        $body = implode("\n", $mail->introLines);

        return str_contains($mail->subject, 'Payment still required')
            && str_contains($body, 'Choose your division and rifle');
    });

    $this->actingAs($this->member)
        ->from(route('registrations.show', $registration))
        ->post(route('payments.registration', $registration))
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHas('error');

    $this->actingAs($this->member)
        ->put(route('registrations.update-division', $registration), [
            'division_id' => $this->division->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($registration->fresh()->division_id)->toBe($this->division->id)
        ->and($registration->fresh()->payment_status)->toBe('pending');
});

it('lets the shooter set a rifle and a junior division, then reach payment', function () {
    $junior = Division::create([
        'slug' => 'junior',
        'name' => 'Junior',
        'is_active' => true,
        'display_order' => 2,
    ]);
    $this->match->update(['junior_fee' => 100]);

    $registration = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->member->id,
        'shooter_name' => $this->member->name,
        'email' => $this->member->email,
        'membership_fee_category' => 'non_member',
        'fee_amount' => 750,
        'payment_status' => 'pending',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ]);

    $rifle = RifleConfiguration::create([
        'user_id' => $this->member->id,
        'nickname' => 'Late rifle',
        'is_active' => true,
    ]);

    $this->actingAs($this->member)
        ->get(route('registrations.show', $registration))
        ->assertOk()
        ->assertSee('Choose your division')
        ->assertSee('Choose your rifle')
        ->assertSee('Payment still required')
        ->assertDontSee('Pay Now');

    $this->actingAs($this->member)
        ->put(route('registrations.update-rifle', $registration), [
            'rifle_configuration_id' => $rifle->id,
        ])
        ->assertSessionHas('success');

    $this->actingAs($this->member)
        ->put(route('registrations.update-division', $registration), [
            'division_id' => $junior->id,
        ])
        ->assertSessionHas('success');

    $fresh = $registration->fresh();

    expect($fresh->rifle_configuration_id)->toBe($rifle->id)
        ->and($fresh->division_id)->toBe($junior->id)
        ->and((float) $fresh->fee_amount)->toBeLessThan(750)
        ->and($fresh->payment_status)->toBe('pending');

    $this->actingAs($this->member)
        ->from(route('registrations.show', $registration))
        ->post(route('payments.registration', $registration))
        ->assertRedirect()
        ->assertSessionHas('error', 'Online payments are not currently available.');
});

it('does not let a shooter change a division that was already chosen once entries are closed', function () {
    $other = Division::create([
        'slug' => 'factory',
        'name' => 'Factory',
        'is_active' => true,
        'display_order' => 3,
    ]);

    $registration = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->member->id,
        'division_id' => $this->division->id,
        'shooter_name' => $this->member->name,
        'email' => $this->member->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 550,
        'payment_status' => 'pending',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ]);

    $this->actingAs($this->member)
        ->put(route('registrations.update-division', $registration), [
            'division_id' => $other->id,
        ])
        ->assertSessionHas('error');

    expect($registration->fresh()->division_id)->toBe($this->division->id);
});

it('keeps the division when staff add themselves and still tells them to pay', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->admin->id,
            'division_id' => $this->division->id,
            'collection' => 'due',
        ])
        ->assertSessionHas('success');

    $registration = MatchRegistration::where('user_id', $this->admin->id)->firstOrFail();

    expect($registration->division_id)->toBe($this->division->id)
        ->and($registration->payment_status)->toBe('pending')
        ->and($registration->registration_status)->toBe('pending');

    Notification::assertSentTo($this->admin, EntryPaymentRequiredNotification::class, function (EntryPaymentRequiredNotification $notification) {
        $mail = $notification->toMail($this->admin);
        $body = implode("\n", $mail->introLines);

        return str_contains($body, 'still outstanding')
            && str_contains($body, 'Open')
            && ! str_contains($body, 'Choose your division and rifle');
    });
});

it('requires a division when the entry is marked paid, and skips mail for a placeholder address', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->from(route('matches.entries', $this->match))
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->member->id,
            'collection' => 'paid',
        ])
        ->assertSessionHasErrors('division_id');

    expect(MatchRegistration::query()->count())->toBe(0);

    $this->member->update(['email' => 'late.shooter@import.saprf.local']);

    $this->actingAs($this->admin)
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->member->id,
            'collection' => 'due',
        ])
        ->assertSessionHas('success');

    Notification::assertNothingSent();

    $registration = MatchRegistration::where('user_id', $this->member->id)->firstOrFail();

    expect($registration->division_id)->toBeNull()
        ->and($registration->payment_status)->toBe('pending');
});

it('lets the match director of this match enter after close and refuses a finished or draft match', function () {
    $this->match->update(['created_by' => $this->otherDirector->id]);

    $this->actingAs($this->otherDirector)
        ->post(route('events.register.store', $this->match), [
            'division_id' => $this->division->id,
        ])
        ->assertRedirect();

    expect(MatchRegistration::where('user_id', $this->otherDirector->id)->value('payment_status'))->toBe('pending');

    MatchRegistration::query()->delete();

    foreach (['completed', 'cancelled', 'draft'] as $status) {
        $this->match->update(['status' => $status, 'published' => true]);

        $this->actingAs($this->admin)
            ->post(route('events.register.store', $this->match), [
                'division_id' => $this->division->id,
            ])
            ->assertSessionHas('error');
    }

    expect(MatchRegistration::query()->count())->toBe(0);
});

it('still lets a member register while entries are open', function () {
    $open = MatchEvent::create([
        'name' => 'Still Open Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'match_date' => Carbon::today()->addMonth(),
        'registration_close_date' => Carbon::tomorrow()->toDateString(),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Test Director',
        'active_member_fee' => 550,
        'non_member_fee' => 750,
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->member)
        ->post(route('events.register.store', $open), [
            'division_id' => $this->division->id,
        ])
        ->assertRedirect();

    $registration = MatchRegistration::where('match_id', $open->id)->where('user_id', $this->member->id)->first();

    expect($registration)->not->toBeNull()
        ->and($registration->division_id)->toBe($this->division->id)
        ->and($registration->payment_status)->not->toBe('paid');
});

it('sends a closed member back from the register form and lets staff open it', function () {
    $this->actingAs($this->member)
        ->get(route('events.register', $this->match))
        ->assertRedirect(route('events.show', $this->match))
        ->assertSessionHas('error');

    $this->actingAs($this->admin)
        ->get(route('events.register', $this->match))
        ->assertOk();

    $this->actingAs($this->admin)
        ->get(route('matches.entries', $this->match))
        ->assertOk()
        ->assertSee('payment is still required')
        ->assertSee('Add — they must pay');
});

it('shows staff a late-entry link and keeps the closed label for members', function () {
    $this->actingAs($this->admin)
        ->get(route('events.show', $this->match))
        ->assertOk()
        ->assertSee('Enter a shooter — fee still due')
        ->assertSee('Registration Closed');

    $this->actingAs($this->member)
        ->get(route('events.show', $this->match))
        ->assertOk()
        ->assertDontSee('Enter a shooter — fee still due')
        ->assertSee('Registration Closed');
});
