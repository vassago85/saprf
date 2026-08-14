<?php

/**
 * Per-match fee overrides.
 *
 * These tests lock in three related contracts:
 *
 *   1. RegistrationPricingService prefers a match-level platform/SAPRF fee
 *      override when both type + value are set on the match, and falls back
 *      to the global setting when either is null. Half-set overrides never
 *      mix with the global rate.
 *   2. Only exco/developer can set the overrides through the match edit form.
 *      Other roles who can update a match (owner, admin, MD) are silently
 *      denied so they can't rewrite the split between SAPRF, platform, and
 *      the MD.
 *   3. UI visibility follows role — owner/admin see the values read-only,
 *      exco/developer see them writable, the MD doesn't see the block at all.
 */

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\RegistrationPricingService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedRoles();
    foreach (['exco', 'developer'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    // Global rates: R50 flat SAPRF, R50 flat platform.
    foreach ([
        'saprf_fee_type' => 'fixed', 'saprf_fee_value' => '50',
        'platform_fee_type' => 'fixed', 'platform_fee_value' => '50',
        'non_member_surcharge' => '0', 'lapsed_member_surcharge' => '0',
        'estimated_gateway_fee_percentage' => '0', 'estimated_gateway_flat_fee' => '0',
    ] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
    app(SettingsService::class)->clearCache();

    $this->md = User::factory()->create();
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Override Test Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 500,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 500,
        'created_by' => $this->md->id,
    ]);
});

// ── Pricing service: match override wins ─────────────────────────────

it('uses the match-level platform fee override when both type and value are set', function () {
    $this->match->update(['platform_fee_type' => 'fixed', 'platform_fee_value' => 0]);
    $shooter = User::factory()->create();

    $breakdown = app(RegistrationPricingService::class)
        ->calculateBreakdown($this->match->fresh(), $shooter, Carbon::today());

    expect($breakdown['platform_fee'])->toBe(0.0);
    expect($breakdown['saprf_fee'])->toBe(50.0);
});

it('uses the match-level SAPRF fee override when both type and value are set', function () {
    $this->match->update(['saprf_fee_type' => 'percentage', 'saprf_fee_value' => 10]);
    $shooter = User::factory()->create();

    $breakdown = app(RegistrationPricingService::class)
        ->calculateBreakdown($this->match->fresh(), $shooter, Carbon::today());

    // 10% of R500 = R50
    expect($breakdown['saprf_fee'])->toBe(50.0);
    expect($breakdown['platform_fee'])->toBe(50.0);
});

it('falls back to global rates when neither override is set', function () {
    $shooter = User::factory()->create();

    $breakdown = app(RegistrationPricingService::class)
        ->calculateBreakdown($this->match, $shooter, Carbon::today());

    expect($breakdown['platform_fee'])->toBe(50.0);
    expect($breakdown['saprf_fee'])->toBe(50.0);
});

it('ignores a half-set override so global rate is never blended with a match value', function () {
    // Only type set, no value → treat as no override.
    $this->match->forceFill([
        'platform_fee_type' => 'fixed',
        'platform_fee_value' => null,
    ])->save();

    $shooter = User::factory()->create();

    $breakdown = app(RegistrationPricingService::class)
        ->calculateBreakdown($this->match->fresh(), $shooter, Carbon::today());

    expect($breakdown['platform_fee'])->toBe(50.0);
});

// ── UI + authorization ───────────────────────────────────────────────

it('shows editable fee overrides to a developer editing the match', function () {
    $dev = User::factory()->create();
    $dev->assignRole('developer');

    $this->actingAs($dev)
        ->get(route('matches.edit', $this->match))
        ->assertOk()
        ->assertSee('Fee Overrides')
        ->assertSee('Exco / Developer');
});

it('shows editable fee overrides to an exco member editing the match', function () {
    $exco = User::factory()->create();
    $exco->assignRole('exco');

    // exco isn't in MatchPolicy::update — grant admin as well so they can
    // reach the edit page. This mirrors real usage where exco also has admin.
    $exco->assignRole('admin');

    $this->actingAs($exco)
        ->get(route('matches.edit', $this->match))
        ->assertOk()
        ->assertSee('Fee Overrides')
        ->assertSee('Exco / Developer');
});

it('shows read-only fee overrides to owner and admin', function () {
    foreach (['owner', 'admin'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);

        $response = $this->actingAs($user)
            ->get(route('matches.edit', $this->match))
            ->assertOk();

        $response->assertSee('Fee Overrides');
        $response->assertSee('Read-only');
    }
});

it('hides the fee overrides block entirely from the match director', function () {
    $this->actingAs($this->md)
        ->get(route('matches.edit', $this->match))
        ->assertOk()
        ->assertDontSee('Fee Overrides');
});

it('persists fee overrides submitted by a developer', function () {
    $dev = User::factory()->create();
    $dev->assignRole('developer');

    $this->actingAs($dev)
        ->put(route('matches.update', $this->match), [
            'name' => $this->match->name,
            'match_type' => 'PRS',
            'series_level' => 'provincial',
            'match_date' => $this->match->match_date->format('Y-m-d'),
            'active_member_fee' => 500,
            'status' => 'open',
            'platform_fee_type' => 'fixed',
            'platform_fee_value' => '0',
            'saprf_fee_type' => 'fixed',
            'saprf_fee_value' => '25',
        ])
        ->assertRedirect(route('matches.show', $this->match));

    $this->match->refresh();
    expect($this->match->platform_fee_type)->toBe('fixed');
    expect((float) $this->match->platform_fee_value)->toBe(0.0);
    expect($this->match->saprf_fee_type)->toBe('fixed');
    expect((float) $this->match->saprf_fee_value)->toBe(25.0);
});

it('silently ignores fee overrides submitted by a match director', function () {
    $this->actingAs($this->md)
        ->put(route('matches.update', $this->match), [
            'name' => $this->match->name,
            'match_type' => 'PRS',
            'series_level' => 'provincial',
            'match_date' => $this->match->match_date->format('Y-m-d'),
            'active_member_fee' => 500,
            'status' => 'open',
            'platform_fee_type' => 'fixed',
            'platform_fee_value' => '0',
        ])
        ->assertRedirect(route('matches.show', $this->match));

    $this->match->refresh();
    expect($this->match->platform_fee_type)->toBeNull();
    expect($this->match->platform_fee_value)->toBeNull();
});

it('silently ignores fee overrides submitted by an owner (read-only view)', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->put(route('matches.update', $this->match), [
            'name' => $this->match->name,
            'match_type' => 'PRS',
            'series_level' => 'provincial',
            'match_date' => $this->match->match_date->format('Y-m-d'),
            'active_member_fee' => 500,
            'status' => 'open',
            'platform_fee_type' => 'fixed',
            'platform_fee_value' => '0',
        ])
        ->assertRedirect(route('matches.show', $this->match));

    $this->match->refresh();
    expect($this->match->platform_fee_type)->toBeNull();
    expect($this->match->platform_fee_value)->toBeNull();
});

it('rejects a half-set override submitted by a developer', function () {
    $dev = User::factory()->create();
    $dev->assignRole('developer');

    $this->actingAs($dev)
        ->put(route('matches.update', $this->match), [
            'name' => $this->match->name,
            'match_type' => 'PRS',
            'series_level' => 'provincial',
            'match_date' => $this->match->match_date->format('Y-m-d'),
            'active_member_fee' => 500,
            'status' => 'open',
            'platform_fee_type' => 'fixed',
            // No platform_fee_value — validation should reject.
        ])
        ->assertSessionHasErrors('platform_fee_value');
});
