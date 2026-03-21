<?php

use App\Models\Category;
use App\Models\Division;
use App\Models\User;

beforeEach(fn () => seedRoles());

// ── Division CRUD ──

it('allows owner to view divisions index', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');

    Division::create(['code' => 'open', 'name' => 'Open', 'discipline' => 'PRS', 'display_order' => 1]);

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
            'code' => 'production',
            'name' => 'Production',
            'discipline' => 'PRS',
            'display_order' => 2,
        ])
        ->assertRedirect(route('divisions.index'));

    $this->assertDatabaseHas('divisions', ['code' => 'production', 'name' => 'Production']);
});

it('validates division code uniqueness', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');

    Division::create(['code' => 'open', 'name' => 'Open', 'discipline' => 'PRS']);

    $this->actingAs($user)
        ->post(route('divisions.store'), [
            'code' => 'open',
            'name' => 'Duplicate',
            'discipline' => 'PRS',
            'display_order' => 0,
        ])
        ->assertSessionHasErrors('code');
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

    $division = Division::create(['code' => 'gas-gun', 'name' => 'Gas Gun', 'discipline' => 'PRS']);

    $this->actingAs($user)
        ->put(route('divisions.update', $division), [
            'code' => 'gas-gun',
            'name' => 'Gas Gun',
            'discipline' => 'PRS',
            'display_order' => 0,
            'is_active' => false,
        ])
        ->assertRedirect(route('divisions.index'));

    expect($division->fresh()->is_active)->toBeFalse();
});

it('filters divisions by discipline scope', function () {
    Division::create(['code' => 'open', 'name' => 'Open', 'discipline' => 'PRS']);
    Division::create(['code' => 'pr22-open', 'name' => 'PR22 Open', 'discipline' => 'PR22']);
    Division::create(['code' => 'both-div', 'name' => 'Both Division', 'discipline' => 'both']);

    $prs = Division::forDiscipline('PRS')->get();
    expect($prs)->toHaveCount(2);
    expect($prs->pluck('code')->toArray())->toContain('open', 'both-div');

    $pr22 = Division::forDiscipline('PR22')->get();
    expect($pr22)->toHaveCount(2);
    expect($pr22->pluck('code')->toArray())->toContain('pr22-open', 'both-div');
});

// ── Category CRUD ──

it('allows owner to create a category', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');

    $this->actingAs($user)
        ->post(route('categories.store'), [
            'code' => 'junior',
            'name' => 'Junior',
            'is_age_based' => true,
            'min_age' => 0,
            'max_age' => 21,
            'display_order' => 1,
        ])
        ->assertRedirect(route('categories.index'));

    $this->assertDatabaseHas('categories', ['code' => 'junior', 'is_age_based' => true, 'max_age' => 21]);
});

it('matches age correctly for age-based category', function () {
    $junior = Category::create(['code' => 'junior', 'name' => 'Junior', 'is_age_based' => true, 'max_age' => 21]);
    $senior = Category::create(['code' => 'senior', 'name' => 'Senior', 'is_age_based' => true, 'min_age' => 55]);

    expect($junior->matchesAge(18))->toBeTrue();
    expect($junior->matchesAge(22))->toBeFalse();
    expect($senior->matchesAge(55))->toBeTrue();
    expect($senior->matchesAge(40))->toBeFalse();
});
