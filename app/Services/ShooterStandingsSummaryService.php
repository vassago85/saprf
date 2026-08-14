<?php

namespace App\Services;

use App\Models\QualificationRule;
use App\Models\Standing;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builds the "how the shooter ranks this season" summary used on both the
 * public shooter profile page and the member dashboard rank cards.
 *
 * For every discipline the shooter has scores in, returns the National +
 * Provincial rank (points + full pool breakdown), plus one row per Division
 * the shooter competed in — because a single shooter can shoot Open in one
 * match and Factory in another and ends up with an independent ranking in
 * each.
 *
 * Extracted from StandingController so the dashboard and public profile
 * always show the same numbers from the same data source. Add a new place
 * that needs "where do I stand this season?" data → call this service, don't
 * duplicate the Standing::where(...) plumbing.
 */
class ShooterStandingsSummaryService
{
    /**
     * @return Collection<int, array{
     *     series: string,
     *     scoring_mode: string,
     *     overall_rank: int|null,
     *     overall_points: float|null,
     *     pool_breakdown: array|null,
     *     divisions: list<array{name: ?string, rank: int, points: float}>,
     *     has_provincial: bool,
     *     province_name: ?string,
     *     provincial_rank: int|null,
     *     provincial_points: float|null,
     *     provincial_pool_breakdown: array|null,
     *     provincial_divisions: list<array{name: ?string, rank: int, points: float}>,
     * }>
     */
    public function build(User $user, string $season, array $seriesList = ['PRS', 'PR22']): Collection
    {
        $summary = collect();

        foreach ($seriesList as $series) {
            $overall = Standing::query()
                ->where('user_id', $user->id)
                ->where('season', $season)
                ->where('series', $series)
                ->whereNull('province_id')
                ->whereNull('division_id')
                ->first();

            $seriesRule = QualificationRule::query()
                ->where('season', $season)
                ->where('series', $series)
                ->first();

            [$provincial, $provincialDivisions] = $this->loadProvincial($user, $series, $season);

            if (! $overall && ! $provincial) {
                continue;
            }

            $divisions = $this->loadDivisions($user, $series, $season, provinceId: null);

            $summary->push([
                'series' => $series,
                'scoring_mode' => $seriesRule?->scoring_mode ?? 'best_of_n',

                'overall_rank' => $overall?->rank,
                'overall_points' => $overall?->points,
                'pool_breakdown' => $overall?->pool_breakdown,
                'divisions' => $divisions,

                'has_provincial' => (bool) $provincial,
                'province_name' => $user->province?->name,
                'provincial_rank' => $provincial?->rank,
                'provincial_points' => $provincial?->points,
                'provincial_pool_breakdown' => $provincial?->pool_breakdown,
                'provincial_divisions' => $provincialDivisions,
            ]);
        }

        return $summary;
    }

    /**
     * @return array{0: Standing|null, 1: list<array{name: ?string, rank: int, points: float}>}
     */
    private function loadProvincial(User $user, string $series, string $season): array
    {
        if (! $user->province_id) {
            return [null, []];
        }

        $provincial = Standing::query()
            ->where('user_id', $user->id)
            ->where('season', $season)
            ->where('series', $series)
            ->where('province_id', $user->province_id)
            ->whereNull('division_id')
            ->first();

        $divisions = $this->loadDivisions($user, $series, $season, provinceId: $user->province_id);

        return [$provincial, $divisions];
    }

    /**
     * All division standings for the shooter for a series/season, scoped to
     * either the national table (province null) or a specific provincial
     * table. Sorted by the division's display_order so the UI presentation
     * is stable and matches the standings tables.
     *
     * @return list<array{name: ?string, rank: int, points: float}>
     */
    private function loadDivisions(User $user, string $series, string $season, ?int $provinceId): array
    {
        return Standing::query()
            ->where('user_id', $user->id)
            ->where('season', $season)
            ->where('series', $series)
            ->when($provinceId === null,
                fn ($q) => $q->whereNull('province_id'),
                fn ($q) => $q->where('province_id', $provinceId),
            )
            ->whereNotNull('division_id')
            ->with('division')
            ->get()
            ->sortBy(fn (Standing $s) => $s->division?->display_order ?? 999)
            ->values()
            ->map(fn (Standing $s) => [
                'name' => $s->division?->name,
                'rank' => (int) $s->rank,
                'points' => (float) $s->points,
            ])
            ->all();
    }
}
