<?php

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function () {
        // The `billing_start_date` setting is seeded by migration to 2026-09-01
        // so August 2026 registrations get a fee waiver in production. Tests
        // that don't care about the waiver would otherwise flip between charging
        // and waiving depending on when they run vs the cut-off. Clear it here
        // so existing tests keep asserting the full R50 SAPRF fee; the waiver
        // tests set it explicitly.
        if (Schema::hasTable('settings')) {
            Setting::query()->where('key', 'billing_start_date')->update(['value' => '']);
            app(SettingsService::class)->clearCache();
        }
    })
    ->in('Feature');

function seedRoles(): void
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    foreach (['owner', 'admin', 'match_director', 'member', 'iprf_selector', 'developer', 'exco', 'chair', 'provincial_admin'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }
}
