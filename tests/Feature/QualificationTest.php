<?php

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\User;
use App\Services\QualificationService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Register YEAR() function for SQLite compatibility
    // (the QualificationService uses YEAR() in a raw query meant for MySQL)
    if (DB::connection()->getDriverName() === 'sqlite') {
        DB::connection()->getPdo()->sqliteCreateFunction('YEAR', function ($date) {
            return $date ? date('Y', strtotime($date)) : null;
        }, 1);
    }

    Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    Province::firstOrCreate(['name' => 'Western Cape'], ['abbreviation' => 'WC']);
    Province::firstOrCreate(['name' => 'Free State'], ['abbreviation' => 'FS']);

    $this->service = app(QualificationService::class);
});

test('qualification status when no rule exists returns 0 required', function () {
    $user = User::factory()->create([
        'province_id' => Province::where('abbreviation', 'GP')->first()->id,
    ]);

    $result = $this->service->getQualificationStatus($user, 'PRS', '2026');

    expect($result['required'])->toBe(0)
        ->and($result['qualified'])->toBeFalse()
        ->and($result['remaining'])->toBe(0);
});

test('counts out-of-province matches correctly', function () {
    $gp = Province::where('abbreviation', 'GP')->first();
    $wc = Province::where('abbreviation', 'WC')->first();
    $fs = Province::where('abbreviation', 'FS')->first();

    $user = User::factory()->create(['province_id' => $gp->id]);
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-QUAL-001',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => now()->addYear(),
    ]);

    $creator = User::factory()->create();

    // Out-of-province match (WC) — should count
    $wcMatch = MatchEvent::create([
        'name' => 'WC National',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $wc->id,
        'match_date' => now()->subMonth(),
        'status' => 'closed',
        'active_member_fee' => 250,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 375,
        'created_by' => $creator->id,
    ]);

    // Out-of-province match (FS) — should count
    $fsMatch = MatchEvent::create([
        'name' => 'FS National',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $fs->id,
        'match_date' => now()->subWeeks(2),
        'status' => 'closed',
        'active_member_fee' => 250,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 375,
        'created_by' => $creator->id,
    ]);

    // Home province match (GP) — should NOT count
    $gpMatch = MatchEvent::create([
        'name' => 'GP National',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $gp->id,
        'match_date' => now()->subWeeks(3),
        'status' => 'closed',
        'active_member_fee' => 250,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 375,
        'created_by' => $creator->id,
    ]);

    foreach ([$wcMatch, $fsMatch, $gpMatch] as $match) {
        Score::create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'shooter_name' => $user->name,
            'raw_score' => 85.000,
            'placement' => 3,
            'division' => 'Open',
            'match_date' => $match->match_date,
            'status' => 'valid',
            'is_member' => true,
        ]);
    }

    QualificationRule::create([
        'series' => 'PRS',
        'season' => '2026',
        'min_out_of_province_matches' => 2,
        'created_by' => $creator->id,
    ]);

    $result = $this->service->getQualificationStatus($user, 'PRS', '2026');

    expect($result['completed'])->toBe(2)
        ->and($result['required'])->toBe(2)
        ->and($result['qualified'])->toBeTrue()
        ->and($result['remaining'])->toBe(0);
});

test('qualification returns correct status when not enough matches', function () {
    $gp = Province::where('abbreviation', 'GP')->first();
    $wc = Province::where('abbreviation', 'WC')->first();

    $user = User::factory()->create(['province_id' => $gp->id]);
    $creator = User::factory()->create();

    $wcMatch = MatchEvent::create([
        'name' => 'WC National Only',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $wc->id,
        'match_date' => now()->subMonth(),
        'status' => 'closed',
        'active_member_fee' => 250,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 375,
        'created_by' => $creator->id,
    ]);

    Score::create([
        'match_id' => $wcMatch->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'raw_score' => 90.000,
        'placement' => 1,
        'division' => 'Open',
        'match_date' => $wcMatch->match_date,
        'status' => 'valid',
        'is_member' => true,
    ]);

    QualificationRule::create([
        'series' => 'PRS',
        'season' => '2026',
        'min_out_of_province_matches' => 3,
        'created_by' => $creator->id,
    ]);

    $result = $this->service->getQualificationStatus($user, 'PRS', '2026');

    expect($result['completed'])->toBe(1)
        ->and($result['required'])->toBe(3)
        ->and($result['qualified'])->toBeFalse()
        ->and($result['remaining'])->toBe(2);
});

test('provincial matches do not count for qualification', function () {
    $gp = Province::where('abbreviation', 'GP')->first();
    $wc = Province::where('abbreviation', 'WC')->first();

    $user = User::factory()->create(['province_id' => $gp->id]);
    $creator = User::factory()->create();

    // Provincial match in WC — should NOT count (only national counts)
    $wcProvincial = MatchEvent::create([
        'name' => 'WC Provincial',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $wc->id,
        'match_date' => now()->subMonth(),
        'status' => 'closed',
        'active_member_fee' => 150,
        'non_member_fee' => 300,
        'lapsed_member_fee' => 225,
        'created_by' => $creator->id,
    ]);

    Score::create([
        'match_id' => $wcProvincial->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'raw_score' => 88.000,
        'placement' => 2,
        'division' => 'Open',
        'match_date' => $wcProvincial->match_date,
        'status' => 'valid',
        'is_member' => true,
    ]);

    QualificationRule::create([
        'series' => 'PRS',
        'season' => '2026',
        'min_out_of_province_matches' => 1,
        'created_by' => $creator->id,
    ]);

    $result = $this->service->getQualificationStatus($user, 'PRS', '2026');

    expect($result['completed'])->toBe(0)
        ->and($result['qualified'])->toBeFalse();
});
