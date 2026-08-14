<?php

/**
 * Backfill migration for old-site imports.
 *
 * The 2026_08_14_130000 migration finds every match that has at least one
 * registration owned by an importer-stub user (email @import.saprf.local),
 * sets the platform-fee override on that match to R0, and rebalances every
 * paid registration on the match so md_net_amount picks up the historical
 * platform_fee (leaving fee = saprf + platform + gateway + md_net balanced).
 *
 * These tests re-run the migration against a hand-crafted fixture so we know
 * the migration itself does the right thing regardless of the shape of
 * whatever was in the DB at deploy time.
 */

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;

beforeEach(function () {
    seedRoles();
    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->division = Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'is_active' => true]);

    $this->md = User::factory()->create();

    // Imported match: has a stub-user registration + a normal-user paid entry.
    $this->importedMatch = MatchEvent::create([
        'name' => 'Imported Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS', 'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => now()->subMonths(2),
        'status' => 'completed',
        'active_member_fee' => 500,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 500,
        'created_by' => $this->md->id,
    ]);

    $stub = User::factory()->create(['email' => 'saprf-1234@import.saprf.local']);
    $realShooter = User::factory()->create(['email' => 'shooter@example.com']);

    // Historical import rows: 500 fee, R50 SAPRF, R50 platform, R20 gateway
    // (R380 md_net). After backfill: 500 fee, R50 SAPRF, R0 platform, R20
    // gateway, R430 md_net.
    foreach ([$stub, $realShooter] as $u) {
        MatchRegistration::create([
            'match_id' => $this->importedMatch->id,
            'user_id' => $u->id,
            'division_id' => $this->division->id,
            'shooter_name' => $u->name,
            'email' => $u->email,
            'membership_fee_category' => 'active_member',
            'fee_amount' => 500,
            'surcharge_amount' => 0,
            'saprf_fee' => 50,
            'platform_fee' => 50,
            'gateway_fee' => 20,
            'md_net_amount' => 380,
            'payment_status' => 'paid',
            'registration_status' => 'confirmed',
            'registered_at' => now()->subMonths(2),
        ]);
    }

    // Native match with a normal shooter — should NOT be touched.
    $this->nativeMatch = MatchEvent::create([
        'name' => 'Native Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS', 'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => now()->subMonth(),
        'status' => 'completed',
        'active_member_fee' => 500,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 500,
        'created_by' => $this->md->id,
    ]);
    $nativeShooter = User::factory()->create(['email' => 'native@example.com']);
    MatchRegistration::create([
        'match_id' => $this->nativeMatch->id,
        'user_id' => $nativeShooter->id,
        'division_id' => $this->division->id,
        'shooter_name' => $nativeShooter->name,
        'email' => $nativeShooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500,
        'surcharge_amount' => 0,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 20,
        'md_net_amount' => 380,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subMonth(),
    ]);

    // Roll back and re-run just the backfill migration so we exercise the
    // real up() method under test.
    $file = database_path('migrations/2026_08_14_130000_zero_platform_fee_on_imported_matches.php');
    $migration = require $file;
    $migration->up();
});

it('sets the platform fee override on matches that had an import-stub registrant', function () {
    $this->importedMatch->refresh();

    expect($this->importedMatch->platform_fee_type)->toBe('fixed');
    expect((float) $this->importedMatch->platform_fee_value)->toBe(0.0);
});

it('zeros the historical platform_fee on every paid registration for imported matches', function () {
    foreach ($this->importedMatch->registrations as $reg) {
        expect((float) $reg->fresh()->platform_fee)->toBe(0.0);
    }
});

it('transfers the historical platform_fee onto md_net_amount so the row still balances', function () {
    foreach ($this->importedMatch->registrations as $reg) {
        $reg->refresh();
        expect((float) $reg->md_net_amount)->toBe(430.0);
        // fee_amount = saprf + platform + gateway + md_net + surcharge
        $sum = (float) $reg->saprf_fee
            + (float) $reg->platform_fee
            + (float) $reg->gateway_fee
            + (float) $reg->md_net_amount
            + (float) $reg->surcharge_amount;
        expect($sum)->toBe((float) $reg->fee_amount);
    }
});

it('leaves native matches (no import-stub registrant) untouched', function () {
    $this->nativeMatch->refresh();

    expect($this->nativeMatch->platform_fee_type)->toBeNull();
    expect($this->nativeMatch->platform_fee_value)->toBeNull();

    $native = $this->nativeMatch->registrations->first()->fresh();
    expect((float) $native->platform_fee)->toBe(50.0);
    expect((float) $native->md_net_amount)->toBe(380.0);
});
