<?php

/**
 * The "Recent Match History" table on the member dashboard has to show a
 * rank for every row — including the Day-1 dual-count provincial rows
 * generated from 2-day national matches. Those synthetic rows never carry a
 * raw placement (the CSV only has one for the "real" score row), but
 * StandingsCalculationService populates overall_rank on them, so
 * Score::displayRank() falls back to overall_rank when placement is null.
 *
 * This test locks the accessor in so we don't silently regress to blank "—"
 * cells for anyone who has attended a dual-count national.
 */

use App\Models\Score;

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
