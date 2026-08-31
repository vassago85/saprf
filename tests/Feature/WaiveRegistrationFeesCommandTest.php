<?php

/**
 * saprf:waive-fees-before-date — retroactively zero SAPRF + platform fees on
 * registrations that fall inside the pre-billing grace period.
 *
 * These tests lock in the arithmetic invariant that matters for reporting:
 * after the command runs, `fee_amount` on each affected row is unchanged, but
 * the amount that used to sit in `saprf_fee` + `platform_fee` has moved into
 * `md_net_amount`. Post-cut-off rows are never touched.
 */

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->md = User::factory()->create();
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Waiver Test Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::create(2026, 12, 1),
        'status' => 'open',
        'active_member_fee' => 500,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 500,
        'created_by' => $this->md->id,
    ]);

    Setting::updateOrCreate(['key' => 'billing_start_date'], ['value' => '2026-09-01']);
    app(SettingsService::class)->clearCache();
});

function makeWaiverRegistration(MatchEvent $match, Carbon $registeredAt, array $overrides = []): MatchRegistration
{
    $shooter = User::factory()->create();

    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500,
        'saprf_fee' => 50,
        'platform_fee' => 25,
        'gateway_fee' => 20,
        'surcharge_amount' => 0,
        'md_net_amount' => 405,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => $registeredAt,
    ], $overrides));
}

it('zeros SAPRF and platform fees on registrations before the cut-off', function () {
    $august = makeWaiverRegistration($this->match, Carbon::create(2026, 8, 20, 12, 0));

    $this->artisan('saprf:waive-fees-before-date')
        ->expectsOutputToContain('affected')
        ->expectsOutputToContain('1 registration')
        ->assertExitCode(0);

    $august->refresh();

    expect($august->saprf_fee)->toEqual(0)
        ->and($august->platform_fee)->toEqual(0)
        // R50 + R25 flows into md_net; gateway_fee is untouched
        ->and($august->md_net_amount)->toEqual(480)
        // total row still balances
        ->and((float) $august->fee_amount)
        ->toEqual((float) ($august->saprf_fee + $august->platform_fee + $august->surcharge_amount + $august->gateway_fee + $august->md_net_amount));
});

it('leaves registrations on or after the cut-off untouched', function () {
    $september = makeWaiverRegistration($this->match, Carbon::create(2026, 9, 1, 8, 0));

    $this->artisan('saprf:waive-fees-before-date')->assertExitCode(0);

    $september->refresh();

    expect($september->saprf_fee)->toEqual(50)
        ->and($september->platform_fee)->toEqual(25)
        ->and($september->md_net_amount)->toEqual(405);
});

it('is idempotent — a second run does not double-add to md_net', function () {
    $august = makeWaiverRegistration($this->match, Carbon::create(2026, 8, 20));

    $this->artisan('saprf:waive-fees-before-date')->assertExitCode(0);
    $firstMdNet = $august->fresh()->md_net_amount;

    $this->artisan('saprf:waive-fees-before-date')
        ->expectsOutputToContain('Nothing to do')
        ->assertExitCode(0);

    expect($august->fresh()->md_net_amount)->toEqual($firstMdNet);
});

it('supports --dry-run to preview without persisting', function () {
    $august = makeWaiverRegistration($this->match, Carbon::create(2026, 8, 20));

    $this->artisan('saprf:waive-fees-before-date --dry-run')
        ->expectsOutputToContain('DRY RUN')
        ->assertExitCode(0);

    $august->refresh();

    expect($august->saprf_fee)->toEqual(50)
        ->and($august->platform_fee)->toEqual(25)
        ->and($august->md_net_amount)->toEqual(405);
});

it('accepts an explicit --date override', function () {
    // Cut-off in setting is 2026-09-01. Override forces 2026-08-15 instead,
    // so an Aug-20 registration should NOT be waived.
    $august = makeWaiverRegistration($this->match, Carbon::create(2026, 8, 20));

    $this->artisan('saprf:waive-fees-before-date --date=2026-08-15')
        ->expectsOutputToContain('Nothing to do')
        ->assertExitCode(0);

    expect($august->fresh()->saprf_fee)->toEqual(50);
});

it('fails when no cut-off is available', function () {
    Setting::query()->where('key', 'billing_start_date')->update(['value' => '']);
    app(SettingsService::class)->clearCache();

    $this->artisan('saprf:waive-fees-before-date')
        ->expectsOutputToContain('No cut-off date provided')
        ->assertExitCode(1);
});

it('handles a mix of pre- and post-cut-off rows correctly', function () {
    $pre1 = makeWaiverRegistration($this->match, Carbon::create(2026, 8, 5));
    $pre2 = makeWaiverRegistration($this->match, Carbon::create(2026, 8, 25), ['saprf_fee' => 50, 'platform_fee' => 0, 'md_net_amount' => 430]);
    $post = makeWaiverRegistration($this->match, Carbon::create(2026, 9, 2));

    $this->artisan('saprf:waive-fees-before-date')
        ->expectsOutputToContain('2 registration')
        ->assertExitCode(0);

    expect($pre1->fresh()->saprf_fee)->toEqual(0)
        ->and($pre1->fresh()->platform_fee)->toEqual(0)
        ->and($pre2->fresh()->saprf_fee)->toEqual(0)
        ->and($pre2->fresh()->platform_fee)->toEqual(0)
        ->and($pre2->fresh()->md_net_amount)->toEqual(480)
        ->and($post->fresh()->saprf_fee)->toEqual(50)
        ->and($post->fresh()->platform_fee)->toEqual(25);
});
