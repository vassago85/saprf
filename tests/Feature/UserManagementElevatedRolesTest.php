<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedRoles();
    // seedRoles() only seeds the four base roles; the elevated ones need to
    // exist for these tests to exercise assignment.
    foreach (['developer', 'exco', 'provincial_admin'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }
});

it('lets a developer assign the elevated owner role', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $target = User::factory()->create();
    $target->assignRole('member');

    $this->actingAs($developer)
        ->put(route('user-management.update-role', $target), [
            'roles' => ['member', 'admin', 'owner'],
        ])
        ->assertRedirect(route('user-management.index'));

    $target->refresh();
    expect($target->hasRole('owner'))->toBeTrue()
        ->and($target->hasRole('admin'))->toBeTrue()
        ->and($target->hasRole('member'))->toBeTrue();
});

it('lets a developer assign the elevated exco and provincial_admin roles', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $target = User::factory()->create();
    $target->assignRole('member');

    $this->actingAs($developer)
        ->put(route('user-management.update-role', $target), [
            'roles' => ['member', 'exco', 'provincial_admin'],
        ])
        ->assertRedirect(route('user-management.index'));

    $target->refresh();
    expect($target->hasRole('exco'))->toBeTrue()
        ->and($target->hasRole('provincial_admin'))->toBeTrue();
});

it('refuses to assign elevated roles when the actor is only an owner (not developer)', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $target = User::factory()->create();
    $target->assignRole('member');

    $this->actingAs($owner)
        ->put(route('user-management.update-role', $target), [
            'roles' => ['member', 'exco'],
        ])
        ->assertSessionHasErrors('roles.1');

    $target->refresh();
    expect($target->hasRole('exco'))->toBeFalse();
});

it('refuses to assign the developer role when the actor is not a developer', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $target = User::factory()->create();
    $target->assignRole('member');

    $this->actingAs($owner)
        ->put(route('user-management.update-role', $target), [
            'roles' => ['member', 'developer'],
        ])
        ->assertSessionHasErrors('roles.1');

    $target->refresh();
    expect($target->hasRole('developer'))->toBeFalse();
});

it('lets a developer demote an owner', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $exOwner = User::factory()->create();
    $exOwner->assignRole('owner');

    $this->actingAs($developer)
        ->put(route('user-management.update-role', $exOwner), [
            'roles' => ['member'],
        ])
        ->assertRedirect(route('user-management.index'));

    $exOwner->refresh();
    expect($exOwner->hasRole('owner'))->toBeFalse()
        ->and($exOwner->hasRole('member'))->toBeTrue();
});

it('does not let a non-developer demote an owner', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $admin->assignRole('owner'); // give owner so they can even access the page

    $otherOwner = User::factory()->create();
    $otherOwner->assignRole('owner');

    $this->actingAs($admin)
        ->put(route('user-management.update-role', $otherOwner), [
            'roles' => ['member'],
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    $otherOwner->refresh();
    expect($otherOwner->hasRole('owner'))->toBeTrue();
});

it('prevents a developer from removing their own developer role', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');
    $developer->assignRole('member');

    $this->actingAs($developer)
        ->put(route('user-management.update-role', $developer), [
            'roles' => ['member'],
        ])
        ->assertRedirect(route('user-management.index'));

    $developer->refresh();
    // The controller silently re-adds the developer role so the user can't
    // lock themselves out.
    expect($developer->hasRole('developer'))->toBeTrue();
});

it('shows an edit link on an owner row for a developer', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $owner = User::factory()->create(['name' => 'Warren Owner']);
    $owner->assignRole('owner');

    $this->actingAs($developer)
        ->get(route('user-management.index', ['search' => 'Warren Owner']))
        ->assertOk()
        ->assertSee(route('user-management.edit', $owner), false)
        ->assertDontSee('Protected');
});

it('hides the edit link on an owner row for a non-developer exco', function () {
    $exco = User::factory()->create();
    $exco->assignRole('exco');

    $owner = User::factory()->create(['name' => 'Warren Owner']);
    $owner->assignRole('owner');

    $this->actingAs($exco)
        ->get(route('user-management.index', ['search' => 'Warren Owner']))
        ->assertOk()
        ->assertDontSee(route('user-management.edit', $owner), false)
        ->assertSee('Protected');
});

it('lets a developer open another owner\'s edit page', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $this->actingAs($developer)
        ->get(route('user-management.edit', $owner))
        ->assertOk()
        ->assertSee('Elevated Roles')
        ->assertSee('Owner');
});

it('shows the elevated roles panel to a developer on the edit page', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $target = User::factory()->create();
    $target->assignRole('member');

    $this->actingAs($developer)
        ->get(route('user-management.edit', $target))
        ->assertOk()
        ->assertSee('Elevated Roles')
        ->assertSee('Sysadmin')
        ->assertSee('Provincial admin');
});

it('does not show the elevated roles panel to a non-developer owner', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $target = User::factory()->create();
    $target->assignRole('member');

    $this->actingAs($owner)
        ->get(route('user-management.edit', $target))
        ->assertOk()
        ->assertDontSee('Elevated Roles');
});
