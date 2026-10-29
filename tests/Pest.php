<?php

use App\Models\Setting;
use App\Models\User;
use App\Navigation\SidebarMenu;
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

/**
 * Give the user an active, paid SAPRF membership record. Required by the
 * EnsureActiveMember middleware that gates kit routes, the certificate, and
 * the activity report. Returns the created Membership so tests can tweak
 * dates / status if they need.
 */
function giveActiveMembership(User $user, ?string $saprfNumber = null): \App\Models\Membership
{
    return \App\Models\Membership::create([
        'user_id' => $user->id,
        'saprf_number' => $saprfNumber ?? ('T-'.strtoupper(Illuminate\Support\Str::random(6))),
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => now()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);
}

/**
 * Sidebar helpers used by SidebarNavigationTest and MatchWorkspaceTest.
 * Defined here (not in a single feature test file) so Pest's parallel
 * runner can call them from any process.
 *
 * @return list<string>
 */
function sidebarLabels(User $user, string $mode): array
{
    return SidebarMenu::labelsFor($user, $mode);
}

/**
 * @return list<array<string, mixed>>
 */
function sidebarSections(User $user, string $mode): array
{
    return SidebarMenu::sectionsFor($user, $mode);
}
