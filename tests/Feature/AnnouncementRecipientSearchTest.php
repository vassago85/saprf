<?php

/**
 * Typeahead behind the composer's "Named individuals" audience rule.
 */

use App\Models\Membership;
use App\Models\User;

beforeEach(function () {
    seedRoles();

    $this->exco = User::factory()->create(['email_verified_at' => now()]);
    $this->exco->assignRole(['exco', 'member']);
});

it('blocks members from the recipient search', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($member)
        ->getJson(route('announcements.recipients.search', ['q' => 'an']))
        ->assertForbidden();
});

it('finds users by partial name', function () {
    $andre = User::factory()->create(['name' => 'Andre Muller']);
    User::factory()->create(['name' => 'Greg Sykes']);

    $this->actingAs($this->exco)
        ->getJson(route('announcements.recipients.search', ['q' => 'mull']))
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.id', $andre->id)
        ->assertJsonPath('results.0.name', 'Andre Muller');
});

it('finds users by email and SAPRF number', function () {
    $byEmail = User::factory()->create(['name' => 'Email Match', 'email' => 'findme@example.test']);
    $byNumber = User::factory()->create(['name' => 'Number Match']);
    Membership::create([
        'user_id' => $byNumber->id,
        'saprf_number' => 'SAPRF-98765',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    $this->actingAs($this->exco)
        ->getJson(route('announcements.recipients.search', ['q' => 'findme@']))
        ->assertJsonPath('results.0.id', $byEmail->id);

    $this->actingAs($this->exco)
        ->getJson(route('announcements.recipients.search', ['q' => 'SAPRF-987']))
        ->assertJsonPath('results.0.id', $byNumber->id)
        ->assertJsonPath('results.0.saprf_number', 'SAPRF-98765');
});

it('returns nothing for a one-character term', function () {
    User::factory()->create(['name' => 'Andre Muller']);

    $this->actingAs($this->exco)
        ->getJson(route('announcements.recipients.search', ['q' => 'a']))
        ->assertOk()
        ->assertJsonCount(0, 'results');
});

it('hydrates selected users by id', function () {
    $a = User::factory()->create(['name' => 'Alpha']);
    $b = User::factory()->create(['name' => 'Bravo']);
    User::factory()->create(['name' => 'Charlie']);

    $response = $this->actingAs($this->exco)
        ->getJson(route('announcements.recipients.search', ['ids' => "{$a->id}, {$b->id}, junk"]))
        ->assertOk()
        ->assertJsonCount(2, 'results');

    expect(collect($response->json('results'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});
