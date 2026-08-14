<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->match = MatchEvent::create([
        'name' => 'Centrefire LP 2-Day National',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 1500.00,
        'non_member_fee' => 1700.00,
        'lapsed_member_fee' => 1600.00,
        'created_by' => User::factory()->create()->id,
    ]);
});

function registerShooter(MatchEvent $match, string $name, array $overrides = []): MatchRegistration
{
    $user = User::factory()->create(['name' => $name]);

    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $name,
        'email' => $user->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1500.00,
        'payment_status' => 'unpaid',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ], $overrides));
}

it('shows the public entry list for a match to any member', function () {
    registerShooter($this->match, 'Jane Marksman');

    $viewer = User::factory()->create(['email_verified_at' => now()]);
    $viewer->assignRole('member');

    $this->actingAs($viewer)
        ->get(route('registrations.index', ['match_id' => $this->match->id]))
        ->assertOk()
        ->assertSee('Jane Marksman')
        ->assertSee('Entry List');
});

it('lists registered shooters on the public event page', function () {
    registerShooter($this->match, 'Jane Marksman');
    registerShooter($this->match, 'Withdrawn Wally', ['registration_status' => 'cancelled']);

    $this->get(route('events.show', $this->match))
        ->assertOk()
        ->assertSee('Entry List')
        ->assertSee('Jane Marksman')
        ->assertDontSee('Withdrawn Wally');
});

it('excludes withdrawn entries from the registered count on the event page', function () {
    registerShooter($this->match, 'Active One');
    registerShooter($this->match, 'Active Two');
    registerShooter($this->match, 'Withdrawn One', ['registration_status' => 'cancelled']);

    $response = $this->get(route('events.show', $this->match))->assertOk();

    expect($response->viewData('match')->registrations_count)->toBe(2);
});

it('hides fee and payment details from ordinary members on the entry list', function () {
    registerShooter($this->match, 'Jane Marksman', ['fee_amount' => 1500.00]);

    $viewer = User::factory()->create(['email_verified_at' => now()]);
    $viewer->assignRole('member');

    $this->actingAs($viewer)
        ->get(route('registrations.index', ['match_id' => $this->match->id]))
        ->assertOk()
        ->assertSee('Jane Marksman')
        ->assertDontSee('1,500.00');
});

it('shows fee details to organisers on the entry list', function () {
    registerShooter($this->match, 'Jane Marksman', ['fee_amount' => 1500.00]);

    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('registrations.index', ['match_id' => $this->match->id]))
        ->assertOk()
        ->assertSee('1,500.00');
});

it('excludes cancelled registrations from the entry list', function () {
    registerShooter($this->match, 'Active Shooter');
    registerShooter($this->match, 'Cancelled Shooter', ['registration_status' => 'cancelled']);

    $viewer = User::factory()->create(['email_verified_at' => now()]);
    $viewer->assignRole('member');

    $this->actingAs($viewer)
        ->get(route('registrations.index', ['match_id' => $this->match->id]))
        ->assertOk()
        ->assertSee('Active Shooter')
        ->assertDontSee('Cancelled Shooter');
});

it('only lists registrations for the requested match', function () {
    registerShooter($this->match, 'Correct Match Shooter');

    $otherMatch = MatchEvent::create([
        'name' => 'Other Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->match->province_id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 500.00,
        'non_member_fee' => 700.00,
        'lapsed_member_fee' => 600.00,
        'created_by' => $this->match->created_by,
    ]);
    registerShooter($otherMatch, 'Other Match Shooter');

    $viewer = User::factory()->create(['email_verified_at' => now()]);
    $viewer->assignRole('member');

    $this->actingAs($viewer)
        ->get(route('registrations.index', ['match_id' => $this->match->id]))
        ->assertOk()
        ->assertSee('Correct Match Shooter')
        ->assertDontSee('Other Match Shooter');
});

it('still shows a member only their own registrations when no match is given', function () {
    $mine = User::factory()->create(['email_verified_at' => now()]);
    $mine->assignRole('member');

    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $mine->id,
        'shooter_name' => $mine->name,
        'email' => $mine->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1500.00,
        'payment_status' => 'unpaid',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ]);

    registerShooter($this->match, 'Someone Else');

    $this->actingAs($mine)
        ->get(route('registrations.index'))
        ->assertOk()
        ->assertSee($mine->name)
        ->assertDontSee('Someone Else');
});
