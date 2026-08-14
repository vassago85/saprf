<?php

use App\Models\Division;
use App\Models\Province;
use App\Models\Standing;
use App\Models\User;
use App\Services\ShooterStandingsSummaryService;

/**
 * The service backs both the public shooter profile rank cards AND the
 * member dashboard rank cards, so the two views always show the same
 * numbers from the same data source. These tests lock down the shape and
 * the sort order so a future refactor of either page can't silently drift.
 */

beforeEach(function () {
    $this->service = app(ShooterStandingsSummaryService::class);
});

it('returns nothing when the shooter has no standings for the season', function () {
    $user = User::factory()->create();

    $summary = $this->service->build($user, '2026');

    expect($summary)->toHaveCount(0);
});

it('includes a series entry when only a national standing exists', function () {
    $user = User::factory()->create();
    Standing::create([
        'user_id' => $user->id, 'series' => 'PR22', 'season' => '2026',
        'points' => 68.15, 'rank' => 1, 'pool_breakdown' => null,
    ]);

    $summary = $this->service->build($user, '2026');

    expect($summary)->toHaveCount(1);
    expect($summary->first()['series'])->toBe('PR22');
    expect($summary->first()['overall_rank'])->toBe(1);
    expect((float) $summary->first()['overall_points'])->toBe(68.15);
    expect($summary->first()['has_provincial'])->toBeFalse();
    expect($summary->first()['divisions'])->toBe([]);
});

it('exposes national division breakdown ordered by division display_order', function () {
    $user = User::factory()->create();
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 2]);
    $factory = Division::create(['slug' => 'factory', 'name' => 'Factory', 'display_order' => 1]);

    Standing::create([
        'user_id' => $user->id, 'series' => 'PR22', 'season' => '2026',
        'points' => 68.15, 'rank' => 1,
    ]);
    Standing::create([
        'user_id' => $user->id, 'series' => 'PR22', 'season' => '2026',
        'division_id' => $open->id, 'points' => 27.95, 'rank' => 26,
    ]);
    Standing::create([
        'user_id' => $user->id, 'series' => 'PR22', 'season' => '2026',
        'division_id' => $factory->id, 'points' => 67.70, 'rank' => 1,
    ]);

    $entry = $this->service->build($user, '2026')->first();

    // Factory (display_order 1) before Open (display_order 2).
    expect($entry['divisions'])->toHaveCount(2);
    expect($entry['divisions'][0]['name'])->toBe('Factory');
    expect($entry['divisions'][0]['rank'])->toBe(1);
    expect($entry['divisions'][1]['name'])->toBe('Open');
    expect($entry['divisions'][1]['rank'])->toBe(26);
});

it('includes provincial standing and division breakdown for shooters with a province', function () {
    $province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $user = User::factory()->create(['province_id' => $province->id]);
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);

    Standing::create([
        'user_id' => $user->id, 'series' => 'PR22', 'season' => '2026',
        'province_id' => $province->id, 'points' => 296.63, 'rank' => 2,
    ]);
    Standing::create([
        'user_id' => $user->id, 'series' => 'PR22', 'season' => '2026',
        'province_id' => $province->id, 'division_id' => $open->id,
        'points' => 279.45, 'rank' => 2,
    ]);

    $entry = $this->service->build($user, '2026')->first();

    expect($entry['has_provincial'])->toBeTrue();
    expect($entry['province_name'])->toBe('Gauteng');
    expect($entry['provincial_rank'])->toBe(2);
    expect((float) $entry['provincial_points'])->toBe(296.63);
    expect($entry['provincial_divisions'])->toHaveCount(1);
    expect($entry['provincial_divisions'][0]['name'])->toBe('Open');
});

it('surfaces a series when only a provincial standing exists (no national row yet)', function () {
    $province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $user = User::factory()->create(['province_id' => $province->id]);

    Standing::create([
        'user_id' => $user->id, 'series' => 'PR22', 'season' => '2026',
        'province_id' => $province->id, 'points' => 100.0, 'rank' => 4,
    ]);

    $summary = $this->service->build($user, '2026');

    expect($summary)->toHaveCount(1);
    $entry = $summary->first();
    expect($entry['overall_rank'])->toBeNull();
    expect($entry['has_provincial'])->toBeTrue();
    expect($entry['provincial_rank'])->toBe(4);
});

it('does not load provincial data for shooters without a province', function () {
    $user = User::factory()->create(['province_id' => null]);

    Standing::create([
        'user_id' => $user->id, 'series' => 'PR22', 'season' => '2026',
        'points' => 60.0, 'rank' => 8,
    ]);

    $entry = $this->service->build($user, '2026')->first();

    expect($entry['has_provincial'])->toBeFalse();
    expect($entry['provincial_rank'])->toBeNull();
    expect($entry['provincial_divisions'])->toBe([]);
});

it('returns entries for both PRS and PR22 when the shooter placed in both', function () {
    $user = User::factory()->create();

    Standing::create([
        'user_id' => $user->id, 'series' => 'PRS', 'season' => '2026',
        'points' => 320.0, 'rank' => 3,
    ]);
    Standing::create([
        'user_id' => $user->id, 'series' => 'PR22', 'season' => '2026',
        'points' => 68.15, 'rank' => 1,
    ]);

    $summary = $this->service->build($user, '2026');

    expect($summary->pluck('series')->all())->toBe(['PRS', 'PR22']);
});
