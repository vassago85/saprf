<?php

/**
 * The "Recent Match History" table on the member dashboard has to:
 *
 * 1. Show a rank for every row — including the Day-1 dual-count provincial
 *    rows generated from 2-day national matches. Those synthetic rows never
 *    carry a raw placement (the CSV only has one for the "real" score row),
 *    but StandingsCalculationService populates overall_rank on them, so
 *    Score::displayRank() falls back to overall_rank when placement is null.
 *
 * 2. List the shooter's past matches in the order they were shot (newest
 *    match date first), NOT in the order the scores happened to be uploaded
 *    or imported. Imports commonly land months of results in one batch, so
 *    ordering by created_at scrambles the season.
 *
 * These tests lock in both behaviours so we don't silently regress.
 */

use App\Models\MatchEvent;
use App\Models\Score;
use App\Models\User;

it('returns the raw placement when it is set', function () {
    $score = new Score(['placement' => 3, 'overall_rank' => 12]);

    expect($score->displayRank())->toBe(3);
});

it('falls back to overall_rank when the raw placement is null (Day 1 dual-count case)', function () {
    $score = new Score(['placement' => null, 'overall_rank' => 2]);

    expect($score->displayRank())->toBe(2);
});

it('returns null when both placement and overall_rank are unset', function () {
    $score = new Score();

    expect($score->displayRank())->toBeNull();
});

it('treats an explicit zero overall_rank as no rank so the view falls through to a dash', function () {
    // A 0-rank shouldn't be shown as "#0" — it means no rank was computed.
    // We rely on the view's `$rank && $rank <= 3` guard to skip zeroes; this
    // test documents that the accessor happily returns the underlying value
    // and the view is what treats falsy as "no rank".
    $score = new Score(['placement' => 0, 'overall_rank' => 5]);

    // placement takes priority even when 0 (null coalesce, not truthy check)
    expect($score->displayRank())->toBe(0);
});

// ── Chronological order ──────────────────────────────────────────────
//
// The dashboard's recent-match query lives in DashboardController::
// memberDashboard(). We rebuild it here rather than booting the full
// dashboard view because the view depends on MySQL-only SQL bits
// (see ViewModeSwitchTest) that SQLite can't render. Sorting is what
// this test cares about, and sorting is entirely in the query.

it('lists the shooters past matches by match date (newest first), even when scores were uploaded out of order', function () {
    $user = User::factory()->create();

    // Two completed matches, months apart.
    $early = MatchEvent::create([
        'name' => 'Early Open',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'provincial',
        'season' => '2026',
        'match_date' => '2026-02-01',
        'status' => 'completed',
        'created_by' => User::factory()->create()->id,
        'published' => true,
    ]);
    $late = MatchEvent::create([
        'name' => 'Late Classic',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'provincial',
        'season' => '2026',
        'match_date' => '2026-06-01',
        'status' => 'completed',
        'created_by' => User::factory()->create()->id,
        'published' => true,
    ]);

    // Insert the scores with created_at INVERTED versus match_date: the
    // older match's score was uploaded last (e.g. a late import backfill).
    // If the controller sorts by created_at we get the wrong order; only a
    // sort by matches.match_date puts Late Classic first.
    Score::create([
        'match_id' => $late->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'raw_score' => 80,
        'status' => 'valid',
        'is_member' => true,
        'match_date' => $late->match_date,
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ]);
    Score::create([
        'match_id' => $early->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'raw_score' => 70,
        'status' => 'valid',
        'is_member' => true,
        'match_date' => $early->match_date,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Mirror the DashboardController::memberDashboard() query verbatim.
    $recentMatches = $user->scores()
        ->with(['match.province'])
        ->orderByDesc(
            MatchEvent::select('match_date')
                ->whereColumn('matches.id', 'scores.match_id')
                ->limit(1)
        )
        ->limit(10)
        ->get();

    expect($recentMatches->pluck('match.name')->all())
        ->toBe(['Late Classic', 'Early Open']);
});
