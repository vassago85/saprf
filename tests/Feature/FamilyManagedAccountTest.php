<?php

use App\Models\Division;
use App\Models\Province;
use App\Models\User;

beforeEach(function () {
    seedRoles();

    $this->province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $this->division = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);

    $this->parent = User::factory()->create([
        'province_id' => $this->province->id,
        'email_verified_at' => now(),
    ]);
    $this->parent->assignRole('member');
});

it('lets a user add a spouse without a date of birth', function () {
    $this->actingAs($this->parent)
        ->post(route('family.store'), [
            'name' => 'Sarah Britnell',
            'relationship' => 'spouse',
            'province_id' => $this->province->id,
            'division_id' => $this->division->id,
        ])
        ->assertRedirect(route('family.index'));

    $spouse = User::where('name', 'Sarah Britnell')->first();

    expect($spouse)->not->toBeNull()
        ->and($spouse->parent_id)->toBe($this->parent->id)
        ->and($spouse->is_managed_account)->toBeTrue()
        ->and($spouse->managed_relationship)->toBe('spouse')
        ->and($spouse->date_of_birth)->toBeNull()
        ->and($spouse->hasRole('member'))->toBeTrue();
});

it('requires a date of birth for junior accounts', function () {
    $this->actingAs($this->parent)
        ->post(route('family.store'), [
            'name' => 'Conner Britnell',
            'relationship' => 'junior',
            'province_id' => $this->province->id,
            'division_id' => $this->division->id,
        ])
        ->assertSessionHasErrors('date_of_birth');

    expect(User::where('name', 'Conner Britnell')->exists())->toBeFalse();
});

it('rejects an unknown relationship', function () {
    $this->actingAs($this->parent)
        ->post(route('family.store'), [
            'name' => 'Someone',
            'relationship' => 'cousin-twice-removed',
            'province_id' => $this->province->id,
            'division_id' => $this->division->id,
        ])
        ->assertSessionHasErrors('relationship');
});

it('lets the account holder hand over a managed adult account', function () {
    $spouse = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'spouse',
        'province_id' => $this->province->id,
    ]);

    $this->actingAs($this->parent)
        ->post(route('family.handover.start', $spouse), [
            'handover_email' => 'sarah@example.com',
        ])
        ->assertRedirect(route('family.show', $spouse));

    $spouse->refresh();

    expect($spouse->handover_email)->toBe('sarah@example.com')
        ->and($spouse->hasPendingHandover())->toBeTrue();
});

it('cannot manage a family member belonging to another account', function () {
    $otherParent = User::factory()->create(['province_id' => $this->province->id]);

    $theirChild = User::factory()->create([
        'parent_id' => $otherParent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
    ]);

    $this->actingAs($this->parent)
        ->get(route('family.show', $theirChild))
        ->assertForbidden();
});

it('exposes all managed accounts and relationship labels', function () {
    User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
    ]);
    $spouse = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'spouse',
    ]);

    expect($this->parent->managedAccounts()->count())->toBe(2)
        ->and($spouse->managedRelationshipLabel())->toBe('Spouse / Partner')
        ->and($spouse->isJuniorAccount())->toBeFalse();
});
