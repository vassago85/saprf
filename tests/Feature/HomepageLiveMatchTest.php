<?php

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;

/**
 * The homepage used to drop today's matches once midnight had passed and hid
 * registration-closed matches entirely. These tests lock down the new
 * `live` / `data` split so a weekend national stays on the home panel while
 * it is actually running, and future `closed` matches still show up.
 */
beforeEach(function () {
    seedRoles();
    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->creator = User::factory()->create();
});

function makeHomepageMatch(array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'Centrefire WC 2-Day National',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => (string) now()->year,
        'province_id' => test()->province->id,
        'match_date' => Carbon::today(),
        'status' => 'open',
        'published' => true,
        'match_director' => test()->creator->name,
        'active_member_fee' => 500,
        'created_by' => test()->creator->id,
    ], $overrides));
}

it('exposes todays match in `live` even when registration has closed', function () {
    $live = makeHomepageMatch([
        'name' => 'Weekend National',
        'match_date' => Carbon::today(),
        'match_end_date' => Carbon::tomorrow(),
        'status' => 'closed', // registration already closed, match still running
    ]);

    $json = $this->getJson('/api/v1/matches/upcoming')->assertOk()->json();

    expect(collect($json['live'])->pluck('id'))->toContain($live->id);
    expect(collect($json['data'])->pluck('id'))->not->toContain($live->id);
});

it('keeps future `closed` matches in `data` (not just `open`)', function () {
    $future = makeHomepageMatch([
        'name' => 'Closed Next Week',
        'match_date' => Carbon::today()->addWeek(),
        'status' => 'closed',
    ]);

    $json = $this->getJson('/api/v1/matches/upcoming')->assertOk()->json();

    expect(collect($json['data'])->pluck('id'))->toContain($future->id);
    expect(collect($json['live'])->pluck('id'))->not->toContain($future->id);
});

it('drops matches that ended yesterday', function () {
    $past = makeHomepageMatch([
        'name' => 'Finished Yesterday',
        'match_date' => Carbon::today()->subDays(2),
        'match_end_date' => Carbon::yesterday(),
        'status' => 'closed',
    ]);

    $json = $this->getJson('/api/v1/matches/upcoming')->assertOk()->json();

    expect(collect($json['live'])->pluck('id'))->not->toContain($past->id);
    expect(collect($json['data'])->pluck('id'))->not->toContain($past->id);
});
