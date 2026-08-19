<?php

use App\Models\Club;
use App\Models\Membership;
use App\Models\Province;
use App\Models\User;

beforeEach(function () {
    seedRoles();

    $this->gauteng = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $this->club = Club::create([
        'name' => 'Highveld Precision Rifle Club (HPRC)',
        'slug' => 'highveld-precision-rifle-club-hprc',
        'abbreviation' => 'HPRC',
        'province_id' => $this->gauteng->id,
        'saprf_recognised' => true,
    ]);

    $this->member = User::factory()->create([
        'name' => 'Stefan Kruger',
        'email' => 'stefan.louis.kruger@gmail.com',
        'phone' => null,
    ]);
    $this->member->assignRole('member');

    $this->membership = Membership::create([
        'user_id' => $this->member->id,
        'saprf_number' => '1629',
        'membership_type' => 'free',
        'status' => 'expired',
        'payment_status' => 'unpaid',
        'expiry_date' => '2025-07-18',
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

function membershipUpdatePayload(array $overrides = []): array
{
    $member = test()->member;
    $membership = test()->membership;

    return array_merge([
        'name' => $member->name,
        'email' => $member->email,
        'phone' => '0847274887',
        'date_of_birth' => '1992-09-22',
        'gender' => 'male',
        'ethnicity' => 'white',
        'previously_disadvantaged_choice' => 'no',
        'province_id' => test()->gauteng->id,
        'club_id' => test()->club->id,
        'sa_citizen' => '1',
        'country_of_residence' => 'ZA',
        'address_line_1' => 'Unit 50 Donegia Complex',
        'address_line_2' => '029 Donegal Road',
        'address_line_3' => 'Rangeview',
        'city' => 'Krugersdorp',
        'postal_code' => '1739',
        'saprf_number' => $membership->saprf_number,
        'membership_type' => 'full',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => '2027-01-01',
    ], $overrides);
}

it('lets an admin correct a member from legacy site details', function () {
    $this->actingAs($this->admin)
        ->put(route('memberships.update', $this->membership), membershipUpdatePayload())
        ->assertRedirect(route('memberships.show', $this->membership));

    $this->member->refresh();
    $this->membership->refresh();

    expect($this->member->phone)->toBe('0847274887')
        ->and($this->member->date_of_birth?->toDateString())->toBe('1992-09-22')
        ->and($this->member->gender)->toBe('male')
        ->and($this->member->ethnicity)->toBe('white')
        ->and($this->member->previously_disadvantaged)->toBeFalse()
        ->and($this->member->province_id)->toBe($this->gauteng->id)
        ->and($this->member->club_id)->toBe($this->club->id)
        ->and($this->member->address_line_1)->toBe('Unit 50 Donegia Complex')
        ->and($this->member->city)->toBe('Krugersdorp')
        ->and($this->member->postal_code)->toBe('1739')
        ->and($this->membership->membership_type)->toBe('full')
        ->and($this->membership->status)->toBe('active')
        ->and($this->membership->payment_status)->toBe('paid')
        ->and($this->membership->expiry_date?->toDateString())->toBe('2027-01-01');
});

it('shows the corrected personal details on the membership page', function () {
    $this->actingAs($this->admin)
        ->put(route('memberships.update', $this->membership), membershipUpdatePayload())
        ->assertRedirect();

    $this->actingAs($this->admin)
        ->get(route('memberships.show', $this->membership))
        ->assertOk()
        ->assertSee('0847274887')
        ->assertSee('22 Sep 1992')
        ->assertSee('Highveld Precision Rifle Club (HPRC)')
        ->assertSee('Unit 50 Donegia Complex')
        ->assertSee('Krugersdorp')
        ->assertSee('01 Jan 2027')
        ->assertSee('Full');
});

it('forbids a regular member from updating another membership', function () {
    $this->actingAs($this->member)
        ->put(route('memberships.update', $this->membership), membershipUpdatePayload())
        ->assertForbidden();
});

it('applies the legacy member correction migration for both records', function () {
    $mpumalanga = Province::create(['name' => 'Mpumalanga', 'abbreviation' => 'MP']);
    $henna = User::factory()->create([
        'name' => 'Henna du Plessis',
        'email' => 'hennadup@gmail.com',
    ]);
    $hennaMembership = Membership::create([
        'user_id' => $henna->id,
        'saprf_number' => '1206',
        'membership_type' => 'full',
        'status' => 'lapsed',
        'payment_status' => 'paid',
        'start_date' => '2026-01-01',
        'expiry_date' => '2026-07-13',
    ]);

    $migration = require database_path('migrations/2026_08_19_100100_correct_legacy_member_details.php');
    $migration->up();

    $this->member->refresh();
    $this->membership->refresh();
    $henna->refresh();
    $hennaMembership->refresh();

    expect($this->member->phone)->toBe('0847274887')
        ->and($this->member->date_of_birth?->toDateString())->toBe('1992-09-22')
        ->and($this->member->club?->name)->toBe('Highveld Precision Rifle Club (HPRC)')
        ->and($this->member->address_line_1)->toBe('Unit 50 Donegia Complex')
        ->and($this->membership->membership_type)->toBe('full')
        ->and($this->membership->status)->toBe('active')
        ->and($this->membership->payment_status)->toBe('paid')
        ->and($this->membership->expiry_date?->toDateString())->toBe('2027-01-01')
        ->and($this->member->sa_id_number)->toBeNull();

    expect($henna->phone)->toBe('27662119959')
        ->and($henna->date_of_birth?->toDateString())->toBe('1989-06-11')
        ->and($henna->sa_id_number)->toBe('8906115075087')
        ->and($henna->club?->name)->toBe('Lowveld Precision Rifle Club')
        ->and($henna->province_id)->toBe($mpumalanga->id)
        ->and($henna->address_line_1)->toBe('Elmswood')
        ->and($henna->city)->toBe('Nelspruit')
        ->and($henna->postal_code)->toBe('1200')
        ->and($hennaMembership->status)->toBe('active')
        ->and($hennaMembership->payment_status)->toBe('paid')
        ->and($hennaMembership->expiry_date?->toDateString())->toBe('2027-07-17');
});
