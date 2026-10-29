<?php

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\RegistrationPricingService;
use App\Services\SettingsService;
use Carbon\Carbon;

/**
 * Covers the "Club entry" fee bracket introduced for club and provincial
 * matches: non-members pay a lower, surcharge-free club price; SAPRF and
 * the platform each take 3% when the shooter pays through PayFast; both
 * sides zero out when the match director collects the money directly.
 *
 * National matches must keep the standard non-member surcharge path — this
 * suite is the regression harness for that too.
 */

beforeEach(function () {
    Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->province = Province::where('abbreviation', 'GP')->first();
    $this->service = app(RegistrationPricingService::class);
    $this->creator = User::factory()->create();
    // Reset the fixed platform-fee tweak that lives in settings so each
    // test runs against the stock percentage model.
    Setting::updateOrCreate(['key' => 'platform_fee_type'], ['value' => 'percentage']);
    Setting::updateOrCreate(['key' => 'platform_fee_value'], ['value' => '5']);
    Setting::updateOrCreate(['key' => 'club_entry_saprf_percent'], ['value' => '3']);
    Setting::updateOrCreate(['key' => 'club_entry_platform_percent'], ['value' => '3']);
    app(SettingsService::class)->clearCache();
});

function clubMatch(array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'Clubbie',
        'match_type' => 'PRS',
        'series_level' => 'club',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => test()->province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 300.00,
        'non_member_fee' => 600.00,
        'lapsed_member_fee' => 450.00,
        'club_entry_fee' => 200.00,
        'created_by' => test()->creator->id,
    ], $overrides));
}

test('non-member on a club match is categorised as club_entry', function () {
    $match = clubMatch();
    $user = User::factory()->create();

    $result = $this->service->determineCategoryAndFee($match, $user, Carbon::today());

    expect($result['category'])->toBe('club_entry')
        ->and($result['fee'])->toBe(200.00);
});

test('non-member on a provincial match is categorised as club_entry', function () {
    $match = clubMatch(['series_level' => 'provincial']);
    $user = User::factory()->create();

    $result = $this->service->determineCategoryAndFee($match, $user, Carbon::today());

    expect($result['category'])->toBe('club_entry')
        ->and($result['fee'])->toBe(200.00);
});

test('blank club_entry_fee falls back to the member fee', function () {
    $match = clubMatch(['club_entry_fee' => null]);
    $user = User::factory()->create();

    $result = $this->service->determineCategoryAndFee($match, $user, Carbon::today());

    expect($result['category'])->toBe('club_entry')
        ->and($result['fee'])->toBe(300.00);
});

test('online club entry takes 3% SAPRF, 3% platform, and gateway at cost', function () {
    $match = clubMatch();
    $user = User::factory()->create();

    $result = $this->service->calculateBreakdown($match, $user, Carbon::today());

    $base = 200.00;
    $gateway = round($base * 0.035 + 2, 2); // config default: 3.5% + R2

    expect($result['category'])->toBe('club_entry')
        ->and($result['base_fee'])->toBe($base)
        ->and($result['total_fee'])->toBe($base)
        ->and($result['surcharge'])->toBe(0.0)
        ->and($result['saprf_fee'])->toBe(round($base * 0.03, 2))
        ->and($result['platform_fee'])->toBe(round($base * 0.03, 2))
        ->and($result['gateway_fee'])->toBe($gateway)
        ->and($result['md_net'])->toBe(round($base - ($base * 0.03) - ($base * 0.03) - $gateway, 2));
});

test('director-collected club entry zeros SAPRF, platform, and gateway', function () {
    $match = clubMatch();
    $user = User::factory()->create();

    $result = $this->service->calculateBreakdown(
        $match,
        $user,
        Carbon::today(),
        null,
        null,
        null,
        RegistrationPricingService::COLLECTED_BY_DIRECTOR,
    );

    expect($result['category'])->toBe('club_entry')
        ->and($result['base_fee'])->toBe(200.00)
        ->and($result['total_fee'])->toBe(200.00)
        ->and($result['saprf_fee'])->toBe(0.0)
        ->and($result['platform_fee'])->toBe(0.0)
        ->and($result['gateway_fee'])->toBe(0.0)
        ->and($result['md_net'])->toBe(200.00);
});

test('national matches still charge the non-member surcharge', function () {
    Setting::updateOrCreate(['key' => 'non_member_surcharge'], ['value' => '100']);
    app(SettingsService::class)->clearCache();

    $match = clubMatch([
        'series_level' => 'national',
        'non_member_fee' => 400.00,
    ]);
    $user = User::factory()->create();

    $result = $this->service->calculateBreakdown($match, $user, Carbon::today());

    // National match for a non-member must stay on the non_member path,
    // not drop into club_entry.
    expect($result['category'])->toBe('non_member')
        ->and($result['base_fee'])->toBe(300.00)
        ->and($result['surcharge'])->toBe(100.00)
        ->and($result['total_fee'])->toBe(400.00);
});

test('active member on a club match stays on the active_member bracket', function () {
    $match = clubMatch();
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'CLUB-ACTIVE-01',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);
    $user->refresh();

    $result = $this->service->determineCategoryAndFee($match, $user, Carbon::today());

    expect($result['category'])->toBe('active_member')
        ->and($result['fee'])->toBe(300.00);
});

test('lapsed member on a club match stays on the lapsed_member bracket', function () {
    $match = clubMatch();
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'CLUB-LAPSED-01',
        'membership_type' => 'paid',
        'status' => 'expired',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subMonth(),
    ]);
    $user->refresh();

    $result = $this->service->determineCategoryAndFee($match, $user, Carbon::today());

    expect($result['category'])->toBe('lapsed_member');
});

test('non-members can see the public match registration page', function () {
    $match = clubMatch();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('events.show', $match))
        ->assertOk();
});

test('non-members get a 403 on the membership certificate', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('membership.certificate'))
        ->assertForbidden();
});

test('non-members get a 403 on the activity report', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('membership.activity-report'))
        ->assertForbidden();
});

test('non-members get a 403 on the kit index pages', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('rifle-configurations.index'))
        ->assertForbidden();
    $this->actingAs($user)
        ->get(route('ammo-loads.index'))
        ->assertForbidden();
    $this->actingAs($user)
        ->get(route('barrels.index'))
        ->assertForbidden();
    $this->actingAs($user)
        ->get(route('load-tools.index'))
        ->assertForbidden();
});

test('active members pass the member-only gate', function () {
    $user = User::factory()->create();
    giveActiveMembership($user);

    $this->actingAs($user)
        ->withoutMiddleware(\App\Http\Middleware\EnsureProfileComplete::class)
        ->get(route('rifle-configurations.index'))
        ->assertOk();
});
