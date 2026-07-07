<?php

use App\Models\Division;
use App\Models\User;

beforeEach(fn () => seedRoles());

// ── Division CRUD ──

it('allows owner to view divisions index', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');

    Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);

    $this->actingAs($user)
        ->get(route('divisions.index'))
        ->assertOk()
        ->assertSee('Open');
});

it('allows owner to create a division', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');

    $this->actingAs($user)
        ->post(route('divisions.store'), [
            'slug' => 'factory',
            'name' => 'Factory',
            'display_order' => 2,
        ])
        ->assertRedirect(route('divisions.index'));

    $this->assertDatabaseHas('divisions', ['slug' => 'factory', 'name' => 'Factory']);
});

it('validates division slug uniqueness', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');

    Division::create(['slug' => 'open', 'name' => 'Open']);

    $this->actingAs($user)
        ->post(route('divisions.store'), [
            'slug' => 'open',
            'name' => 'Duplicate',
            'display_order' => 0,
        ])
        ->assertSessionHasErrors('slug');
});

it('restricts division CRUD to owner role', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('divisions.index'))
        ->assertForbidden();
});

it('can archive a division by setting is_active to false', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');

    $division = Division::create(['slug' => 'gas-gun', 'name' => 'Gas Gun']);

    $this->actingAs($user)
        ->put(route('divisions.update', $division), [
            'slug' => 'gas-gun',
            'name' => 'Gas Gun',
            'display_order' => 0,
            'is_active' => false,
        ])
        ->assertRedirect(route('divisions.index'));

    expect($division->fresh()->is_active)->toBeFalse();
});

it('lists active divisions in display order', function () {
    Division::create(['slug' => 'b', 'name' => 'B', 'display_order' => 2]);
    Division::create(['slug' => 'a', 'name' => 'A', 'display_order' => 1]);

    $divisions = Division::active()->ordered()->get();

    expect($divisions)->toHaveCount(2);
    expect($divisions->first()->slug)->toBe('a');
});

// ── Demographic divisions ──
// Under the flat-division model, Ladies/Junior/Senior sit alongside the
// equipment classes as regular divisions. Every shooter picks exactly one.

it('supports demographic divisions alongside equipment divisions', function () {
    Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    Division::create(['slug' => 'ladies', 'name' => 'Ladies', 'display_order' => 5]);
    Division::create(['slug' => 'junior', 'name' => 'Junior', 'display_order' => 6]);
    Division::create(['slug' => 'senior', 'name' => 'Senior', 'display_order' => 7]);

    expect(Division::active()->ordered()->pluck('slug')->toArray())
        ->toBe(['open', 'ladies', 'junior', 'senior']);
});

it('allows a user to belong to one division only', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open']);
    $ladies = Division::create(['slug' => 'ladies', 'name' => 'Ladies']);

    $user = User::factory()->create(['division_id' => $open->id]);
    $user->update(['division_id' => $ladies->id]);

    expect($user->fresh()->division->slug)->toBe('ladies');
});
