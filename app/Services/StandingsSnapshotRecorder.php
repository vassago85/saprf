<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\Standing;
use App\Models\StandingsSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes a batch of standings_snapshots rows every time a match's scores
 * get finalised. The movement arrows on the public standings page compare
 * the two most recent snapshots per shooter — this service is where those
 * rows come from.
 *
 * Deliberately idempotent on taken_at: calling recordForMatch twice for the
 * same scores_finalized_at (double-click, retry) does NOT write duplicate
 * snapshots. That lets the hooks on completeScores + recalculateForMatch
 * both fire for the same finalisation without poisoning the comparison.
 */
class StandingsSnapshotRecorder
{
    /**
     * Snapshot every Standing row that shares the match's series + season.
     * Returns the number of rows inserted (0 when the batch is a duplicate
     * of the most recent one, so retries are safe).
     */
    public function recordForMatch(MatchEvent $match): int
    {
        $series = $match->series;
        $season = $match->season ?: (string) $match->match_date->year;

        // Finalisation timestamp is the authoritative "as of" marker when
        // present. For admin recalcs that fire without a fresh finalise
        // (e.g. membership activation flipping a shooter paid), we fall
        // back to now() so movement still progresses.
        $takenAt = $match->scores_finalized_at
            ? Carbon::parse($match->scores_finalized_at)
            : Carbon::now();

        $takenAtStr = $takenAt->format('Y-m-d H:i:s');

        // Idempotency guard: if the most recent snapshot batch for this
        // (season, series) already matches our taken_at second-for-second,
        // skip. Prevents double writes on completeScores→recalc chains.
        $existing = StandingsSnapshot::query()
            ->where('season', $season)
            ->where('series', $series)
            ->where('taken_at', $takenAtStr)
            ->exists();

        if ($existing) {
            return 0;
        }

        $standings = Standing::query()
            ->where('season', $season)
            ->where('series', $series)
            ->get();

        if ($standings->isEmpty()) {
            return 0;
        }

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $rows = $standings->map(fn (Standing $s) => [
            'season' => $season,
            'series' => $series,
            // Scope convention: province_id null = national,
            // non-null = provincial. The "level" column captures that
            // explicitly so movement lookups don't have to re-derive it.
            'level' => $s->province_id === null ? 'national' : 'provincial',
            'province_id' => $s->province_id,
            'division_id' => $s->division_id,
            'user_id' => (int) $s->user_id,
            'rank' => (int) $s->rank,
            'points' => (float) $s->points,
            'taken_at' => $takenAtStr,
            'triggered_by_match_id' => $match->id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // Chunk to stay well inside MariaDB's max_allowed_packet even on
        // large nationals (300+ shooters × 10 divisions × 9 provinces).
        collect($rows)->chunk(500)->each(fn ($chunk) => DB::table('standings_snapshots')->insert($chunk->all()));

        return count($rows);
    }
}
