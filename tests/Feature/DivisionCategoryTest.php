<?php

use App\Models\Category;
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

// ── Category CRUD ──

it('allows owner to create a category', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');

    $this->actingAs($user)
        ->post(route('categories.store'), [
            'slug' => 'junior',
            'name' => 'Junior',
            'display_order' => 1,
        ])
        ->assertRedirect(route('categories.index'));

    $this->assertDatabaseHas('categories', ['slug' => 'junior', 'name' => 'Junior']);
});

it('validates category slug uniqueness', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');

    Category::create(['slug' => 'ladies', 'name' => 'Ladies']);

    $this->actingAs($user)
        ->post(route('categories.store'), [
            'slug' => 'ladies',
            'name' => 'Duplicate',
            'display_order' => 0,
        ])
        ->assertSessionHasErrors('slug');
});

// ── User Division/Category assignment ──

it('assigns a user to a division and categories', function () {
    $division = Division::create(['slug' => 'open', 'name' => 'Open']);
    $ladies = Category::create(['slug' => 'ladies', 'name' => 'Ladies', 'display_order' => 1]);
    $senior = Category::create(['slug' => 'senior', 'name' => 'Senior', 'display_order' => 2]);

    $user = User::factory()->create(['division_id' => $division->id]);
    $user->categories()->sync([$ladies->id, $senior->id]);

    expect($user->division->slug)->toBe('open');
    expect($user->categories)->toHaveCount(2);
    expect($user->categories->pluck('slug')->sort()->values()->toArray())->toBe(['ladies', 'senior']);
});

it('allows a user to belong to one division only', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open']);
    $factory = Division::create(['slug' => 'factory', 'name' => 'Factory']);

    $user = User::factory()->create(['division_id' => $open->id]);
    $user->update(['division_id' => $factory->id]);

    expect($user->fresh()->division->slug)->toBe('factory');
});
