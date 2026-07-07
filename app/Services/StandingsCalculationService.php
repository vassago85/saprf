<?php

namespace App\Services;

use App\Models\MatchEvent;
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

        // Under pooled scoring (PR22), provincial matches also feed the national
        // standings via the provincial pool contribution, so we need to recalc both.
        $rule = QualificationRule::where('series', $match->series)->where('season', $season)->first();
        $pooled = $rule && $rule->isPooledScoring();

        // PRS annual log: only national (regular) + final (champs) matches count,
        // and there is no provincial variant — one recalc of the overall +
        // per-division tables covers it regardless of the match's level.
        if ($rule && $rule->isAnnualLogWithChamps()) {
            $this->recalculateSeasonStandings($match->series, $season);

            return;
        }

        if (in_array($match->series_level, ['national', 'final'], true)) {
            // Any 2-day national flagged as dual-count feeds the PR22 provincial pool
            // via day-1 provincial_normalized_score. Compute those regardless of
            // whether we're also computing a per-province standings table.
            if ($match->also_counts_for_provincial) {
                $this->calculateProvincialNormalizedScores($match);
            }

            $this->recalculateSeasonStandings($match->series, $season);

            if ($match->also_counts_for_provincial && $match->province_id) {
                $this->recalculateSeasonStandings($match->series, $season, $match->province_id);
            }
        } else {
            $this->recalculateSeasonStandings($match->series, $season, $match->province_id);

            if ($pooled) {
                $this->recalculateSeasonStandings($match->series, $season);
            }
        }
    }

    /**
     * Rank + normalize every score in a match that's eligible to be shown.
     * That includes non-members and lapsed shooters, so a non-member can
     * legitimately win a match (matches precisionrifle.co.za convention).
     * Season standings still filter to status=valid separately.
     */
    public function calculateMatchRankings(MatchEvent $match): void
    {
        $scores = Score::where('match_id', $match->id)
            ->whereIn('status', \App\Services\ScoreValidationService::VISIBLE_STATUSES)
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

        // Per-division ranks (equipment class OR demographic class — they're
        // all just divisions now).
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
    }

    /**
     * For national matches that also count as provincial, calculate
     * normalized scores based on provincial_raw_score independently.
     */
    public function calculateProvincialNormalizedScores(MatchEvent $match): void
    {
        $scores = Score::where('match_id', $match->id)
            ->whereIn('status', \App\Services\ScoreValidationService::VISIBLE_STATUSES)
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

    public function recalculateSeasonStandings(string $series, string $season, ?int $provinceId = null): void
    {
        $isProvincial = $provinceId !== null;

        // Peek at the qualification rule up front — we need to know if pooled
        // scoring is on, so we can widen the score filter to include provincial
        // matches in the national standings pool.
        $rule = QualificationRule::where('series', $series)->where('season', $season)->first();

        // PRS annual log has no provincial standings — only the national/overall
        // and per-division tables. Bail out for any province-scoped call.
        if ($rule && $rule->isAnnualLogWithChamps()) {
            if (! $isProvincial) {
                $this->recalculateAnnualLogStandings($series, $season, $rule);
            }

            return;
        }

        $usePooled = ! $isProvincial && $rule && $rule->isPooledScoring();

        // status='valid' is set by ScoreValidationService when the shooter was
        // an active + paid member on the match date. That's a historical fact
        // captured per-score, so we DO NOT re-filter by current membership
        // state here — otherwise a member whose membership expires later in
        // the season would lose all their earlier valid scores retroactively.
        $allScores = Score::query()
            ->with(['match'])
            ->where('status', 'valid')
            ->where('counts_for_season', true)
            ->whereNotNull('user_id')
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
            $allowedLevels = $usePooled
                ? ['provincial', 'national', 'final']
                : ['national', 'final'];

            $scores = $allScores->filter(function (Score $score) use ($series, $season, $allowedLevels): bool {
                $match = $score->match;
                if (! $match) {
                    return false;
                }

                $matchSeason = $match->season ?: (string) $match->match_date->year;

                return $match->series === $series
                    && $matchSeason === $season
                    && in_array($match->series_level, $allowedLevels, true)
                    && $score->normalized_score !== null;
            });
        }

        $bestOf = $rule?->best_of_count;
        $finalsMultiplier = ($rule && $rule->weighted_final_enabled)
            ? (float) ($rule->weighted_final_multiplier ?? 1.0)
            : 1.0;

        Standing::where('series', $series)
            ->where('season', $season)
            ->where('province_id', $provinceId)
            ->delete();

        if ($usePooled) {
            $overallTotals = $this->aggregateWeightedPools($scores, $rule, 'overall');
        } else {
            $overallTotals = $this->aggregateSeasonTotals($scores, 'overall', $bestOf, $isProvincial, $finalsMultiplier);
        }
        $this->persistRankedStandings($overallTotals, $series, $season, $provinceId, null);

        $divisionIds = $scores->pluck('division_id')->filter()->unique();
        foreach ($divisionIds as $divisionId) {
            $divScores = $scores->where('division_id', $divisionId);
            $divTotals = $usePooled
                ? $this->aggregateWeightedPools($divScores, $rule, 'division')
                : $this->aggregateSeasonTotals($divScores, 'division', $bestOf, $isProvincial, $finalsMultiplier);
            $this->persistRankedStandings($divTotals, $series, $season, $provinceId, $divisionId);
        }
    }

    /**
     * PRS annual "national log" standings.
     *
     * Only national (regular) and final (champs) matches count — provincial PRS
     * matches are ignored, and there is no province dimension. Computes the
     * overall table (match-wide normalisation) plus one independent table per
     * division (per-division normalisation).
     */
    public function recalculateAnnualLogStandings(string $series, string $season, QualificationRule $rule): void
    {
        $scores = Score::query()
            ->with(['match'])
            ->where('status', 'valid')
            ->where('counts_for_season', true)
            ->whereNotNull('user_id')
            ->get()
            ->filter(function (Score $score) use ($series, $season): bool {
                $match = $score->match;
                if (! $match) {
                    return false;
                }
                $matchSeason = $match->season ?: (string) $match->match_date->year;

                return $match->series === $series
                    && $matchSeason === $season
                    && in_array($match->series_level, ['national', 'final'], true);
            });

        $regularBestOf = $rule->regularBestOf();

        // Wipe the whole (province-null) set for this series/season, overall +
        // every division, then rebuild.
        Standing::where('series', $series)
            ->where('season', $season)
            ->whereNull('province_id')
            ->delete();

        $overall = $this->aggregatePrsAnnualLog($scores, 'overall', $regularBestOf);
        $this->persistCompetitionRankedStandings($overall, $series, $season, null, null);

        $divisionIds = $scores->pluck('division_id')->filter()->unique();
        foreach ($divisionIds as $divisionId) {
            $divScores = $scores->where('division_id', $divisionId);
            $divTotals = $this->aggregatePrsAnnualLog($divScores, 'division', $regularBestOf);
            $this->persistCompetitionRankedStandings($divTotals, $series, $season, null, $divisionId);
        }
    }

    /**
     * Annual-log aggregation for a single scope (overall or one division).
     *
     * total = (sum of BEST $regularBestOf regular/national match %s)
     *         + (championship/final match %, non-droppable, 0 if not shot)
     *
     * The champs component can never be replaced by an extra regular match, so
     * a shooter with four 100% regulars and no champs tops out at 300, not 400.
     *
     * @return \Illuminate\Support\Collection<int, array{user_id:int, points:float, pool_breakdown:array}>
     */
    private function aggregatePrsAnnualLog(Collection $scores, string $context, int $regularBestOf): Collection
    {
        return $scores
            ->groupBy('user_id')
            ->map(function (Collection $userScores, int $userId) use ($context, $regularBestOf): array {
                $regular = $userScores
                    ->filter(fn (Score $s) => $s->match?->series_level === 'national')
                    ->map(fn (Score $s) => [
                        'match_id' => $s->match_id,
                        'match_name' => $s->match?->name,
                        'pct' => round($this->normalizedScoreForContext($s, $context), 2),
                    ])
                    ->sortByDesc('pct')
                    ->values();

                $counted = $regular->take($regularBestOf)->values();
                $regularTotal = round((float) $counted->sum('pct'), 2);

                // Championship = the single best final-level score (usually only
                // one champs match exists). 0 if the shooter didn't shoot it.
                $champsScores = $userScores
                    ->filter(fn (Score $s) => $s->match?->series_level === 'final')
                    ->map(fn (Score $s) => [
                        'match_id' => $s->match_id,
                        'match_name' => $s->match?->name,
                        'pct' => round($this->normalizedScoreForContext($s, $context), 2),
                    ])
                    ->sortByDesc('pct')
                    ->values();

                $champs = $champsScores->first();
                $champsPct = $champs ? (float) $champs['pct'] : 0.0;

                $total = round($regularTotal + $champsPct, 2);

                return [
                    'user_id' => $userId,
                    'points' => $total,
                    'pool_breakdown' => [
                        'mode' => 'annual_log',
                        'regular' => $counted->all(),
                        'regular_counted' => $counted->count(),
                        'regular_best_of' => $regularBestOf,
                        'regular_total' => $regularTotal,
                        'champs' => $champs,
                        'champs_pct' => round($champsPct, 2),
                        'max' => $regularBestOf * 100 + 100,
                        'total' => $total,
                    ],
                ];
            })
            ->sortByDesc('points')
            ->values();
    }

    /**
     * Persist standings using standard competition ranking: shooters tied on
     * points share a rank and the following rank(s) are skipped (1, 2, 2, 4).
     * Ties are decided on points rounded to 2 decimals.
     *
     * @param \Illuminate\Support\Collection<int, array{user_id:int, points:float, pool_breakdown?:array}> $totals
     */
    public function persistCompetitionRankedStandings(
        Collection $totals,
        string $series,
        string $season,
        ?int $provinceId,
        ?int $divisionId,
    ): void {
        $ordered = $totals->sortByDesc(fn (array $row) => round((float) $row['points'], 2))->values();

        $rank = 0;
        $position = 0;
        $previousPoints = null;

        foreach ($ordered as $row) {
            $position++;
            $points = round((float) $row['points'], 2);

            if ($previousPoints === null || $points !== $previousPoints) {
                $rank = $position;
                $previousPoints = $points;
            }

            Standing::create([
                'user_id' => (int) $row['user_id'],
                'series' => $series,
                'season' => $season,
                'province_id' => $provinceId,
                'division_id' => $divisionId,
                'points' => (float) $row['points'],
                'rank' => $rank,
                'pool_breakdown' => $row['pool_breakdown'] ?? null,
            ]);
        }
    }

    /**
     * Ranked annual-log output for a given season + division (or overall when
     * $divisionId is null). Reads the persisted standings and shapes them for
     * display / export.
     *
     * @return \Illuminate\Support\Collection<int, array{
     *     rank:int, user_id:int, shooter:string, regular:array, champs_pct:float,
     *     total:float, max:int
     * }>
     */
    public function annualLog(string $series, string $season, ?int $divisionId = null): Collection
    {
        return Standing::query()
            ->with('user:id,name')
            ->where('series', $series)
            ->where('season', $season)
            ->whereNull('province_id')
            ->where('division_id', $divisionId)
            ->orderBy('rank')
            ->orderByDesc('points')
            ->get()
            ->map(function (Standing $s) {
                $b = $s->pool_breakdown ?? [];

                return [
                    'rank' => (int) $s->rank,
                    'user_id' => (int) $s->user_id,
                    'shooter' => $s->user?->name ?? '—',
                    'regular' => $b['regular'] ?? [],
                    'regular_total' => (float) ($b['regular_total'] ?? 0),
                    'champs' => $b['champs'] ?? null,
                    'champs_pct' => (float) ($b['champs_pct'] ?? 0),
                    'total' => (float) $s->points,
                    'max' => (int) ($b['max'] ?? 400),
                ];
            })
            ->values();
    }

    /**
     * Weighted-pool aggregation: scores are grouped by pool (provincial /
     * national / champs) based on their match's series_level. Each pool has
     * a "best of N" and a weight (%). The season total is a weighted average
     * out of 100.
     *
     * Pool divisor rules:
     *   - provincial / champs: STRICT — sum of best-N ÷ N (fixed), so missing
     *     matches effectively count as 0 (rewards attendance).
     *   - national: DROP-ONE — a shooter's worst national is always dropped, so
     *     the number of counting scores = (nationals shot − 1), capped at the
     *     pool's best-of (2). The pool result is the AVERAGE of those counting
     *     scores. Practically: 1 shot → 0, 2 shot → best 1, 3+ shot → best 2.
     *
     * @return \Illuminate\Support\Collection<int, array{user_id: int, points: float, pool_breakdown: array}>
     */
    private function aggregateWeightedPools(
        Collection $scores,
        QualificationRule $rule,
        string $context,
    ): Collection {
        return $scores
            ->groupBy('user_id')
            ->map(function (Collection $userScores, int $userId) use ($rule, $context): array {
                $breakdown = [];
                $total = 0.0;

                foreach ($this->poolConfigs($rule) as $poolKey => $config) {
                    if ($config['best_of'] <= 0 || $config['weight'] <= 0) {
                        continue;
                    }

                    $normalized = $userScores
                        ->map(fn (Score $s) => $this->contributionForPool($s, $poolKey, $context))
                        ->filter(fn ($v) => $v !== null)
                        ->values();

                    $sorted = $normalized->sortDesc()->values();

                    if ($poolKey === 'national') {
                        // Drop-one: you must shoot one extra national to unlock each
                        // counting score. The divisor is the number that actually
                        // count, so a single counting score is worth its full value.
                        $countN = max(0, min($config['best_of'], $sorted->count() - 1));
                        $counted = $sorted->take($countN);
                        $poolAverage = $countN > 0 ? $counted->sum() / $countN : 0.0;
                    } else {
                        // Strict: divide by best_of even when the shooter has fewer
                        // scores. Missing matches count as 0.
                        $counted = $sorted->take($config['best_of']);
                        $poolAverage = $counted->sum() / $config['best_of'];
                    }

                    $contribution = ($poolAverage * $config['weight']) / 100.0;

                    $breakdown[$poolKey] = [
                        'scores_counted' => $counted->count(),
                        'best_of' => $config['best_of'],
                        'weight_pct' => $config['weight'],
                        'pool_average' => round($poolAverage, 2),
                        'contribution' => round($contribution, 2),
                    ];

                    $total += $contribution;
                }

                return [
                    'user_id' => $userId,
                    'points' => round($total, 4),
                    'pool_breakdown' => $breakdown,
                ];
            })
            ->sortByDesc('points')
            ->values();
    }

    /**
     * Definition of the three pools with their best-of counts and weights.
     */
    private function poolConfigs(QualificationRule $rule): array
    {
        return [
            'provincial' => [
                'best_of' => (int) ($rule->provincial_pool_best_of ?? 0),
                'weight' => (float) ($rule->provincial_pool_weight_pct ?? 0),
            ],
            'national' => [
                'best_of' => (int) ($rule->national_pool_best_of ?? 0),
                'weight' => (float) ($rule->national_pool_weight_pct ?? 0),
            ],
            'champs' => [
                'best_of' => (int) ($rule->champs_pool_best_of ?? 1),
                'weight' => (float) ($rule->champs_pool_weight_pct ?? 0),
            ],
        ];
    }

    /**
     * Return the normalized contribution a single score makes to a specific pool,
     * or null if the score does not belong to that pool.
     *
     * Pool membership rules:
     *   - provincial : provincial-level matches (full score)
     *                  + 2-day nationals with also_counts_for_provincial
     *                    (uses the day-1-based provincial_normalized_score)
     *   - national   : national-level matches (full score)
     *   - champs     : final-level matches (full score)
     */
    private function contributionForPool(Score $score, string $poolKey, string $context): ?float
    {
        $match = $score->match;
        if (! $match) {
            return null;
        }

        return match ($poolKey) {
            'provincial' => match (true) {
                $match->series_level === 'provincial'
                    => $this->normalizedScoreForContext($score, $context),

                $match->series_level === 'national' && $match->also_counts_for_provincial
                    => $score->provincial_normalized_score !== null
                        ? (float) $score->provincial_normalized_score
                        : null,

                default => null,
            },
            'national' => $match->series_level === 'national'
                ? $this->normalizedScoreForContext($score, $context)
                : null,
            'champs' => $match->series_level === 'final'
                ? $this->normalizedScoreForContext($score, $context)
                : null,
            default => null,
        };
    }

    private function normalizedScoreForContext(Score $score, string $context): float
    {
        return match ($context) {
            'division' => (float) ($score->division_normalized_score ?? $score->normalized_score ?? 0),
            default => (float) ($score->normalized_score ?? 0),
        };
    }

    /**
     * @param string $context 'overall' | 'division'
     */
    private function aggregateSeasonTotals(
        Collection $scores,
        string $context,
        ?int $bestOf,
        bool $useProvincialScore = false,
        float $finalsMultiplier = 1.0,
    ): Collection {
        return $scores
            ->groupBy('user_id')
            ->map(function (Collection $userScores, int $userId) use ($context, $bestOf, $useProvincialScore, $finalsMultiplier): array {
                $scored = $userScores->map(function (Score $s) use ($context, $useProvincialScore, $finalsMultiplier) {
                    $match = $s->match;
                    if ($useProvincialScore && $match && $match->series_level === 'national' && $match->also_counts_for_provincial) {
                        return $s->provincial_normalized_score ?? 0;
                    }

                    $base = match ($context) {
                        'division' => $s->division_normalized_score ?? $s->normalized_score ?? 0,
                        default => $s->normalized_score ?? 0,
                    };

                    if ($match && $match->series_level === 'final' && $finalsMultiplier > 1.0) {
                        $base = $base * $finalsMultiplier;
                    }

                    return $base;
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

    public function persistRankedStandings(
        Collection $totals,
        string $series,
        string $season,
        ?int $provinceId,
        ?int $divisionId,
    ): void {
        $rank = 1;

        foreach ($totals as $row) {
            Standing::create([
                'user_id' => (int) $row['user_id'],
                'series' => $series,
                'season' => $season,
                'province_id' => $provinceId,
                'division_id' => $divisionId,
                'points' => (float) $row['points'],
                'rank' => $rank++,
                'pool_breakdown' => $row['pool_breakdown'] ?? null,
            ]);
        }
    }
}
