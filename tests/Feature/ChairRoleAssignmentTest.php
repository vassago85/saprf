<?php

/**
 * Chair role rules enforced by UserManagementController and the seeded
 * role list. Chair is elevated (developer-only assignment) and always
 * gets Exco alongside — a chair without exco is not representable via
 * the UI.
 */

use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    seedRoles();
});

it('seeds the chair role alongside exco', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(\Spatie\Permission\Models\Role::where('name', 'chair')->exists())->toBeTrue();
});

it('lets a developer assign the chair role and auto-unions exco', function () {
    $developer = User::factory()->create(['email_verified_at' => now()]);
    $developer->assignRole(['developer']);

    $target = User::factory()->create(['email_verified_at' => now()]);
    $target->assignRole('member');

    $this->actingAs($developer)
        ->put(route('user-management.update-role', $target), [
            'roles' => ['member', 'chair'],
        ])
        ->assertRedirect(route('user-management.index'));

    $target->refresh();

    expect($target->hasRole('chair'))->toBeTrue()
        ->and($target->hasRole('exco'))->toBeTrue()
        ->and($target->hasRole('member'))->toBeTrue()
        ->and($target->isExco())->toBeTrue()
        ->and($target->isChair())->toBeTrue();
});

it('blocks a non-developer from assigning the chair role', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $owner->assignRole(['owner']);

    $target = User::factory()->create(['email_verified_at' => now()]);
    $target->assignRole('member');

    $response = $this->actingAs($owner)
        ->put(route('user-management.update-role', $target), [
            'roles' => ['member', 'chair'],
        ]);

    // Validation refuses the chair value; owner keeps their existing roles.
    $response->assertSessionHasErrors('roles.*');

    expect($target->fresh()->hasRole('chair'))->toBeFalse();
});

it('treats chair as a staff role for view-mode switching', function () {
    $chair = User::factory()->create(['email_verified_at' => now()]);
    $chair->assignRole(['chair', 'exco', 'member']);

    expect($chair->isStaffMember())->toBeTrue()
        ->and($chair->canSwitchViewMode())->toBeTrue();
});
