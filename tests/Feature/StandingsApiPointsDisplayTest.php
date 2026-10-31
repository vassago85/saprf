<?php

use App\Models\Standing;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

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
    expect($html)->toContain('Array.isArray(payload?.data)');
});

it('still returns shooter rows on the second request when the cache cannot unserialize objects', function () {
    // Production Redis uses this setting. A cached Collection comes back as
    // __PHP_Incomplete_Class, which the homepage treats as data and then
    // renders as a blank card in both standings widgets.
    config(['cache.default' => 'file']);
    Cache::store('file')->flush();

    $user = User::factory()->create(['name' => 'Cache Survivor']);
    Standing::create([
        'user_id' => $user->id,
        'series' => 'PRS',
        'season' => '2026',
        'points' => 283.500,
        'rank' => 1,
    ]);

    $first = $this->getJson('/api/v1/standings?series=PRS&limit=5')->assertOk()->json('data');
    $second = $this->getJson('/api/v1/standings?series=PRS&limit=5')->assertOk()->json('data');

    expect($first)->toBeArray()
        ->and(array_is_list($first))->toBeTrue()
        ->and($first[0]['user']['name'])->toBe('Cache Survivor')
        ->and($first[0]['points_display'])->toBe('283.5');

    expect($second)->toBeArray()
        ->and(array_is_list($second))->toBeTrue()
        ->and($second[0]['user']['name'])->toBe('Cache Survivor')
        ->and($second[0]['points_display'])->toBe('283.5')
        ->and($second[0]['rank_label'])->toBe('1');

    expect(array_keys($second[0]['user']))->not->toContain('email')
        ->and(array_keys($second[0]['user']))->not->toContain('password');

    $third = $this->getJson('/api/v1/standings?series=PRS&limit=5')->assertOk()->json('data');
    expect($third[0]['user']['name'])->toBe('Cache Survivor');
});

it('rebuilds the standings list when the cache holds an unserializable collection', function () {
    config(['cache.default' => 'file']);
    Cache::store('file')->flush();

    $user = User::factory()->create(['name' => 'Rebuilt Shooter']);
    Standing::create([
        'user_id' => $user->id,
        'series' => 'PRS',
        'season' => '2026',
        'points' => 300,
        'rank' => 1,
    ]);

    $stamp = Standing::query()
        ->where('series', 'PRS')
        ->whereNull('province_id')
        ->whereNull('division_id')
        ->selectRaw('COUNT(*) as row_count, MAX(standings.updated_at) as row_updated')
        ->first();

    $key = 'standings.api.list.'.sha1(json_encode([
        'series' => 'PRS',
        'season' => null,
        'province_id' => null,
        'division_id' => null,
        'limit' => 5,
        'rows' => (int) $stamp->row_count,
        'updated' => (string) $stamp->row_updated,
    ]));

    // The shape Redis returns for a Collection cached before this fix.
    Cache::store('file')->put($key, collect([['user' => ['name' => 'Poison']]]), 60);

    $rows = $this->getJson('/api/v1/standings?series=PRS&limit=5')->assertOk()->json('data');

    expect($rows[0]['user']['name'])->toBe('Rebuilt Shooter');
    expect(Cache::store('file')->get($key)[0]['user']['name'])->toBe('Rebuilt Shooter');
});
