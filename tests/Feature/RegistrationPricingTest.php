<?php

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\User;
use App\Services\RegistrationPricingService;
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
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    $result = $this->service->determineCategoryAndFee($this->match, $user, Carbon::today());

    expect($result['category'])->toBe('active_member')
        ->and($result['fee'])->toBe(250.00);
});

test('non-member gets non_member_fee', function () {
    $user = User::factory()->create();

    $result = $this->service->determineCategoryAndFee($this->match, $user, Carbon::today());

    expect($result['category'])->toBe('non_member')
        ->and($result['fee'])->toBe(500.00);
});

test('lapsed member gets lapsed_member_fee', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-PRICE-002',
        'status' => 'expired',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subMonth(),
    ]);

    $result = $this->service->determineCategoryAndFee($this->match, $user, Carbon::today());

    expect($result['category'])->toBe('lapsed_member')
        ->and($result['fee'])->toBe(375.00);
});

test('null user gets non_member_fee', function () {
    $result = $this->service->determineCategoryAndFee($this->match, null, Carbon::today());

    expect($result['category'])->toBe('non_member')
        ->and($result['fee'])->toBe(500.00);
});
