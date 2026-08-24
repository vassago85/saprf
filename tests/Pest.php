<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

function seedRoles(): void
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    foreach (['owner', 'admin', 'match_director', 'member', 'iprf_selector', 'developer', 'exco', 'chair', 'provincial_admin'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }
}
