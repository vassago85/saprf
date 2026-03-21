<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\Standing;
use Illuminate\Support\Collection;

class StandingsCalculationService
{
    public function recalculateForMatch(MatchEvent $match): void
    {
        $this->calculateMatchRankings($match);

        $match->loadMissing('province');
        $season = $match->season ?: (string) $match->match_date->year;

        if ($match->series_level === 'national') {
            $this->recalculateSeasonStandings($match->series, $season);

            if ($match->also_counts_for_provincial && $match->province_id) {
                $this->calculateProvincialNormalizedScores($match);
                $this->recalculateSeasonStandings($match->series, $season, $match->province_id);
            }
        } else {
            $this->recalculateSeasonStandings($match->series, $season, $match->province_id);
        }
    }

    public function calculateMatchRankings(MatchEvent $match): void
    {
        $scores = Score::where('match_id', $match->id)
            ->where('status', 'valid')
            ->orderByDesc('raw_score')
            ->get();

        if ($scores->isEmpty()) {
            return;
        }

        $topRawScore = $scores->max('raw_score');

        if ($topRawScore <= 0) {
            return;
        }

        foreach ($scores as $score) {
            $score->normalized_score = ($score->raw_score / $topRawScore) * 100;
        }

        $rank = 1;
        foreach ($scores->sortByDesc('normalized_score')->values() as $score) {
            $score->overall_rank = $rank++;
        }

        $byDivision = $scores->groupBy('division_id');
        foreach ($byDivision as $divisionId => $divScores) {
            if ($divisionId === null) {
                continue;
            }
            $topDivRaw = $divScores->max('raw_score');
            if ($topDivRaw <= 0) {
                continue;
            }
            $rank = 1;
            foreach ($divScores->sortByDesc('raw_score')->values() as $score) {
                $score->division_normalized_score = ($score->raw_score / $topDivRaw) * 100;
                $score->division_rank = $rank++;
            }
        }

        foreach ($scores as $score) {
            $score->save();
        }

        $this->calculateCategoryScoresAndRanks($scores);
    }

    /**
     * For national matches that also count as provincial, calculate
     * normalized scores based on provincial_raw_score independently.
     */
    public function calculateProvincialNormalizedScores(MatchEvent $match): void
    {
        $scores = Score::where('match_id', $match->id)
            ->where('status', 'valid')
            ->whereNotNull('provincial_raw_score')
            ->where('provincial_raw_score', '>', 0)
            ->get();

        if ($scores->isEmpty()) {
            return;
        }

        $topProvincialScore = $scores->max('provincial_raw_score');

        if ($topProvincialScore <= 0) {
            return;
        }

        foreach ($scores as $score) {
            $score->provincial_normalized_score = ($score->provincial_raw_score / $topProvincialScore) * 100;
            $score->save();
        }
    }

    private function calculateCategoryScoresAndRanks(Collection $scores): void
    {
        $categoryScores = [];

        foreach ($scores as $score) {
            $score->loadMissing('categories');
            foreach ($score->categories as $category) {
                $categoryScores[$category->id][] = $score;
            }
        }

        foreach ($categoryScores as $categoryId => $catScores) {
            $topCatRaw = collect($catScores)->max('raw_score');
            if ($topCatRaw <= 0) {
                continue;
            }

            usort($catScores, fn ($a, $b) => $b->raw_score <=> $a->raw_score);
            $rank = 1;
            foreach ($catScores as $score) {
                $catNorm = ($score->raw_score / $topCatRaw) * 100;
                $score->categories()->updateExistingPivot($categoryId, [
                    'category_normalized_score' => round($catNorm, 4),
                    'category_rank' => $rank++,
                ]);
            }
        }
    }

    public function recalculateSeasonStandings(string $series, string $season, ?int $provinceId = null): void
    {
        $paidUserIds = Membership::where('membership_type', 'paid')
            ->where('status', 'active')
            ->pluck('user_id');

        $isProvincial = $provinceId !== null;

        $allScores = Score::query()
            ->with(['match', 'categories'])
            ->where('status', 'valid')
            ->where('counts_for_season', true)
            ->whereNotNull('user_id')
            ->whereIn('user_id', $paidUserIds)
            ->get();

        if ($isProvincial) {
            $scores = $allScores->filter(function (Score $score) use ($series, $season, $provinceId): bool {
                $match = $score->match;
                if (! $match) {
                    return false;
                }

                $matchSeason = $match->season ?: (string) $match->match_date->year;
                if ($match->series !== $series || $matchSeason !== $season) {
                    return false;
                }

                if ($match->province_id !== $provinceId) {
                    return false;
                }

                if ($match->series_level === 'provincial') {
                    return $score->normalized_score !== null;
                }

                if ($match->series_level === 'national' && $match->also_counts_for_provincial) {
                    return $score->provincial_normalized_score !== null;
                }

                return false;
            });
        } else {
            $scores = $allScores->filter(function (Score $score) use ($series, $season): bool {
                $match = $score->match;
                if (! $match) {
                    return false;
                }

                $matchSeason = $match->season ?: (string) $match->match_date->year;

                return $match->series === $series
                    && $matchSeason === $season
                    && $match->series_level === 'national'
                    && $score->normalized_score !== null;
            });
        }

        $rule = QualificationRule::where('series', $series)->where('season', $season)->first();
        $bestOf = $rule?->best_of_count;

        Standing::where('series', $series)
            ->where('season', $season)
            ->where('province_id', $provinceId)
            ->delete();

        $overallTotals = $this->aggregateSeasonTotals($scores, 'overall', $bestOf, $isProvincial);
        $this->persistRankedStandings($overallTotals, $series, $season, $provinceId, null, null);

        $divisionIds = $scores->pluck('division_id')->filter()->unique();
        foreach ($divisionIds as $divisionId) {
            $divScores = $scores->where('division_id', $divisionId);
            $divTotals = $this->aggregateSeasonTotals($divScores, 'division', $bestOf, $isProvincial);
            $this->persistRankedStandings($divTotals, $series, $season, $provinceId, $divisionId, null);
        }

        $categoryIds = $scores->flatMap(fn ($s) => $s->categories->pluck('id'))->unique();
        foreach ($categoryIds as $categoryId) {
            $catScores = $scores->filter(fn ($s) => $s->categories->contains('id', $categoryId));
            $catTotals = $this->aggregateSeasonTotals($catScores, 'category', $bestOf, $isProvincial, $categoryId);
            $this->persistRankedStandings($catTotals, $series, $season, $provinceId, null, $categoryId);
        }
    }

    /**
     * @param string $context 'overall' | 'division' | 'category'
     */
    private function aggregateSeasonTotals(
        Collection $scores,
        string $context,
        ?int $bestOf,
        bool $useProvincialScore = false,
        ?int $categoryId = null,
    ): Collection {
        return $scores
            ->groupBy('user_id')
            ->map(function (Collection $userScores, int $userId) use ($context, $bestOf, $useProvincialScore, $categoryId): array {
                $scored = $userScores->map(function (Score $s) use ($context, $useProvincialScore, $categoryId) {
                    $match = $s->match;
                    if ($useProvincialScore && $match && $match->series_level === 'national' && $match->also_counts_for_provincial) {
                        return $s->provincial_normalized_score ?? 0;
                    }

                    return match ($context) {
                        'division' => $s->division_normalized_score ?? $s->normalized_score ?? 0,
                        'category' => $this->getCategoryNormalizedScore($s, $categoryId),
                        default => $s->normalized_score ?? 0,
                    };
                });

                $sorted = $scored->sortDesc()->values();

                if ($bestOf && $bestOf > 0) {
                    $counted = $sorted->take($bestOf);
                } else {
                    $counted = $sorted;
                }

                return [
                    'user_id' => $userId,
                    'points' => round($counted->sum(), 4),
                ];
            })
            ->sortByDesc('points')
            ->values();
    }

    private function getCategoryNormalizedScore(Score $score, ?int $categoryId): float
    {
        if (! $categoryId) {
            return $score->normalized_score ?? 0;
        }

        $pivot = $score->categories->firstWhere('id', $categoryId)?->pivot;

        return (float) ($pivot?->category_normalized_score ?? $score->normalized_score ?? 0);
    }

    public function persistRankedStandings(
        Collection $totals,
        string $series,
        string $season,
        ?int $provinceId,
        ?int $divisionId,
        ?int $categoryId,
    ): void {
        $rank = 1;

        foreach ($totals as $row) {
            Standing::create([
                'user_id' => (int) $row['user_id'],
                'series' => $series,
                'season' => $season,
                'province_id' => $provinceId,
                'division_id' => $divisionId,
                'category_id' => $categoryId,
                'points' => (float) $row['points'],
                'rank' => $rank++,
            ]);
        }
    }
}
