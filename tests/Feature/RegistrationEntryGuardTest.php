<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\RifleConfiguration;
use App\Models\User;

beforeEach(function () {
    seedRoles();

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->shooter = User::factory()->create();
    $this->shooter->assignRole('member');

    $this->other = User::factory()->create();
    $this->other->assignRole('member');

    $this->director = User::factory()->create();
    $this->director->assignRole('match_director');

    $this->otherDirector = User::factory()->create();
    $this->otherDirector->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Rifle Guard Match',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'club',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => now()->addWeek()->toDateString(),
        'status' => 'open',
        'published' => true,
        'created_by' => $this->director->id,
        'active_member_fee' => 100,
        'non_member_fee' => 150,
        'lapsed_member_fee' => 120,
    ]);

    $this->registration = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->shooter->id,
        'shooter_name' => $this->shooter->name,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 100,
        'saprf_fee' => 0,
        'platform_fee' => 0,
        'surcharge_amount' => 0,
        'gateway_fee' => 0,
        'md_net_amount' => 100,
        'payment_status' => 'unpaid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->ownRifle = RifleConfiguration::create([
        'user_id' => $this->shooter->id,
        'nickname' => 'Own rifle',
        'total_barrel_rounds' => 10,
    ]);

    $this->otherRifle = RifleConfiguration::create([
        'user_id' => $this->other->id,
        'nickname' => 'Someone else',
        'total_barrel_rounds' => 40,
    ]);
});

it('refuses to attach another members rifle to an entry', function () {
    $this->actingAs($this->shooter)
        ->put(route('registrations.update-rifle', $this->registration), [
            'rifle_configuration_id' => $this->otherRifle->id,
        ])
        ->assertSessionHasErrors('rifle_configuration_id');

    expect($this->registration->fresh()->rifle_configuration_id)->toBeNull()
        ->and((int) $this->otherRifle->fresh()->total_barrel_rounds)->toBe(40);
});

it('lets the shooter attach their own rifle and recount only that rifle', function () {
    $this->actingAs($this->shooter)
        ->put(route('registrations.update-rifle', $this->registration), [
            'rifle_configuration_id' => $this->ownRifle->id,
        ])
        ->assertRedirect();

    $this->actingAs($this->shooter)
        ->put(route('registrations.update-shots', $this->registration), [
            'shot_count' => 25,
        ])
        ->assertRedirect();

    expect($this->registration->fresh()->rifle_configuration_id)->toBe($this->ownRifle->id)
        ->and((int) $this->ownRifle->fresh()->total_barrel_rounds)->toBe(25)
        ->and((int) $this->otherRifle->fresh()->total_barrel_rounds)->toBe(40);
});

it('stops a match director from editing an entry on someone elses match', function () {
    $this->actingAs($this->otherDirector)
        ->put(route('registrations.update-shots', $this->registration), [
            'shot_count' => 9,
        ])
        ->assertForbidden();

    $this->actingAs($this->otherDirector)
        ->put(route('registrations.update-division', $this->registration), [
            'division_id' => 1,
        ])
        ->assertForbidden();

    expect($this->registration->fresh()->shot_count)->toBeNull();
});

it('lets the match director who owns the match set the shot count', function () {
    $this->actingAs($this->director)
        ->put(route('registrations.update-shots', $this->registration), [
            'shot_count' => 12,
        ])
        ->assertRedirect();

    expect((int) $this->registration->fresh()->shot_count)->toBe(12);
});
