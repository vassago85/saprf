<?php

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\RegistrationPricingService;
use App\Services\SettingsService;
use Carbon\Carbon;

beforeEach(function () {
    Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $province = Province::where('abbreviation', 'GP')->first();

    $this->service = app(RegistrationPricingService::class);

    $this->match = MatchEvent::create([
        'name' => 'Pricing Test Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 250.00,
        'non_member_fee' => 500.00,
        'lapsed_member_fee' => 375.00,
        'created_by' => User::factory()->create()->id,
    ]);
});

test('active member gets active_member_fee', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-PRICE-001',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);
    $user->refresh();

    $result = $this->service->determineCategoryAndFee($this->match, $user, Carbon::today());

    expect($result['category'])->toBe('active_member')
        ->and($result['fee'])->toBe(250.00);
});

test('non-member pays base fee only (no surcharge)', function () {
    $user = User::factory()->create();

    $result = $this->service->determineCategoryAndFee($this->match, $user, Carbon::today());

    expect($result['category'])->toBe('non_member')
        ->and($result['fee'])->toBe(250.00);
});

test('lapsed member gets lapsed_member_fee', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-PRICE-002',
        'membership_type' => 'paid',
        'status' => 'expired',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subMonth(),
    ]);
    $user->refresh();

    $result = $this->service->determineCategoryAndFee($this->match, $user, Carbon::today());

    expect($result['category'])->toBe('lapsed_member')
        ->and($result['fee'])->toBe(250.00);
});

test('null user pays base fee only (no surcharge)', function () {
    $result = $this->service->determineCategoryAndFee($this->match, null, Carbon::today());

    expect($result['category'])->toBe('non_member')
        ->and($result['fee'])->toBe(250.00);
});

test('calculateBreakdown returns full fee structure for active member', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-BKD-001',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);
    $user->refresh();

    $result = $this->service->calculateBreakdown($this->match, $user, Carbon::today());

    expect($result['category'])->toBe('active_member')
        ->and($result['base_fee'])->toBe(250.00)
        ->and($result['surcharge'])->toBe(0.0)
        ->and($result['total_fee'])->toBe(250.00)
        ->and($result['saprf_fee'])->toBe(50.00)
        ->and($result['platform_fee'])->toBe(0.0)
        ->and($result['gateway_fee'])->toBe(10.75)
        ->and($result['md_net'])->toBe(189.25)
        ->and($result['rates'])->toHaveKeys(['saprf_type', 'saprf_value', 'platform_type', 'platform_value', 'gateway_pct', 'gateway_flat']);
});

test('calculateBreakdown for non-member is the flat R50 SAPRF fee, no surcharge', function () {
    $user = User::factory()->create();

    $result = $this->service->calculateBreakdown($this->match, $user, Carbon::today());

    expect($result['category'])->toBe('non_member')
        ->and($result['base_fee'])->toBe(250.00)
        ->and($result['surcharge'])->toBe(0.0)
        ->and($result['total_fee'])->toBe(250.00)
        ->and($result['saprf_fee'])->toBe(50.00)
        ->and($result['platform_fee'])->toBe(0.0)
        ->and($result['gateway_fee'])->toBe(10.75)
        ->and($result['md_net'])->toBe(189.25);
});

test('junior-division entry uses the junior fee when one is set', function () {
    $this->match->update(['junior_fee' => 120.00]);

    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-JNR-001',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);
    $user->refresh();

    $junior = $this->service->determineCategoryAndFee($this->match, $user, Carbon::today(), 'junior');
    $open = $this->service->determineCategoryAndFee($this->match, $user, Carbon::today(), 'open');

    expect($junior['fee'])->toBe(120.00)
        ->and($junior['base_fee'])->toBe(120.00)
        ->and($open['fee'])->toBe(250.00);
});

test('junior division falls back to the entry fee when no junior fee is set', function () {
    $user = User::factory()->create();

    $result = $this->service->determineCategoryAndFee($this->match, $user, Carbon::today(), 'junior');

    expect($result['fee'])->toBe(250.00);
});

test('calculateBreakdown md_net equals total minus all deductions', function () {
    $user = User::factory()->create();

    $result = $this->service->calculateBreakdown($this->match, $user, Carbon::today());

    $expectedNet = $result['total_fee'] - $result['saprf_fee'] - $result['platform_fee']
        - $result['surcharge'] - $result['gateway_fee'];

    expect($result['md_net'])->toBe(round($expectedNet, 2));
});

// ── Billing grace period ─────────────────────────────────────────────

function setBillingStartDate(?string $date): void
{
    Setting::updateOrCreate(['key' => 'billing_start_date'], ['value' => $date ?? '']);
    app(SettingsService::class)->clearCache();
}

test('registrations before billing_start_date waive SAPRF + platform fees', function () {
    setBillingStartDate('2026-09-01');
    // Also switch the platform fee back on so we can prove it is waived too.
    Setting::updateOrCreate(['key' => 'platform_fee_type'], ['value' => 'fixed']);
    Setting::updateOrCreate(['key' => 'platform_fee_value'], ['value' => '25']);
    app(SettingsService::class)->clearCache();

    $user = User::factory()->create();
    Carbon::setTestNow(Carbon::create(2026, 8, 15, 10, 0, 0));

    $result = $this->service->calculateBreakdown($this->match, $user, Carbon::today());

    Carbon::setTestNow();

    expect($result['fee_waived'])->toBeTrue()
        ->and($result['saprf_fee'])->toBe(0.0)
        ->and($result['platform_fee'])->toBe(0.0)
        // total is unchanged; the waived fees flow to md_net
        ->and($result['total_fee'])->toBe(250.00)
        ->and($result['md_net'])->toBe(round(250.00 - 10.75, 2));
});

test('registrations on or after billing_start_date charge the full SAPRF fee', function () {
    setBillingStartDate('2026-09-01');

    $user = User::factory()->create();
    Carbon::setTestNow(Carbon::create(2026, 9, 1, 0, 0, 0));

    $result = $this->service->calculateBreakdown($this->match, $user, Carbon::today());

    Carbon::setTestNow();

    expect($result['fee_waived'])->toBeFalse()
        ->and($result['saprf_fee'])->toBe(50.00)
        ->and($result['md_net'])->toBe(189.25);
});

test('explicit registeredAt overrides now() so applyCategory of an August entry stays waived in September', function () {
    setBillingStartDate('2026-09-01');

    $user = User::factory()->create();
    Carbon::setTestNow(Carbon::create(2026, 9, 15, 12, 0, 0));

    $result = $this->service->calculateBreakdown(
        $this->match,
        $user,
        Carbon::today(),
        null,
        null,
        Carbon::create(2026, 8, 20, 14, 0, 0),
    );

    Carbon::setTestNow();

    expect($result['fee_waived'])->toBeTrue()
        ->and($result['saprf_fee'])->toBe(0.0);
});

test('empty billing_start_date disables the waiver (fail-safe)', function () {
    setBillingStartDate('');

    $user = User::factory()->create();
    Carbon::setTestNow(Carbon::create(1970, 1, 1));

    $result = $this->service->calculateBreakdown($this->match, $user, Carbon::today());

    Carbon::setTestNow();

    expect($result['fee_waived'])->toBeFalse()
        ->and($result['saprf_fee'])->toBe(50.00);
});
