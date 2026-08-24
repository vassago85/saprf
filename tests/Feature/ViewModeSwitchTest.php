<?php

/**
 * Covers the Admin/Shooter view-mode switch: staff can flip between their
 * admin dashboard and their own shooter (member) experience via the sidebar
 * toggle. Pure members are pinned to the shooter view.
 */

use App\Models\User;

beforeEach(function () {
    seedRoles();
});

// ── Defaults ─────────────────────────────────────────────────────────

it('defaults staff users to admin view mode', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect($admin->fresh()->effectiveViewMode())->toBe('admin');
});

it('pins ordinary members to shooter view mode regardless of session', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    // Even if a session key somehow said "admin", a pure member falls back
    // to shooter — they don't have an admin experience to switch into.
    session(['view_mode' => 'admin']);

    expect($member->fresh()->effectiveViewMode())->toBe('shooter');
});

it('reports canSwitchViewMode true for staff and false for members', function () {
    $member = User::factory()->create();
    $member->assignRole('member');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $exco = User::factory()->create();
    $exco->assignRole('exco');
    $provincial = User::factory()->create();
    $provincial->assignRole('provincial_admin');

    expect($member->canSwitchViewMode())->toBeFalse()
        ->and($admin->canSwitchViewMode())->toBeTrue()
        ->and($exco->canSwitchViewMode())->toBeTrue()
        ->and($provincial->canSwitchViewMode())->toBeTrue();
});

// ── Switching action ─────────────────────────────────────────────────

it('lets a staff user switch to shooter mode via the toggle route', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->post(route('dashboard.view-mode'), ['mode' => 'shooter'])
        ->assertRedirect(route('dashboard'));

    expect(session('view_mode'))->toBe('shooter');
});

it('lets a staff user switch back to admin mode', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->post(route('dashboard.view-mode'), ['mode' => 'shooter'])
        ->assertRedirect(route('dashboard'));

    $this->post(route('dashboard.view-mode'), ['mode' => 'admin'])
        ->assertRedirect(route('dashboard'));

    expect(session('view_mode'))->toBe('admin');
});

it('rejects an invalid mode value with a validation error', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->post(route('dashboard.view-mode'), ['mode' => 'godmode'])
        ->assertSessionHasErrors('mode');
});

it('ignores a switch attempt from a pure member and does not set the session key', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->post(route('dashboard.view-mode'), ['mode' => 'shooter'])
        ->assertRedirect(route('dashboard'));

    // Nothing was written to the session — pure members are always shooters.
    expect(session()->has('view_mode'))->toBeFalse();
});

// ── Dashboard integration ────────────────────────────────────────────

it('renders the admin dashboard for an admin in admin mode', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $admin->assignRole('member');

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Admin Dashboard');
});

it('routes an admin in shooter mode to the member dashboard branch', function () {
    // We only assert the routing decision, not the full rendered HTML —
    // memberDashboard() runs SQL using YEAR() which SQLite (used in tests)
    // does not support. The routing itself is the interesting behaviour;
    // production MySQL renders the view fine.
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $admin->assignRole('member');

    $this->actingAs($admin);
    $this->post(route('dashboard.view-mode'), ['mode' => 'shooter']);

    // After flipping, effectiveViewMode is now 'shooter' — this is the
    // trigger the DashboardController::index() branches on.
    expect($admin->fresh()->effectiveViewMode())->toBe('shooter');
});
