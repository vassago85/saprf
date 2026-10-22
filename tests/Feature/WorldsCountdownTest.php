<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedRoles();
    Carbon::setTestNow('2026-10-22 08:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('counts down to 1 August 2028 until an exact date is saved', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('1 August 2028', false)
        ->assertSee('Days', false)
        ->assertSee('data-countdown-target="2028-08-01T00:00:00+02:00"', false);
});

it('uses the site setting when the exact start is saved', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->put(route('site-settings.update'), worldsCountdownSettings([
            'worlds_countdown_at' => '2028-08-14T08:00',
        ]))
        ->assertRedirect(route('site-settings.index'));

    expect(app(SettingsService::class)->get('worlds_countdown_at'))->toBe('2028-08-14 08:00:00');

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('14 August 2028, 08:00', false)
        ->assertSee('data-countdown-target="2028-08-14T08:00:00+02:00"', false);
});

it('says the championship is underway once the start has passed', function () {
    Setting::updateOrCreate(
        ['key' => 'worlds_countdown_at'],
        ['value' => '2026-01-01 00:00:00', 'description' => 'test'],
    );
    app(SettingsService::class)->clearCache();

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('The championship is underway', false)
        ->assertDontSee('data-countdown-target', false);
});

function worldsCountdownSettings(array $overrides = []): array
{
    return array_merge([
        'non_member_surcharge' => '250',
        'lapsed_member_surcharge' => '150',
        'withdrawal_admin_fee' => '100',
        'withdrawal_deadline_hours' => '72',
        'division_single_select' => '1',
        'saprf_fee_type' => 'fixed',
        'saprf_fee_value' => '50',
        'membership_platform_fee_pct' => '2.5',
        'estimated_gateway_fee_percentage' => '3.5',
        'estimated_gateway_flat_fee' => '2.00',
        'payfast_sandbox' => '1',
        'payments_enabled' => '1',
        'notifications_enabled' => '1',
    ], $overrides);
}
