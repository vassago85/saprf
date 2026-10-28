<?php

namespace App\Services;

use App\Models\StandingsSnapshot;
use Illuminate\Support\Carbon;
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

        // The all-provinces board re-ranks every province into one table.
        // Snapshots are stored per province (each province's own 1st, 2nd,
        // 3rd), so a province_id IS NULL lookup finds nothing and the
        // column stays blank. Rebuild the combined places from points.
        if ($level === 'provincial' && $provinceId === null) {
            return $this->acrossProvinces($userIds, $season, $series, $divisionId);
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

    /**
     * Movement on the combined provincial table (Province = All).
     *
     * @param  list<int>  $userIds
     * @return array<int, int|null>
     */
    private function acrossProvinces(array $userIds, string $season, string $series, ?int $divisionId): array
    {
        $divisionSql = $divisionId !== null ? 'AND division_id = ?' : 'AND division_id IS NULL';
        $bindings = [$season, $series, 'provincial'];
        if ($divisionId !== null) {
            $bindings[] = $divisionId;
        }

        $times = DB::select(
            'SELECT DISTINCT taken_at FROM standings_snapshots
             WHERE season = ? AND series = ? AND level = ? AND province_id IS NOT NULL '.$divisionSql.'
             ORDER BY taken_at DESC
             LIMIT 2',
            $bindings,
        );

        $blank = array_fill_keys($userIds, null);
        if (count($times) < 2) {
            return $blank;
        }

        $currentAt = Carbon::parse($times[0]->taken_at)->format('Y-m-d H:i:s');
        $previousAt = Carbon::parse($times[1]->taken_at)->format('Y-m-d H:i:s');

        $rows = DB::select(
            'SELECT user_id, points, taken_at FROM standings_snapshots
             WHERE season = ? AND series = ? AND level = ? AND province_id IS NOT NULL '.$divisionSql.'
             AND taken_at IN (?, ?)',
            array_merge($bindings, [$currentAt, $previousAt]),
        );

        $current = [];
        $previous = [];
        foreach ($rows as $row) {
            $bucket = Carbon::parse($row->taken_at)->format('Y-m-d H:i:s') === $currentAt ? 'current' : 'previous';
            if ($bucket === 'current') {
                $current[(int) $row->user_id] = (float) $row->points;
            } else {
                $previous[(int) $row->user_id] = (float) $row->points;
            }
        }

        $currentRanks = $this->ranksFromPoints($current);
        $previousRanks = $this->ranksFromPoints($previous);

        $out = $blank;
        foreach ($userIds as $uid) {
            if (! isset($currentRanks[$uid], $previousRanks[$uid])) {
                continue;
            }
            $out[$uid] = $previousRanks[$uid] - $currentRanks[$uid];
        }

        return $out;
    }

    /**
     * Competition rank from points, matching the public table: equal points
     * to 1 decimal share a place and the next place is skipped.
     *
     * @param  array<int, float>  $pointsByUser
     * @return array<int, int>
     */
    private function ranksFromPoints(array $pointsByUser): array
    {
        $rows = [];
        foreach ($pointsByUser as $userId => $points) {
            $rows[] = ['user_id' => (int) $userId, 'tenths' => (int) round($points * 10)];
        }

        usort($rows, function (array $a, array $b): int {
            return [$b['tenths'], $a['user_id']] <=> [$a['tenths'], $b['user_id']];
        });

        $ranks = [];
        $rank = 0;
        $previous = null;
        foreach (array_values($rows) as $index => $row) {
            if ($previous === null || $row['tenths'] !== $previous) {
                $rank = $index + 1;
                $previous = $row['tenths'];
            }
            $ranks[$row['user_id']] = $rank;
        }

        return $ranks;
    }
}
