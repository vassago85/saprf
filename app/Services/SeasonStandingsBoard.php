<?php

namespace App\Services;

use App\Models\Standing;
use Illuminate\Support\Collection;

class SeasonStandingsBoard
{
    public function __construct(private readonly RankStandings $ranker) {}

    /**
     * @return array{0: string, 1: string}
     */
    public function normalizeSort(?string $sort, ?string $direction): array
    {
        $sortable = ['points', 'rank', 'shooter', 'division', 'province'];
        if (! in_array($sort, $sortable, true)) {
            $sort = 'points';
        }
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $sort === 'rank' ? 'asc' : 'desc';
        }

        return [$sort, $direction];
    }

    public function load(
        string $season,
        string $series,
        string $level,
        ?int $divisionId,
        ?int $provinceId,
        ?string $sort,
        ?string $direction,
        ?int $seed,
    ): SeasonStandingsPage {
        [$sort, $direction] = $this->normalizeSort($sort, $direction);

        $query = Standing::query()
            ->with(['user.province', 'user.division', 'province', 'division'])
            ->where('standings.season', $season)
            ->where('standings.series', $series);

        if ($divisionId) {
            $query->where('standings.division_id', $divisionId);
        } else {
            $query->whereNull('standings.division_id');
        }

        if ($level === 'provincial') {
            $query->whereNotNull('standings.province_id');
        } else {
            $query->whereNull('standings.province_id');
        }

        if ($provinceId) {
            if ($level === 'provincial') {
                $query->where('standings.province_id', $provinceId);
            } else {
                $query->whereHas('user', fn ($q) => $q->where('users.province_id', $provinceId));
            }
        }

        $totalRanked = (clone $query)->distinct('user_id')->count('user_id');
        $models = $query->get();
        $showProvinceRank = $level === 'provincial' && $provinceId === null;

        $ordered = $this->applyRanks($models, $seed);

        if ($showProvinceRank) {
            $ordered->groupBy(fn (Standing $standing) => (int) $standing->province_id)
                ->each(function (Collection $group): void {
                    foreach ($this->ranker->rank($this->payload($group), null) as $row) {
                        $row['model']->setAttribute('province_rank_label', $row['rank_label']);
                    }
                });
        }

        return new SeasonStandingsPage(
            $this->applyDisplaySort($ordered, $sort, $direction, $level),
            $totalRanked,
            $sort,
            $direction,
            $showProvinceRank,
        );
    }

    /**
     * @param  Collection<int, Standing>  $models
     * @return Collection<int, Standing>
     */
    private function applyRanks(Collection $models, ?int $seed): Collection
    {
        return $this->ranker->rank($this->payload($models), $seed)
            ->map(function (array $row) {
                /** @var Standing $model */
                $model = $row['model'];
                $model->rank = $row['rank'];
                $model->setAttribute('rank_label', $row['rank_label']);
                $model->setAttribute('is_tied', $row['is_tied']);

                return $model;
            })
            ->values();
    }

    /**
     * @param  Collection<int, Standing>  $models
     * @return Collection<int, array<string, mixed>>
     */
    private function payload(Collection $models): Collection
    {
        return $models->map(fn (Standing $standing) => [
            'model' => $standing,
            'points' => (float) $standing->points,
            'name' => (string) ($standing->user?->name ?? ''),
            'id' => (int) ($standing->user_id ?? $standing->id),
        ])->values();
    }

    /**
     * @param  Collection<int, Standing>  $rows
     * @return Collection<int, Standing>
     */
    private function applyDisplaySort(Collection $rows, string $sort, string $direction, string $level): Collection
    {
        if ($sort === 'points' || $sort === 'rank') {
            $competitionOrder = $sort === 'points'
                ? $direction === 'desc'
                : $direction === 'asc';

            return $competitionOrder ? $rows->values() : $rows->reverse()->values();
        }

        $descending = $direction === 'desc';

        return $rows->sortBy(function (Standing $standing) use ($sort, $level) {
            return match ($sort) {
                'shooter' => mb_strtolower((string) ($standing->user?->name ?? ''), 'UTF-8'),
                'division' => mb_strtolower((string) ($standing->division?->name ?? $standing->user?->division?->name ?? ''), 'UTF-8'),
                'province' => mb_strtolower((string) ($this->provinceName($standing, $level) ?? ''), 'UTF-8'),
                default => '',
            };
        }, SORT_REGULAR, $descending)->values();
    }

    private function provinceName(Standing $standing, string $level): ?string
    {
        $province = $level === 'provincial'
            ? ($standing->province ?? $standing->user?->province)
            : ($standing->user?->province ?? $standing->province);

        return $province?->name;
    }
}
