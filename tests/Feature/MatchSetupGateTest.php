<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedRoles();

    $this->division = Division::create([
        'slug' => 'open', 'name' => 'Open', 'is_active' => true, 'display_order' => 1,
    ]);

    $this->gateMatch = function (array $overrides = []): MatchEvent {
        return MatchEvent::create(array_merge([
            'name' => 'Gate Match',
            'match_type' => 'PRS',
            'series_level' => 'national',
            'series' => 'PRS',
            'season' => '2026',
            'match_date' => Carbon::today()->addMonth(),
            'status' => 'open',
            'published' => true,
            'match_director' => 'Jane Director',
            'active_member_fee' => 250,
            'created_by' => User::factory()->create()->id,
        ], $overrides));
    };
});

it('is not open for sign-up when the entry fee is not set', function () {
    $match = ($this->gateMatch)(['active_member_fee' => null]);

    expect($match->registration_status)->toBe('setup_incomplete')
        ->and($match->isRegistrationOpen())->toBeFalse()
        ->and($match->hasRequiredSetup())->toBeFalse();
});

it('is not open for sign-up when there is no match director', function () {
    $match = ($this->gateMatch)(['match_director' => null]);

    expect($match->registration_status)->toBe('setup_incomplete')
        ->and($match->isRegistrationOpen())->toBeFalse();
});

it('treats a fully set-up free match (R0) as open for sign-up', function () {
    $match = ($this->gateMatch)(['active_member_fee' => 0]);

    expect($match->registration_status)->toBe('open')
        ->and($match->isRegistrationOpen())->toBeTrue()
        ->and($match->hasRequiredSetup())->toBeTrue();
});

it('redirects away from the registration form for a not-set-up match', function () {
    $match = ($this->gateMatch)(['active_member_fee' => null]);
    $match->divisions()->attach($this->division->id);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    $this->actingAs($user)
        ->get(url('/events/' . $match->id . '/register'))
        ->assertRedirect(route('events.show', $match));
});

it('blocks posting a registration to a not-set-up match', function () {
    $match = ($this->gateMatch)(['match_director' => null]);
    $match->divisions()->attach($this->division->id);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    $this->actingAs($user)
        ->post(route('events.register.store', $match), ['division_id' => $this->division->id])
        ->assertSessionHas('error');

    expect(MatchRegistration::where('user_id', $user->id)->count())->toBe(0);
});
