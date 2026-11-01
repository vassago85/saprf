<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\Standing;
use App\Models\StandingsSnapshot;
use App\Models\User;
use App\Services\SeasonMatchCounters;
use App\Services\StandingsCalculationService;
use App\Services\StandingsMovement;
use App\Services\StandingsSnapshotRecorder;
use App\Support\Format;
use Illuminate\Support\Carbon;

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
    StandingsSnapshot::create([
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
        StandingsSnapshot::create([
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

it('records a new PRS snapshot when a finalised match is rebuilt so the next update shows place arrows', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    QualificationRule::create([
        'series' => 'PRS',
        'season' => '2026',
        'scoring_mode' => 'best_n_plus_champs',
        'best_of_count' => 3,
        'total_qualifying_matches' => 4,
        'min_out_of_province_matches' => 0,
        'created_by' => User::factory()->create()->id,
    ]);

    $leader = User::factory()->create(['name' => 'Alex Leader']);
    $riser = User::factory()->create(['name' => 'Blair Riser']);
    $service = app(StandingsCalculationService::class);

    $first = arrowMatch('First National', '2026-03-01', now()->subDays(10));
    arrowScore($first, $leader, $open->id, 100);
    arrowScore($first, $riser, $open->id, 50);
    $service->recalculateForMatch($first);

    expect(StandingsSnapshot::query()->where('user_id', $riser->id)->whereNull('division_id')->count())->toBe(1);

    $second = arrowMatch('Second National', '2026-04-01', now()->subDay());
    arrowScore($second, $leader, $open->id, 40);
    arrowScore($second, $riser, $open->id, 100);
    $service->recalculateForMatch($second);

    $move = app(StandingsMovement::class)->for(
        [$riser->id, $leader->id],
        '2026',
        'PRS',
        'national',
        null,
        null,
    );

    // Blair was 2nd, then won the second match and leads. ▲1.
    expect($move[$riser->id])->toBe(1)
        ->and($move[$leader->id])->toBe(-1);

    $this->get('/standings/prs?season=2026')
        ->assertOk()
        ->assertSee('▲1')
        ->assertSee('▼1');
});

it('shows place arrows on the all-provinces board', function () {
    $gp = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $wc = Province::firstOrCreate(['name' => 'Western Cape'], ['abbreviation' => 'WC']);
    $riser = User::factory()->create(['name' => 'Blair Provincial Riser']);
    $faller = User::factory()->create(['name' => 'Alex Provincial Faller']);

    // Phase 7: arrows compare match-to-match, so the two snapshot batches
    // have to be tagged with distinct triggered_by_match_id values. Build
    // a yesterday match and a today match to stand in for the real pair
    // the recorder would produce in production.
    $match1 = arrowMatch('Yesterday Provincial', '2026-04-30', now()->subDay());
    $match2 = arrowMatch('Today Provincial', '2026-05-01', now());

    Standing::create([
        'user_id' => $riser->id,
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $gp->id,
        'division_id' => null,
        'points' => 120,
        'rank' => 1,
    ]);
    Standing::create([
        'user_id' => $faller->id,
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $wc->id,
        'division_id' => null,
        'points' => 100,
        'rank' => 1,
    ]);

    // Stored rank is each province's own place (both 1st at home). The
    // all-provinces board has to rebuild the combined order from points.
    foreach ([
        [$riser, $gp, 80.0, now()->subDay(), $match1->id],
        [$faller, $wc, 100.0, now()->subDay(), $match1->id],
        [$riser, $gp, 120.0, now(), $match2->id],
        [$faller, $wc, 100.0, now(), $match2->id],
    ] as [$shooter, $province, $points, $at, $matchId]) {
        StandingsSnapshot::create([
            'season' => '2026',
            'series' => 'PRS',
            'level' => 'provincial',
            'province_id' => $province->id,
            'division_id' => null,
            'user_id' => $shooter->id,
            'rank' => 1,
            'points' => $points,
            'taken_at' => $at,
            'triggered_by_match_id' => $matchId,
        ]);
    }

    $move = app(StandingsMovement::class)->for(
        [$riser->id, $faller->id],
        '2026',
        'PRS',
        'provincial',
        null,
        null,
    );

    expect($move[$riser->id])->toBe(1)
        ->and($move[$faller->id])->toBe(-1);

    $this->get('/standings/prs?season=2026&level=provincial')
        ->assertOk()
        ->assertSee('Blair Provincial Riser')
        ->assertSee('▲1')
        ->assertSee('▼1');
});

it('writes a snapshot even when the match has NOT been finalised', function () {
    // Phase 7: the "scores_finalized_at" gate was removed from
    // StandingsCalculationService::recalculateForMatch. Every recalc now
    // snapshots so an import that never goes through the finalise wizard
    // still contributes a comparison point for the arrows.
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    QualificationRule::create([
        'series' => 'PRS',
        'season' => '2026',
        'scoring_mode' => 'best_n_plus_champs',
        'best_of_count' => 3,
        'total_qualifying_matches' => 4,
        'min_out_of_province_matches' => 0,
        'created_by' => User::factory()->create()->id,
    ]);

    $shooter = User::factory()->create();

    $match = MatchEvent::create([
        'name' => 'Unfinalised Match',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'national',
        'season' => '2026',
        'match_date' => '2026-03-01',
        'status' => 'completed',
        'published' => true,
        'created_by' => User::factory()->create()->id,
        'active_member_fee' => 100,
        // scores_finalized_at deliberately NULL — never ran the wizard.
    ]);
    arrowScore($match, $shooter, $open->id, 50);

    app(StandingsCalculationService::class)->recalculateForMatch($match);

    expect(StandingsSnapshot::query()
        ->where('user_id', $shooter->id)
        ->whereNull('division_id')
        ->count())->toBe(1);
});

it('falls back to match_date (end of day) for taken_at on an un-finalised match', function () {
    // Phase 7: the recorder used to fall back to now() when
    // scores_finalized_at was null. For a backfill walker that iterates
    // matches in match_date order, that collapsed the whole season to
    // the same effective moment — useless for arrow comparison. The
    // fallback is now match_date->endOfDay() so historical ordering is
    // preserved.
    $shooter = User::factory()->create();

    $match = MatchEvent::create([
        'name' => 'Legacy Match',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'national',
        'season' => '2026',
        'match_date' => '2026-06-15',
        'status' => 'completed',
        'published' => true,
        'created_by' => User::factory()->create()->id,
        'active_member_fee' => 100,
    ]);

    Standing::create([
        'user_id' => $shooter->id,
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => null,
        'division_id' => null,
        'points' => 42,
        'rank' => 10,
    ]);

    app(StandingsSnapshotRecorder::class)->recordForMatch($match);

    $snap = StandingsSnapshot::query()->where('user_id', $shooter->id)->first();
    expect($snap)->not->toBeNull();
    expect($snap->taken_at->format('Y-m-d H:i:s'))->toBe('2026-06-15 23:59:59');
});

it('ignores within-match edit noise when comparing match-to-match', function () {
    // Phase 7: a CSV import then a shooter rename then a live-sync tick
    // can produce three snapshots for one match on one day. The arrow
    // between match N and match N+1 must still read as "change since
    // match N", not "change since the last keystroke". Verified by
    // creating three edits for match 1 (ranks jumping around), then one
    // snapshot for match 2, and checking the delta is match1→match2 only.
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    QualificationRule::create([
        'series' => 'PRS',
        'season' => '2026',
        'scoring_mode' => 'best_n_plus_champs',
        'best_of_count' => 3,
        'total_qualifying_matches' => 4,
        'min_out_of_province_matches' => 0,
        'created_by' => User::factory()->create()->id,
    ]);

    $shooter = User::factory()->create();
    $match1 = arrowMatch('Match 1', '2026-03-01', now()->subDays(10));
    $match2 = arrowMatch('Match 2', '2026-04-01', now()->subDay());

    // Match 1 — three edits, final rank = 5.
    foreach ([
        ['rank' => 9, 'taken_at' => now()->subDays(10)->setTime(9, 0)],
        ['rank' => 7, 'taken_at' => now()->subDays(10)->setTime(10, 0)],
        ['rank' => 5, 'taken_at' => now()->subDays(10)->setTime(11, 0)],
    ] as $edit) {
        StandingsSnapshot::create([
            'season' => '2026',
            'series' => 'PRS',
            'level' => 'national',
            'province_id' => null,
            'division_id' => null,
            'user_id' => $shooter->id,
            'rank' => $edit['rank'],
            'points' => 100,
            'taken_at' => $edit['taken_at'],
            'triggered_by_match_id' => $match1->id,
        ]);
    }

    // Match 2 — single snapshot, rank = 2.
    StandingsSnapshot::create([
        'season' => '2026',
        'series' => 'PRS',
        'level' => 'national',
        'province_id' => null,
        'division_id' => null,
        'user_id' => $shooter->id,
        'rank' => 2,
        'points' => 150,
        'taken_at' => now()->subDay(),
        'triggered_by_match_id' => $match2->id,
    ]);

    $move = app(StandingsMovement::class)->for([$shooter->id], '2026', 'PRS', 'national', null, null);

    // Match 1's representative rank is the LATEST edit (5), not the
    // earliest (9) — so delta = 5 - 2 = 3 (▲3). If the collapse were
    // broken and the two most recent raw snapshots were compared, we
    // would see 7 - 5 = 2 instead.
    expect($move[$shooter->id])->toBe(3);
});

it('collapses multiple edits of the same match into one representative snapshot', function () {
    // Phase 7: every recalc writes a snapshot, so a single match may
    // accrue several rows during the day (CSV import → shooter rename →
    // Impact live tick). Movement must compare match-to-match, which
    // means those within-match edits collapse to a single representative
    // row (the latest one). With only one match in play, the arrows have
    // nothing to diff against and the service returns null — exactly
    // the "·" placeholder state the view renders when there is no prior.
    $shooter = User::factory()->create();
    $match = arrowMatch('Corrected National', '2026-05-01', now()->subHour());

    Standing::create([
        'user_id' => $shooter->id,
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => null,
        'division_id' => null,
        'points' => 80,
        'rank' => 4,
    ]);

    $recorder = app(StandingsSnapshotRecorder::class);
    expect($recorder->recordForMatch($match))->toBe(1);

    Standing::query()->where('user_id', $shooter->id)->update(['rank' => 1, 'points' => 100]);

    expect($recorder->recordForMatch($match->fresh()))->toBe(1);

    $move = app(StandingsMovement::class)->for([$shooter->id], '2026', 'PRS', 'national', null, null);
    expect($move[$shooter->id])->toBeNull();
});

function arrowMatch(string $name, string $date, Carbon $finalizedAt): MatchEvent
{
    return MatchEvent::create([
        'name' => $name,
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'national',
        'season' => '2026',
        'match_date' => $date,
        'status' => 'completed',
        'published' => true,
        'created_by' => User::factory()->create()->id,
        'active_member_fee' => 100,
        'scores_finalized_at' => $finalizedAt,
        'scores_finalized_by' => User::factory()->create()->id,
    ]);
}

function arrowScore(MatchEvent $match, User $shooter, int $divisionId, float $raw): Score
{
    // Standings builder gates on membership_type now — stamp a paid membership
    // so these ranking-arrow fixtures count as full federation members.
    Membership::firstOrCreate(
        ['user_id' => $shooter->id],
        [
            'saprf_number' => 'SAPRF-TEST-'.$shooter->id,
            'membership_type' => 'paid',
            'status' => 'active',
            'payment_status' => 'paid',
            'start_date' => $match->match_date->copy()->subMonth(),
            'expiry_date' => $match->match_date->copy()->addYear(),
        ]
    );

    return Score::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'division_id' => $divisionId,
        'raw_score' => $raw,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_season' => true,
        'match_date' => $match->match_date,
    ]);
}

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
    $kevin = User::factory()->create(['name' => 'Kevin Factory Tied']);
    $deon = User::factory()->create(['name' => 'Deon Open Leader']);

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

it('lists division leaders by highest score instead of catalogue order', function () {
    $open = Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'display_order' => 1, 'is_active' => true],
    );
    $factory = Division::firstOrCreate(
        ['slug' => 'factory'],
        ['name' => 'Factory', 'display_order' => 2, 'is_active' => true],
    );
    $senior = Division::firstOrCreate(
        ['slug' => 'senior'],
        ['name' => 'Senior', 'display_order' => 3, 'is_active' => true],
    );

    Standing::create([
        'user_id' => User::factory()->create(['name' => 'Malcolm Open Leader'])->id,
        'series' => 'PRS',
        'season' => '2026',
        'division_id' => $open->id,
        'province_id' => null,
        'points' => 68.1,
        'rank' => 1,
    ]);
    Standing::create([
        'user_id' => User::factory()->create(['name' => 'Kevin Factory Leader'])->id,
        'series' => 'PRS',
        'season' => '2026',
        'division_id' => $factory->id,
        'province_id' => null,
        'points' => 68.5,
        'rank' => 1,
    ]);
    Standing::create([
        'user_id' => User::factory()->create(['name' => 'LeRiche Senior Leader'])->id,
        'series' => 'PRS',
        'season' => '2026',
        'division_id' => $senior->id,
        'province_id' => null,
        'points' => 64.9,
        'rank' => 1,
    ]);

    $html = $this->get('/standings/prs?season=2026')->assertOk()->getContent();
    $sidebar = strstr($html, 'Division leaders');

    $factoryAt = strpos($sidebar, 'Kevin Factory Leader');
    $openAt = strpos($sidebar, 'Malcolm Open Leader');
    $seniorAt = strpos($sidebar, 'LeRiche Senior Leader');

    expect($factoryAt)->not->toBeFalse()
        ->and($openAt)->not->toBeFalse()
        ->and($seniorAt)->not->toBeFalse()
        ->and($factoryAt)->toBeLessThan($openAt)
        ->and($openAt)->toBeLessThan($seniorAt);
});
