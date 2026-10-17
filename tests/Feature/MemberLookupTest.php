<?php

use App\Models\Membership;
use App\Models\User;

beforeEach(function () {
    seedRoles();
    $this->owner = User::factory()->create(['name' => 'Owner']);
    $this->owner->assignRole('owner');
});

it('finds a member by name and labels them with their number', function () {
    $member = User::factory()->create(['name' => 'Ken Watridge', 'is_active' => true]);
    Membership::create([
        'user_id' => $member->id,
        'saprf_number' => '4242',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => now()->subYear(),
        'expiry_date' => now()->addYear(),
    ]);

    $this->actingAs($this->owner)
        ->getJson(route('members.search', ['q' => 'Ken']))
        ->assertOk()
        ->assertJsonPath('results.0.label', 'Ken Watridge (4242)')
        ->assertJsonMissingPath('results.0.email');

    $this->actingAs($this->owner)
        ->getJson(route('members.search', ['q' => '4242']))
        ->assertOk()
        ->assertJsonPath('results.0.id', $member->id);
});

it('asks for a name search instead of a member number on the staff forms', function () {
    foreach ([
        route('memberships.create'),
        route('appearances.create'),
        route('provincial-committees.create'),
    ] as $url) {
        $this->actingAs($this->owner)
            ->get($url)
            ->assertOk()
            ->assertSee('Search by name or number')
            ->assertDontSee('exact full name');
    }
});
