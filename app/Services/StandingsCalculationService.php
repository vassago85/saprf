<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Score;
use App\Models\Standing;
use Illuminate\Support\Collection;

class StandingsCalculationService
{
    public function recalculateForMatch(MatchEvent $match): void
    {
        $match->loadMissing('province');
        $season = $match->season ?: (string) $match->match_date->year;

        if ($match->series_level === 'national') {
            $this->recalculateForSeriesSeason($match->series, $season);
        } else {
            $this->recalculateForSeriesSeason($match->series, $season, $match->province_id);
        }
    }

    public function recalculateForSeriesSeason(string $series, string $season, ?int $provinceId = null): void
    {
        $paidUserIds = Membership::where('membership_type', 'paid')
            ->where('status', 'active')
            ->pluck('user_id');

        $scores = Score::query()
            ->with('match')
            ->where('status', 'valid')
            ->whereNotNull('user_id')
            ->whereIn('user_id', $paidUserIds)
            ->get()
            ->filter(function (Score $score) use ($series, $season, $provinceId): bool {
                $match = $score->match;
                if (! $match) {
                    return false;
                }

                $matchSeason = $match->season ?: (string) $match->match_date->year;

                if ($match->series !== $series || $matchSeason !== $season) {
                    return false;
                }

                if ($provinceId !== null) {
                    return $match->province_id === $provinceId;
                }

                return $match->series_level === 'national';
            });

        Standing::query()
            ->where('series', $series)
            ->where('season', $season)
            ->where('province_id', $provinceId)
            ->delete();

        $divisions = $scores->pluck('division')->filter()->unique()->values();
        if ($divisions->isEmpty()) {
            $divisions = collect(['Open']);
        }

        foreach ($divisions as $division) {
            $divisionScores = $scores->where('division', $division);
            $totals = $this->calculateTotals($divisionScores);
            $this->persistRankedStandings($totals, $series, $season, $division, $provinceId);
        }

        $allTotals = $this->calculateTotals($scores);
        $this->persistRankedStandings($allTotals, $series, $season, 'Overall', $provinceId);
    }

    private function calculateTotals(Collection $scores): Collection
    {
        return $scores
            ->groupBy('user_id')
            ->map(function (Collection $userScores, int $userId): array {
                $points = $userScores->sum(function (Score $score): float {
                    if (! $score->placement) {
                        return 0;
                    }

                    return (float) max(0, 101 - $score->placement);
                });

                return ['user_id' => $userId, 'points' => $points];
            })
            ->sortByDesc('points')
            ->values();
    }

    public function persistRankedStandings(Collection $totals, string $series, string $season, string $division, ?int $provinceId): void
    {
        $rank = 1;

        foreach ($totals as $row) {
            Standing::query()->create([
                'user_id' => (int) $row['user_id'],
                'series' => $series,
                'season' => $season,
                'division' => $division,
                'province_id' => $provinceId,
                'points' => (float) $row['points'],
                'rank' => $rank++,
            ]);
        }
    }
}
