<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['owner', 'admin', 'match_director', 'member', 'provincial_admin'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }

    $this->owner = User::factory()->create(['email' => 'test-owner@saprf.co.za']);
    $this->owner->syncRoles(['owner', 'member']);

    // Smoke-tested kit routes (rifle configurations) now require an active
    // SAPRF membership. Give the owner one so the smoke test keeps covering
    // its own routes rather than exercising the member-gate 403 path.
    \App\Models\Membership::create([
        'user_id' => $this->owner->id,
        'saprf_number' => 'T-SMOKE',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => now()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    // Profile-complete has its own tests; skipping it here keeps the smoke
    // suite focused on URL reachability.
    $this->withoutMiddleware(\App\Http\Middleware\EnsureProfileComplete::class);
});

test('public pages return 200', function (string $url) {
    $this->get($url)->assertOk();
})->with([
    '/',
    '/login',
    '/register',
]);

test('api endpoints return 200', function (string $url) {
    $this->getJson($url)->assertOk();
})->with([
    '/api/v1/standings',
    '/api/v1/matches/upcoming',
    '/api/v1/matches/recent-results',
    '/api/v1/firearm-models',
    '/api/v1/firearm-models?make_id=1',
]);

test('authenticated pages return 200', function (string $url) {
    $this->actingAs($this->owner)->get($url)->assertOk();
})->with([
    '/dashboard',
    '/dashboard?view_as=owner',
    '/dashboard?view_as=admin',
    '/dashboard?view_as=match_director',
    '/dashboard?view_as=member',
    '/profile',
    '/standings',
    '/registrations',
    '/rifle-configurations',
    '/rifle-configurations/create',
    '/matches',
    '/matches/create',
    '/score-imports',
    '/scores',
    '/memberships',
    '/sponsors',
    '/sponsors/create',
    '/audit-logs',
    '/site-settings',
    '/user-management',
    '/qualification-rules',
    '/qualification-rules/create',
    '/sponsor-tiers',
    '/sponsor-tiers/create',
    '/sascoc-report',
    '/provincial-committees',
    '/provincial-committees/create',
    '/provincial-members',
]);
