<?php

namespace App\Services;

use App\Models\FederationColourOverride;
use App\Models\Score;
use App\Models\Standing;
use Illuminate\Database\Eloquent\Builder;
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
     * folded into Open. A division or province filter only hides other groups;
     * it does not change who the leader is inside a group.
     *
     * When require_champs is set, a grade is awarded only if the shooter has a
     * valid score at the SA Championships in that same division, or a stored
     * override with a reason.
     *
     * @param  array{
     *     division_id?: int|null,
     *     province_id?: int|null,
     *     require_champs?: bool,
     *     gold?: float,
     *     silver?: float,
     *     bronze?: float
     * }  $options
     * @return array{
     *     series: string,
     *     season: string,
     *     divisions: list<array<string, mixed>>
     * }
     */
    public function build(string $series, string $season, array $options = []): array
    {
        $requireChamps = (bool) ($options['require_champs'] ?? false);
        $thresholds = [
            'gold' => (float) ($options['gold'] ?? 95),
            'silver' => (float) ($options['silver'] ?? 85),
            'bronze' => (float) ($options['bronze'] ?? 80),
        ];

        $standings = $this->standingsQuery($series, $season)
            ->when($options['division_id'] ?? null, fn (Builder $query, int $divisionId) => $query->where('division_id', $divisionId))
            ->when($options['province_id'] ?? null, fn (Builder $query, int $provinceId) => $query->where('province_id', $provinceId))
            ->with(['user.membership', 'province', 'division'])
            ->get();

        $champs = $this->championshipKeys($series, $season, $standings);
        $overrides = $this->overrides($series, $season, $standings);

        $divisions = $standings
            ->groupBy('division_id')
            ->sortBy(function (Collection $rows): array {
                $division = $rows->first()?->division;

                return [
                    $division?->display_order ?? 999,
                    $division?->name ?? '',
                ];
            })
            ->map(function (Collection $divisionRows) use ($requireChamps, $thresholds, $champs, $overrides): array {
                $division = $divisionRows->first()?->division;

                $provinces = $divisionRows
                    ->groupBy('province_id')
                    ->sortBy(fn (Collection $rows): string => $rows->first()?->province?->name ?? '')
                    ->map(fn (Collection $provinceRows): array => $this->provinceGroup(
                        $provinceRows,
                        $requireChamps,
                        $thresholds,
                        $champs,
                        $overrides,
                    ))
                    ->values()
                    ->all();

                return [
                    'id' => $division?->id,
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
     * Divisions and provinces that have a provincial standing, for the filters.
     * This is the unfiltered list so a selected division can be changed.
     *
     * @return array{
     *     divisions: list<array{id: int, name: string}>,
     *     provinces: list<array{id: int, name: string}>
     * }
     */
    public function catalog(string $series, string $season): array
    {
        $standings = $this->standingsQuery($series, $season)
            ->with(['division:id,name,display_order', 'province:id,name'])
            ->get();

        $divisions = $standings
            ->map(fn (Standing $standing) => $standing->division)
            ->filter()
            ->unique('id')
            ->sortBy([['display_order', 'asc'], ['name', 'asc']])
            ->map(fn ($division): array => ['id' => (int) $division->id, 'name' => $division->name])
            ->values()
            ->all();

        $provinces = $standings
            ->map(fn (Standing $standing) => $standing->province)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->map(fn ($province): array => ['id' => (int) $province->id, 'name' => $province->name])
            ->values()
            ->all();

        return [
            'divisions' => $divisions,
            'provinces' => $provinces,
        ];
    }

    /**
     * One CSV row per shooter, still split by the division that was shot.
     *
     * @param  array{
     *     division_id?: int|null,
     *     province_id?: int|null,
     *     require_champs?: bool,
     *     gold?: float,
     *     silver?: float,
     *     bronze?: float
     * }  $options
     * @return list<array<string, mixed>>
     */
    public function flat(string $series, string $season, array $options = []): array
    {
        $rows = [];

        foreach ($this->build($series, $season, $options)['divisions'] as $division) {
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

    public static function grade(?float $percentage, float $gold = 95, float $silver = 85, float $bronze = 80): ?string
    {
        if ($percentage === null) {
            return null;
        }

        $shown = round($percentage, 2);

        return match (true) {
            $shown >= $gold => 'Gold',
            $shown >= $silver => 'Silver',
            $shown >= $bronze => 'Bronze',
            default => null,
        };
    }

    /**
     * @return Builder<Standing>
     */
    private function standingsQuery(string $series, string $season): Builder
    {
        return Standing::query()
            ->where('series', $series)
            ->where('season', $season)
            ->whereNotNull('province_id')
            ->whereNotNull('division_id');
    }

    /**
     * @param  Collection<int, Standing>  $standings
     * @return array<string, true>
     */
    private function championshipKeys(string $series, string $season, Collection $standings): array
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
                    ->where('series_level', 'final')
                    ->where('season', $season);
            })
            ->get(['user_id', 'division_id'])
            ->each(function (Score $score) use (&$keys): void {
                $keys[$score->user_id.'|'.$score->division_id] = true;
            });

        return $keys;
    }

    /**
     * @param  Collection<int, Standing>  $standings
     * @return Collection<string, FederationColourOverride>
     */
    private function overrides(string $series, string $season, Collection $standings): Collection
    {
        $userIds = $standings->pluck('user_id')->filter()->unique()->all();

        if ($userIds === []) {
            return collect();
        }

        return FederationColourOverride::query()
            ->where('series', $series)
            ->where('season', $season)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn (FederationColourOverride $override): string => $override->user_id.'|'.$override->division_id);
    }

    /**
     * @param  Collection<int, Standing>  $provinceRows
     * @param  array{gold: float, silver: float, bronze: float}  $thresholds
     * @param  array<string, true>  $champs
     * @param  Collection<string, FederationColourOverride>  $overrides
     * @return array<string, mixed>
     */
    private function provinceGroup(
        Collection $provinceRows,
        bool $requireChamps,
        array $thresholds,
        array $champs,
        Collection $overrides,
    ): array {
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

            $key = $standing->user_id.'|'.$standing->division_id;
            $shotChamps = isset($champs[$key]);
            $override = $overrides->get($key);
            $eligible = ! $requireChamps || $shotChamps || $override !== null;

            $rows[] = [
                'user_id' => (int) $standing->user_id,
                'division_id' => (int) $standing->division_id,
                'rank' => $rank,
                'name' => $standing->user?->name ?? 'Unknown',
                'saprf_number' => $standing->user?->membership?->saprf_number,
                'points' => $points,
                'matches_counted' => $this->matchesCounted($standing),
                'percentage' => $percentage,
                'shot_champs' => $shotChamps,
                'override_id' => $override?->id,
                'override_reason' => $override?->reason,
                'grade' => $eligible ? self::grade($percentage, $thresholds['gold'], $thresholds['silver'], $thresholds['bronze']) : null,
            ];
        }

        return [
            'id' => $sorted->first()?->province_id,
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
