<?php

/**
 * The Financial Dashboard's Quick Filter dropdown auto-submits, so `period`
 * tends to end up in the query string even when the user actually wanted to
 * type explicit dates. Prior to this fix, `period=month` clobbered typed
 * `from`/`to` and silently flipped an August query into September.
 *
 * These tests lock in the priority order: explicit dates always win over
 * the dropdown.
 */

use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('respects explicit from/to even when period=month is also submitted', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 3, 8, 0, 0));

    $response = $this->actingAs($this->admin)
        ->get(route('financials.dashboard', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'period' => 'month',
        ]));

    Carbon::setTestNow();

    $response->assertOk();

    expect($response->viewData('from')->toDateString())->toBe('2026-08-01')
        ->and($response->viewData('to')->toDateString())->toBe('2026-08-31');
});

it('falls back to This Month when period=month and no dates are provided', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 3, 8, 0, 0));

    $response = $this->actingAs($this->admin)
        ->get(route('financials.dashboard', ['period' => 'month']));

    Carbon::setTestNow();

    $response->assertOk();

    expect($response->viewData('from')->toDateString())->toBe('2026-09-01')
        ->and($response->viewData('to')->toDateString())->toBe('2026-09-30');
});

it('falls back to season year when period=season and no dates are provided', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('financials.dashboard', [
            'period' => 'season',
            'season_year' => '2026',
        ]));

    $response->assertOk();

    expect($response->viewData('from')->toDateString())->toBe('2026-01-01')
        ->and($response->viewData('to')->toDateString())->toBe('2026-12-31');
});

it('leaves from/to null with no filters (All Time)', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('financials.dashboard'));

    $response->assertOk();

    expect($response->viewData('from'))->toBeNull()
        ->and($response->viewData('to'))->toBeNull();
});

it('accepts an explicit from without a to (open-ended range)', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 3, 8, 0, 0));

    $response = $this->actingAs($this->admin)
        ->get(route('financials.dashboard', [
            'from' => '2026-08-15',
            'period' => 'month',
        ]));

    Carbon::setTestNow();

    $response->assertOk();

    expect($response->viewData('from')->toDateString())->toBe('2026-08-15')
        ->and($response->viewData('to'))->toBeNull();
});
