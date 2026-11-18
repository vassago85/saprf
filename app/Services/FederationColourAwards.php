<?php

namespace App\Services;

use App\Models\Score;
use App\Models\Standing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * National federation colour awards for one series and season.
 *
 * Every paid-up member with a national series log appears on the list so
 * the leaderboard is honest all season. The colour itself (Gold / Silver
 * / Bronze) is only earned when the shooter has also shot a qualifying
 * match, which is either:
 *   - the one-day national that counts as the provincial in their home
 *     province (stored either as a one-day national or as the day-1
 *     provincial sibling of a two-day national), or
 *   - the SA Championships in that division — either the completed
 *     `series_level = 'final'` match, or (while the champs is still
 *     running on day 1) the provincial sibling whose parent is that
 *     final. Home province is irrelevant for the champs route,
 *     because champs is a national event and anyone who travelled to
 *     it has already proven their qualifying attendance.
 *
 * A shooter sitting in a colour band without a qualifying match has
 * `award = null` and `pending_award` set to the band they would earn
 * once they do qualify. The public report shows a dash in the Award
 * column for these rows; the shooter's profile shows a "Pending Gold"
 * / "Pending Silver" / "Pending Bronze" pill.
 */
class FederationColourAwards
{
    public const SERIES = ['PRS', 'PR22'];

    /**
     * @return array{
     *     series: string,
     *     season: string,
     *     divisions: list<array<string, mixed>>
     * }
     */
    public function build(string $series, string $season, ?int $divisionId = null): array
    {
        $standings = Standing::query()
            ->where('series', $series)
            ->where('season', $season)
            ->whereNull('province_id')
            ->whereNotNull('division_id')
            ->when($divisionId, fn (Builder $query) => $query->where('division_id', $divisionId))
            ->with(['user.membership', 'division'])
            ->get();

        $eligible = $this->eligibleKeys($series, $season, $standings);

        $divisions = $standings
            ->groupBy('division_id')
            ->sortBy(function (Collection $rows): array {
                $division = $rows->first()?->division;

                return [
                    $division?->display_order ?? 999,
                    $division?->name ?? '',
                ];
            })
            ->map(fn (Collection $rows): ?array => $this->divisionGroup($rows, $eligible))
            ->filter()
            ->values()
            ->all();

        return [
            'series' => $series,
            'season' => $season,
            'divisions' => $divisions,
        ];
    }

    /**
     * Colour entries for one shooter in one season. Includes both earned
     * colours (`pending = false`) and provisional ones they would earn
     * once they shoot a qualifying match (`pending = true`). Rows below
     * the bronze threshold are omitted — they have no colour to show.
     *
     * @return list<array{
     *     series: string,
     *     series_label: string,
     *     season: string,
     *     division: string,
     *     award: string,
     *     pending: bool,
     *     weighted_percentage: float
     * }>
     */
    public function forShooter(int $userId, string $season): array
    {
        $awards = [];

        foreach (self::SERIES as $series) {
            foreach ($this->build($series, $season)['divisions'] as $division) {
                foreach ($division['rows'] as $row) {
                    if ((int) $row['user_id'] !== $userId) {
                        continue;
                    }

                    $colour = $row['award'] ?? $row['pending_award'] ?? null;

                    if ($colour === null) {
                        continue;
                    }

                    $awards[] = [
                        'series' => $series,
                        'series_label' => $series === 'PR22' ? 'PR22' : 'Centrefire',
                        'season' => $season,
                        'division' => (string) $division['name'],
                        'award' => (string) $colour,
                        'pending' => $row['award'] === null,
                        'weighted_percentage' => (float) $row['weighted_percentage'],
                    ];
                }
            }
        }

        return $awards;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function catalog(string $series, string $season): array
    {
        return Standing::query()
            ->where('series', $series)
            ->where('season', $season)
            ->whereNull('province_id')
            ->whereNotNull('division_id')
            ->with('division:id,name,display_order')
            ->get()
            ->map(fn (Standing $standing) => $standing->division)
            ->filter()
            ->unique('id')
            ->sortBy([['display_order', 'asc'], ['name', 'asc']])
            ->map(fn ($division): array => ['id' => (int) $division->id, 'name' => $division->name])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function flat(string $series, string $season, ?int $divisionId = null): array
    {
        $rows = [];

        foreach ($this->build($series, $season, $divisionId)['divisions'] as $division) {
            foreach ($division['rows'] as $row) {
                $rows[] = [
                    'series' => $series,
                    'season' => $season,
                    'division' => $division['name'],
                    ...$row,
                ];
            }
        }

        return $rows;
    }

    public static function band(?float $percentage): ?string
    {
        if ($percentage === null) {
            return null;
        }

        $shown = round($percentage, 2);

        return match (true) {
            $shown >= 95 => 'Gold',
            $shown >= 90 => 'Silver',
            $shown >= 85 => 'Bronze',
            default => null,
        };
    }

    public static function seriesLabel(string $series): string
    {
        return $series === 'PR22' ? 'PR22' : 'Centrefire';
    }

    /**
     * @param  Collection<int, Standing>  $standings
     * @return array<string, true>
     */
    private function eligibleKeys(string $series, string $season, Collection $standings): array
    {
        $userIds = $standings->pluck('user_id')->filter()->unique()->all();
        $divisionIds = $standings->pluck('division_id')->filter()->unique()->all();

        if ($userIds === [] || $divisionIds === []) {
            return [];
        }

        $keys = [];

        Score::query()
            ->where('status', 'valid')
            ->whereIn('user_id', $userIds)
            ->whereIn('division_id', $divisionIds)
            ->whereHas('match', function (Builder $query) use ($series, $season): void {
                $query->where('series', $series)
                    ->where('season', $season)
                    ->where(function (Builder $level): void {
                        $level->where(function (Builder $national): void {
                            $national->where('series_level', 'national')
                                ->where(function (Builder $oneDay): void {
                                    $oneDay->whereNull('match_end_date')
                                        ->orWhereColumn('match_end_date', 'match_date');
                                });
                        })->orWhere(function (Builder $sibling): void {
                            $sibling->where('series_level', 'provincial')
                                ->whereNotNull('source_national_match_id');
                        })->orWhere('series_level', 'final');
                    });
            })
            ->with([
                'match:id,province_id,series_level,source_national_match_id',
                'match.sourceNationalMatch:id,series_level',
                'user:id,province_id',
            ])
            ->get(['id', 'user_id', 'division_id', 'match_id'])
            ->each(function (Score $score) use (&$keys): void {
                $match = $score->match;
                $isChampsFinal = $match?->series_level === 'final';
                $isChampsDay1Sibling = $match?->series_level === 'provincial'
                    && $match?->sourceNationalMatch?->series_level === 'final';

                // SA Championships qualify a shooter regardless of where they
                // live — both the completed two-day `final` and its day-1
                // provincial sibling (used while the champs is still running).
                // Champs is a national event, so home province is irrelevant.
                if ($isChampsFinal || $isChampsDay1Sibling) {
                    $keys[$score->user_id.'|'.$score->division_id] = true;

                    return;
                }

                // One-day national / day-1 sibling of a regular two-day
                // national: the match must have been hosted in the shooter's
                // home province, i.e. it counted as their provincial.
                $home = $score->user?->province_id;
                $matchProvince = $match?->province_id;

                if ($home === null || $matchProvince === null || (int) $home !== (int) $matchProvince) {
                    return;
                }

                $keys[$score->user_id.'|'.$score->division_id] = true;
            });

        return $keys;
    }

    /**
     * @param  Collection<int, Standing>  $rows
     * @param  array<string, true>  $eligible
     * @return array<string, mixed>|null
     */
    private function divisionGroup(Collection $rows, array $eligible): ?array
    {
        $sorted = $rows
            ->sort(function (Standing $a, Standing $b): int {
                $byPoints = round((float) $b->points, 2) <=> round((float) $a->points, 2);

                return $byPoints !== 0
                    ? $byPoints
                    : strcasecmp((string) $a->user?->name, (string) $b->user?->name);
            })
            ->values();

        $reference = round((float) ($sorted->max(fn (Standing $standing): float => (float) $standing->points) ?? 0), 2);
        $division = $sorted->first()?->division;
        $listed = [];
        $position = 0;
        $lastPoints = null;
        $lastRank = 0;

        foreach ($sorted as $standing) {
            // Non-members never appear. Unqualified members DO appear so the
            // leaderboard is a live snapshot of where every shooter sits —
            // their Award column is just left blank until they qualify.
            if (! (bool) $standing->user?->isActiveMember()) {
                continue;
            }

            $qualified = isset($eligible[$standing->user_id.'|'.$standing->division_id]);

            $position++;
            $points = round((float) $standing->points, 2);

            if ($lastPoints !== null && abs($points - $lastPoints) < 0.0005) {
                $rank = $lastRank;
            } else {
                $rank = $position;
                $lastRank = $position;
            }
            $lastPoints = $points;

            $percentage = $reference > 0
                ? round(($points / $reference) * 100, 2)
                : null;

            $band = self::band($percentage);

            $listed[] = [
                'rank' => $rank,
                'user_id' => (int) $standing->user_id,
                'name' => $standing->user?->name ?? 'Unknown',
                'saprf_number' => $standing->user?->membership?->saprf_number,
                'division' => $division?->name ?? 'Division',
                'raw_score' => $points,
                'weighted_percentage' => $percentage,
                'qualified' => $qualified,
                'award' => $qualified ? $band : null,
                'pending_award' => $qualified ? null : $band,
            ];
        }

        if ($listed === []) {
            return null;
        }

        return [
            'id' => $division?->id,
            'name' => $division?->name ?? 'Division',
            'reference_score' => $reference,
            'rows' => $listed,
        ];
    }
}
