<?php

use App\Models\Standing;
use App\Models\User;

/**
 * The homepage used to call `parseFloat(entry.points).toFixed(0)` in the
 * browser, which turned 283.5 and 279.7 into 283 and 280 and faked ties.
 * We now format on the server and the welcome view consumes
 * `points_display` so homepage and standings-page values can never drift.
 */
beforeEach(function () {
    seedRoles();
});

it('returns points_display formatted to one decimal', function () {
    $user = User::factory()->create();
    Standing::create([
        'user_id' => $user->id,
        'series' => 'PRS',
        'season' => '2026',
        'points' => 283.500,
        'rank' => 1,
    ]);

    $json = $this->getJson('/api/v1/standings?series=PRS&limit=5')->assertOk()->json();

    expect($json['data'][0]['points_display'])->toBe('283.5');
    expect((float) $json['data'][0]['points'])->toBe(283.5);
});

it('distinguishes 273.4 from 272.6 instead of rounding both to 273', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    Standing::create(['user_id' => $a->id, 'series' => 'PRS', 'season' => '2026', 'points' => 273.400, 'rank' => 1]);
    Standing::create(['user_id' => $b->id, 'series' => 'PRS', 'season' => '2026', 'points' => 272.600, 'rank' => 2]);

    $rows = $this->getJson('/api/v1/standings?series=PRS&limit=5')->assertOk()->json('data');

    $display = collect($rows)->pluck('points_display')->all();
    expect($display)->toContain('273.4');
    expect($display)->toContain('272.6');
});

it('welcome view does not use toFixed(0) on standings points', function () {
    $html = file_get_contents(resource_path('views/welcome.blade.php'));
    expect($html)->not->toContain('toFixed(0)');
});
