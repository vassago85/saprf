<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(Tests\TestCase::class, RefreshDatabase::class)->in('Feature');

function seedRoles(): void
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    foreach (['owner', 'admin', 'match_director', 'member', 'iprf_selector', 'developer', 'exco', 'chair'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }
}
