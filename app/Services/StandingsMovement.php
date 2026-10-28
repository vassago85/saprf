<?php

namespace App\Services;

use App\Models\StandingsSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Rank-change lookup for the movement arrow column on the public standings
 * table. For each user in the current page, returns `previous_rank -
 * current_rank` based on the two most recent snapshots in the same scope
 * — a positive number means the shooter moved UP the table (▲n emerald),
 * negative means they moved DOWN (▼n amber), zero means no change (–),
 * and null means we only have one snapshot (so nothing to compare against).
 */
class StandingsMovement
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, int|null>
     */
    public function for(
        array $userIds,
        string $season,
        string $series,
        string $level,
        ?int $provinceId,
        ?int $divisionId,
    ): array {
        if ($userIds === []) {
            return [];
        }

        $base = StandingsSnapshot::query()
            ->where('season', $season)
            ->where('series', $series)
            ->where('level', $level)
            ->whereIn('user_id', $userIds);

        if ($provinceId !== null) {
            $base->where('province_id', $provinceId);
        } else {
            $base->whereNull('province_id');
        }

        if ($divisionId !== null) {
            $base->where('division_id', $divisionId);
        } else {
            $base->whereNull('division_id');
        }

        // Pull the two most recent (user_id, taken_at, rank) rows per user
        // in one query via a windowed row_number. MariaDB 10.11 supports
        // window functions; MySQL 8+ does too. The $base Eloquent builder
        // above is kept for clarity — the final SELECT is a raw query
        // because Eloquent 11 can't easily express a ROW_NUMBER() over a
        // WHERE-filtered subquery, and we want everything in one round
        // trip. We mirror the base's WHERE clauses manually below.
        unset($base);

        // `rank` is a reserved word in MySQL 8+ / MariaDB 10+ (it's the
        // SQL:2003 RANK() window function). Backtick it everywhere it
        // appears outside the ROW_NUMBER() call, otherwise the parser
        // trips on `rank, taken_at`.
        $rows = DB::select(
            'SELECT user_id, `rank`, taken_at FROM (
                SELECT user_id, `rank`, taken_at, ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY taken_at DESC) AS rn
                FROM standings_snapshots
                WHERE season = ? AND series = ? AND level = ?'
            .($provinceId !== null ? ' AND province_id = ?' : ' AND province_id IS NULL')
            .($divisionId !== null ? ' AND division_id = ?' : ' AND division_id IS NULL')
            .' AND user_id IN ('.implode(',', array_fill(0, count($userIds), '?')).')
            ) ranked
            WHERE rn <= 2
            ORDER BY user_id, taken_at DESC',
            array_values(array_filter(
                array_merge(
                    [$season, $series, $level],
                    $provinceId !== null ? [$provinceId] : [],
                    $divisionId !== null ? [$divisionId] : [],
                    $userIds,
                ),
                fn ($v) => $v !== null,
            ))
        );

        // Group by user_id — the first row (rn=1) is current rank, rn=2 is
        // previous. Compute movement = previous - current (positive = up).
        $byUser = [];
        foreach ($rows as $row) {
            // Property access on `rank` works fine on stdClass — the
            // backtick quoting above was purely a SQL parser concern.
            $byUser[(int) $row->user_id][] = (int) $row->rank;
        }

        $out = [];
        foreach ($userIds as $uid) {
            $pair = $byUser[$uid] ?? [];
            if (count($pair) < 2) {
                $out[$uid] = null;      // only one snapshot so far

                continue;
            }
            $current = $pair[0];
            $previous = $pair[1];
            $out[$uid] = $previous - $current;
        }

        return $out;
    }
}
