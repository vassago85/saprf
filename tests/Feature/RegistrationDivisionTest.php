<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->division = Division::create([
        'slug' => 'open',
        'name' => 'Open',
        'is_active' => true,
        'display_order' => 1,
    ]);

    $this->match = MatchEvent::create([
        'name' => 'Division Test Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'created_by' => User::factory()->create()->id,
    ]);
});

it('requires a division when registering for a match', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    $this->actingAs($user)
        ->post(route('events.register.store', $this->match), ['notes' => 'no division'])
        ->assertSessionHasErrors('division_id');

    expect(MatchRegistration::where('user_id', $user->id)->count())->toBe(0);
});

it('rejects a division that is not offered for the match', function () {
    $otherActiveDivision = Division::create(['slug' => 'tac', 'name' => 'Tactical', 'is_active' => true, 'display_order' => 2]);
    // Restrict the match to only the Open division.
    $this->match->divisions()->attach($this->division->id);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    $this->actingAs($user)
        ->post(route('events.register.store', $this->match), ['division_id' => $otherActiveDivision->id])
        ->assertSessionHasErrors('division_id');

    expect(MatchRegistration::where('user_id', $user->id)->count())->toBe(0);
});

it('saves the chosen division on registration', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    $this->actingAs($user)
        ->post(route('events.register.store', $this->match), ['division_id' => $this->division->id]);

    $registration = MatchRegistration::where('user_id', $user->id)->first();

    expect($registration)->not->toBeNull()
        ->and($registration->division_id)->toBe($this->division->id);
});

it('shows the division on the public event entry list', function () {
    $shooter = User::factory()->create(['name' => 'Jane Marksman']);
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $shooter->id,
        'division_id' => $this->division->id,
        'shooter_name' => 'Jane Marksman',
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 0,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->get(route('events.show', $this->match))
        ->assertOk()
        ->assertSee('Jane Marksman')
        ->assertSee('Open');
});
