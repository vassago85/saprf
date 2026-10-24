<?php

namespace App\Services;

use App\Models\MatchEvent;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for the four "matches this season" counters that
 * sit above the public standings table. The audit (24 Oct 2026) found the
 * old welcome/standings pages were double-counting and leaking matches on
 * today — e.g. "21 total, 15 completed, 4 remaining" with 2 missing. This
 * service partitions every published, non-cancelled match in a series /
 * season into exactly one of three buckets so the three bucket counts
 * always sum to the season total:
 *
 *   completed    status = completed
 *   in_progress  not completed, effective end-date is today or earlier
 *                (so a 2-day national on today + yesterday falls here,
 *                 and a 1-day on today also falls here)
 *   remaining    not completed, match_date is in the future (strictly > today)
 *
 * Draft and cancelled matches are deliberately excluded from the season
 * total — a draft is a staging row the MD hasn't published yet, and a
 * cancelled match never happened. The old counter counted drafts in
 * "remaining" which inflated the total; this service does not.
 */
class SeasonMatchCounters
{
    /**
     * @return array{total:int, completed:int, in_progress:int, remaining:int, ranked:int}
     */
    public function for(string $series, string $season, int $rankedShooters = 0): array
    {
        $today = Carbon::today()->toDateString();

        $base = MatchEvent::query()
            ->where('series', $series)
            ->where('season', $season)
            ->where('status', '!=', 'cancelled')
            ->where('published', true);

        $completed = (clone $base)->where('status', 'completed')->count();

        // "In progress" = non-completed matches whose effective end date is
        // on or before today. Covers a 1-day on today AND a 2-day national
        // whose last day is today or yesterday but the MD hasn't flipped
        // the status to completed yet. The subquery with COALESCE picks
        // match_end_date when present, otherwise match_date — the same
        // "effective end" shape used by MatchEvent::scopeLive().
        //
        // The DATE() wrapper is critical on SQLite (and harmless on MySQL /
        // MariaDB): without it, a `match_date` cast as DATETIME compares
        // lexically against the plain-date `?` binding and a match stamped
        // 2026-10-24 00:00:00 is treated as "greater than" the string
        // "2026-10-24", which pushes a match-on-today out of in_progress
        // and into remaining. Pest tests surfaced the drift on SQLite.
        $inProgress = (clone $base)
            ->where('status', '!=', 'completed')
            ->whereRaw('DATE(COALESCE(match_end_date, match_date)) <= DATE(?)', [$today])
            ->count();

        // "Remaining" = non-completed matches that start strictly after
        // today. A match on today sits in "in progress" (above), so this
        // bucket does not include it — that keeps the three buckets
        // disjoint and makes the sum identity hold. Same DATE() reason as
        // above for cross-engine safety.
        $remaining = (clone $base)
            ->where('status', '!=', 'completed')
            ->whereRaw('DATE(match_date) > DATE(?)', [$today])
            ->count();

        $total = $completed + $inProgress + $remaining;

        return [
            'total' => $total,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'remaining' => $remaining,
            'ranked' => $rankedShooters,
        ];
    }
}
