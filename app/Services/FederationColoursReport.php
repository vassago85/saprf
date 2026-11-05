<?php

namespace App\Services;

use App\Models\Standing;
use Illuminate\Support\Collection;

class FederationColoursReport
{
    public const SERIES = ['PRS', 'PR22'];

    /**
     * Provincial standings for one series and season, grouped by the division
     * that was shot and then by the shooter's home province. Each row's
     * percentage is that shooter's provincial points divided by the highest
     * points in the same division and province.
     *
     * Overall rows (no division) are left out, and a Factory standing is never
     * folded into Open.
     *
     * @return array{
     *     series: string,
     *     season: string,
     *     divisions: list<array{
     *         name: string,
     *         provinces: list<array{
     *             name: string,
     *             leader_name: string,
     *             leader_points: float,
     *             rows: list<array{
     *                 rank: int,
     *                 name: string,
     *                 saprf_number: string|null,
     *                 points: float,
     *                 matches_counted: int|null,
     *                 percentage: float|null,
     *                 grade: string|null
     *             }>
     *         }>
     *     }>
     * }
     */
    public function build(string $series, string $season): array
    {
        $standings = Standing::query()
            ->where('series', $series)
            ->where('season', $season)
            ->whereNotNull('province_id')
            ->whereNotNull('division_id')
            ->with(['user.membership', 'province', 'division'])
            ->get();

        $divisions = $standings
            ->groupBy('division_id')
            ->sortBy(function (Collection $rows): array {
                $division = $rows->first()?->division;

                return [
                    $division?->display_order ?? 999,
                    $division?->name ?? '',
                ];
            })
            ->map(function (Collection $divisionRows): array {
                $division = $divisionRows->first()?->division;

                $provinces = $divisionRows
                    ->groupBy('province_id')
                    ->sortBy(fn (Collection $rows): string => $rows->first()?->province?->name ?? '')
                    ->map(fn (Collection $provinceRows): array => $this->provinceGroup($provinceRows))
                    ->values()
                    ->all();

                return [
                    'name' => $division?->name ?? 'Division',
                    'provinces' => $provinces,
                ];
            })
            ->values()
            ->all();

        return [
            'series' => $series,
            'season' => $season,
            'divisions' => $divisions,
        ];
    }

    /**
     * One CSV row per shooter, still split by the division that was shot.
     *
     * @return list<array{
     *     series: string,
     *     season: string,
     *     division: string,
     *     province: string,
     *     rank: int,
     *     name: string,
     *     saprf_number: string|null,
     *     points: float,
     *     matches_counted: int|null,
     *     percentage: float|null,
     *     grade: string|null
     * }>
     */
    public function flat(string $series, string $season): array
    {
        $rows = [];

        foreach ($this->build($series, $season)['divisions'] as $division) {
            foreach ($division['provinces'] as $province) {
                foreach ($province['rows'] as $row) {
                    $rows[] = [
                        'series' => $series,
                        'season' => $season,
                        'division' => $division['name'],
                        'province' => $province['name'],
                        ...$row,
                    ];
                }
            }
        }

        return $rows;
    }

    public static function grade(?float $percentage): ?string
    {
        if ($percentage === null) {
            return null;
        }

        $shown = round($percentage, 2);

        return match (true) {
            $shown >= 95 => 'Gold',
            $shown >= 85 => 'Silver',
            $shown >= 80 => 'Bronze',
            default => null,
        };
    }

    /**
     * @param  Collection<int, Standing>  $provinceRows
     * @return array{
     *     name: string,
     *     leader_name: string,
     *     leader_points: float,
     *     rows: list<array{
     *         rank: int,
     *         name: string,
     *         saprf_number: string|null,
     *         points: float,
     *         matches_counted: int|null,
     *         percentage: float|null,
     *         grade: string|null
     *     }>
     * }
     */
    private function provinceGroup(Collection $provinceRows): array
    {
        $sorted = $provinceRows
            ->sort(function (Standing $a, Standing $b): int {
                $byPoints = (float) $b->points <=> (float) $a->points;

                return $byPoints !== 0
                    ? $byPoints
                    : strcasecmp((string) $a->user?->name, (string) $b->user?->name);
            })
            ->values();

        $leaderPoints = (float) ($sorted->max(fn (Standing $standing): float => (float) $standing->points) ?? 0);
        $leaders = $sorted->filter(
            fn (Standing $standing): bool => abs((float) $standing->points - $leaderPoints) < 0.0005
        );

        $rows = [];
        $position = 0;
        $lastPoints = null;
        $lastRank = 0;

        foreach ($sorted as $standing) {
            $position++;
            $points = (float) $standing->points;

            if ($lastPoints !== null && abs($points - $lastPoints) < 0.0005) {
                $rank = $lastRank;
            } else {
                $rank = $position;
                $lastRank = $position;
            }
            $lastPoints = $points;

            $percentage = $leaderPoints > 0
                ? round(($points / $leaderPoints) * 100, 2)
                : null;

            $rows[] = [
                'rank' => $rank,
                'name' => $standing->user?->name ?? 'Unknown',
                'saprf_number' => $standing->user?->membership?->saprf_number,
                'points' => $points,
                'matches_counted' => $this->matchesCounted($standing),
                'percentage' => $percentage,
                'grade' => self::grade($percentage),
            ];
        }

        return [
            'name' => $sorted->first()?->province?->name ?? 'Province',
            'leader_name' => $leaders
                ->map(fn (Standing $standing): string => $standing->user?->name ?? 'Unknown')
                ->implode(', '),
            'leader_points' => $leaderPoints,
            'rows' => $rows,
        ];
    }

    private function matchesCounted(Standing $standing): ?int
    {
        $breakdown = $standing->pool_breakdown;

        if (! is_array($breakdown) || ! isset($breakdown['scores_counted'])) {
            return null;
        }

        return (int) $breakdown['scores_counted'];
    }
}
