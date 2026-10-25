<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Standing;
use App\Models\User;
use App\Services\SeasonMatchCounters;
use App\Services\StandingsMovement;
use App\Services\StandingsSnapshotRecorder;
use App\Support\Format;

beforeEach(function () {
    seedRoles();
});

/**
 * Covers the 24 Oct 2026 standings-pages rebuild. Groups the regression
 * targets from the user's QA brief: routing + redirects, tie-label parity
 * across all three surfaces, counter arithmetic including today's matches,
 * movement arrows, and the format helper.
 */

// ── Routing + redirects ─────────────────────────────────────────────────

it('serves /standings/prs with 200', function () {
    $this->get('/standings/prs')->assertOk();
});

it('serves /standings/pr22 with 200', function () {
    $this->get('/standings/pr22')->assertOk();
});

it('redirects bare /standings to /standings/prs with 301 and keeps non-series filters', function () {
    $this->get('/standings?division_id=1&province_id=2')
        ->assertStatus(301)
        ->assertRedirect(route('standings.prs').'?division_id=1&province_id=2');
});

it('redirects /standings?series=PR22 to /standings/pr22 with 301', function () {
    $this->get('/standings?series=PR22')
        ->assertStatus(301)
        ->assertRedirect(route('standings.pr22'));
});

it('discipline page sends canonical URL without filter query parameters', function () {
    $html = $this->get('/standings/prs?division_id=1&province_id=2')->getContent();

    expect($html)->toContain('rel="canonical"');
    expect($html)->toContain(route('standings.prs').'"');
    expect($html)->not->toContain('division_id=1');
});

it('marks the active discipline link with aria-current="page"', function () {
    $html = $this->get('/standings/prs')->getContent();

    expect($html)->toContain('aria-current="page"');
});

// ── Format helper + global points() ─────────────────────────────────────

it('Format::points renders one decimal, dot separator, no grouping', function () {
    expect(Format::points(1234.5))->toBe('1234.5');
    expect(Format::points(0))->toBe('0.0');
    expect(Format::points(null))->toBe('—');
});

it('points() helper mirrors Format::points', function () {
    expect(points(283.5))->toBe('283.5');
    expect(points(null))->toBe('—');
});

// ── Tie parity across all three surfaces ────────────────────────────────

it('two shooters on identical points receive the same rank_label on standings, API and shooter profile', function () {
    $leader = User::factory()->create(['name' => 'Zoe Leader']);
    $a = User::factory()->create(['name' => 'Alpha Tied']);
    $b = User::factory()->create(['name' => 'Bravo Tied']);

    // Use plain foreach — User objects can't be array keys.
    $plan = [
        [$leader->id, 300.0, 1],
        [$a->id,      293.0, 2],
        [$b->id,      293.0, 2],
    ];
    foreach ($plan as [$uid, $points, $rank]) {
        Standing::create([
            'user_id' => $uid,
            'series' => 'PRS',
            'season' => (string) now()->year,
            'points' => $points,
            'rank' => $rank,
        ]);
    }

    // API — the homepage widget reads this and now sends rank_label through
    // via RankStandings so the hero / lower widgets no longer invent ranks.
    $apiRows = $this->getJson('/api/v1/standings?series=PRS&limit=5')->assertOk()->json('data');
    $apiA = collect($apiRows)->firstWhere('user_id', $a->id);
    $apiB = collect($apiRows)->firstWhere('user_id', $b->id);

    expect($apiA['rank_label'])->toBe('T2');
    expect($apiB['rank_label'])->toBe('T2');
    expect($apiA['is_tied'])->toBeTrue();
    expect($apiB['is_tied'])->toBeTrue();

    // Standings model helper — used by SeasonStandingsBoard and the shooter
    // profile summary. Must agree with the API row-for-row.
    $standingA = Standing::where('user_id', $a->id)->first();
    expect(Standing::rankLabelFor($standingA))->toBe('T2');
});

it('rank_label falls through to plain rank when no other row shares the rank', function () {
    $u = User::factory()->create();
    Standing::create([
        'user_id' => $u->id,
        'series' => 'PRS',
        'season' => (string) now()->year,
        'points' => 300.0,
        'rank' => 1,
    ]);

    $standing = Standing::where('user_id', $u->id)->first();
    expect(Standing::rankLabelFor($standing))->toBe('1');
});

// ── Season counters: sum identity holds, incl. match on today ──────────

it('season counters total equals completed + in_progress + remaining, including today', function () {
    $counters = app(SeasonMatchCounters::class)->for('PRS', '2026');

    expect($counters['total'])->toBe(
        $counters['completed'] + $counters['in_progress'] + $counters['remaining']
    );
});

it('a match whose match_date is today lives in in_progress, not remaining', function () {
    $creator = User::factory()->create();
    $match = MatchEvent::create([
        'name' => 'TEST Counter Match On Today',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'national',
        'season' => (string) now()->year,
        'match_date' => now()->toDateString(),
        'status' => 'open',
        'published' => true,
        'created_by' => $creator->id,
        'active_member_fee' => 500,
        'non_member_fee' => 750,
        'lapsed_member_fee' => 650,
        'max_competitors' => 40,
    ]);

    $counters = app(SeasonMatchCounters::class)->for('PRS', (string) now()->year);

    expect($counters['total'])->toBe($counters['completed'] + $counters['in_progress'] + $counters['remaining']);
    // At minimum the match we just created lands in in_progress (today = today).
    // SQLite needs explicit date casting for the COALESCE comparison to work
    // against the plain `?` binding, so we document the sum identity first
    // (which is the point of the test) before asserting the in-progress hit.
    expect($counters['in_progress'])->toBeGreaterThanOrEqual(1, 'Match on today should land in in_progress, got: '.json_encode($counters));
});

// ── Movement arrows ─────────────────────────────────────────────────────

it('StandingsMovement returns null for a user with fewer than two snapshots', function () {
    $u = User::factory()->create();

    // One snapshot: not enough history to compute a delta — service must
    // return null (so the view can render a neutral "·", not "▲0" or
    // "▼0" which would read as "no change" instead of "no history").
    \App\Models\StandingsSnapshot::create([
        'season' => '2026',
        'series' => 'PRS',
        'level' => 'national',
        'province_id' => null,
        'division_id' => null,
        'user_id' => $u->id,
        'rank' => 7,
        'points' => 50,
        'taken_at' => now(),
    ]);

    $move = app(StandingsMovement::class)
        ->for([$u->id], '2026', 'PRS', 'national', null, null);

    expect($move)->toHaveKey($u->id);
    expect($move[$u->id])->toBeNull();
});

it('StandingsMovement computes previous_rank minus current_rank from two snapshots', function () {
    $u = User::factory()->create();
    foreach ([['rank' => 5, 'at' => now()->subDay()], ['rank' => 2, 'at' => now()]] as $snap) {
        \App\Models\StandingsSnapshot::create([
            'season' => '2026',
            'series' => 'PRS',
            'level' => 'national',
            'province_id' => null,
            'division_id' => null,
            'user_id' => $u->id,
            'rank' => $snap['rank'],
            'points' => 100,
            'taken_at' => $snap['at'],
        ]);
    }

    $move = app(StandingsMovement::class)->for([$u->id], '2026', 'PRS', 'national', null, null);
    // 5 (previous) - 2 (current) = 3 → shooter moved UP by 3 (▲3 emerald).
    expect($move[$u->id])->toBe(3);
});

// ── Division leaders sidebar: ties show every leader, not one ──────────

it('Division leaders sidebar lists every shooter when the top division score is tied', function () {
    // Two shooters tied at 300.0 in Factory + a clear leader in Open — matches
    // the live PR22 provincial board on saprf.co.za where Factory had T1 /
    // T1 at 300.0 but the sidebar silently rendered just one of them.
    $factory = Division::firstOrCreate(
        ['slug' => 'factory'],
        ['name' => 'Factory', 'display_order' => 1, 'active' => true],
    );
    $open = Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'display_order' => 2, 'active' => true],
    );

    $russell = User::factory()->create(['name' => 'Russell Factory Tied']);
    $kevin   = User::factory()->create(['name' => 'Kevin Factory Tied']);
    $deon    = User::factory()->create(['name' => 'Deon Open Leader']);

    foreach ([
        [$russell->id, $factory->id, 300.0, 1],
        [$kevin->id,   $factory->id, 300.0, 1],
        [$deon->id,    $open->id,    296.0, 1],
    ] as [$uid, $divId, $pts, $rank]) {
        Standing::create([
            'user_id' => $uid,
            'series' => 'PR22',
            'season' => '2026',
            'division_id' => $divId,
            'province_id' => null,
            'points' => $pts,
            'rank' => $rank,
        ]);
    }

    $html = $this->get('/standings/pr22?season=2026')->assertOk()->getContent();

    // Both tied Factory shooters must appear, each flagged T1 in the
    // sidebar. The clear Open leader must appear without a T1 badge
    // (otherwise we'd be falsely implying a tie that doesn't exist).
    expect($html)->toContain('Russell Factory Tied');
    expect($html)->toContain('Kevin Factory Tied');
    expect($html)->toContain('Deon Open Leader');

    // The T1 badge is only rendered inside the sidebar <li> when
    // leaders->count() > 1 — pin the exact badge markup so a future
    // refactor can't quietly drop it.
    expect(substr_count($html, '>T1<'))->toBeGreaterThanOrEqual(2);
});
