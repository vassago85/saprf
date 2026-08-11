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

        // PRS annual log: national side uses regular + champs matches only
        // (annual-log calc), while provincial-level matches feed a separate
        // best-of-N provincial standing. If the completed match is national
        // or final, only the national side needs rebuilding; if it's
        // provincial, every province's table has to be rebuilt (attribution
        // follows the shooter's home province, so any province can be
        // affected regardless of the host).
        if ($rule && $rule->isAnnualLogWithChamps()) {
            $this->recalculateSeasonStandings($match->series, $season);
            if ($match->series_level === 'provincial') {
                $this->recalculateProvincialStandings($match->series, $season);
            }

            return;
        }

        if (in_array($match->series_level, ['national', 'final'], true)) {
            // A national match is national ONLY. Even if the legacy
            // also_counts_for_provincial flag is set, we deliberately do not
            // touch the provincial standings from here — provincial credit
            // requires a genuine provincial-level match (MDs post day-1 as
            // its own provincial event when that credit is intended).
            $this->recalculateSeasonStandings($match->series, $season);
        } else {
            // A provincial match is attended by shooters from many provinces, and
            // each is scored against their OWN province — so recalculate every
            // provincial table, not just the host province's.
            $this->recalculateProvincialStandings($match->series, $season);

            if ($pooled) {
                $this->recalculateSeasonStandings($match->series, $season);
            }
        }
    }

    /**
     * Rebuild the provincial standings for every province for a series/season.
     * Because a score is attributed to the shooter's home province (not the
     * match's), any province may be affected by a single match.
     */
    public function recalculateProvincialStandings(string $series, string $season): void
    {
        foreach (\App\Models\Province::query()->pluck('id') as $provinceId) {
            $this->recalculateSeasonStandings($series, $season, (int) $provinceId);
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

        // PRS annual log handles the NATIONAL side (best-N regular + fixed
        // champs). Provincial PRS standings are computed via the generic
        // best-of-N provincial builder below — "sum of best N provincial
        // scores" — using the same best_of_count as the annual log. This
        // parallels PR22 (provincial standing = sum of best-N provincial
        // scores) so both series share one provincial rule.
        if ($rule && $rule->isAnnualLogWithChamps()) {
            if (! $isProvincial) {
                $this->recalculateAnnualLogStandings($series, $season, $rule);

                return;
            }
            // Provincial: fall through to the generic path.
        }

        $usePooled = ! $isProvincial && $rule && $rule->isPooledScoring();

        // status='valid' is set by ScoreValidationService when the shooter was
        // an active + paid member on the match date. That's a historical fact
        // captured per-score, so we DO NOT re-filter by current membership
        // state here — otherwise a member whose membership expires later in
        // the season would lose all their earlier valid scores retroactively.
        $allScores = Score::query()
            ->with(['match', 'user:id,province_id'])
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

                // Provincial results follow the SHOOTER's home province, not the
                // province that hosted the match. An out-of-province score still
                // counts towards the shooter's own provincial standing, and a
                // shooter can only ever appear in one provincial table (so no
                // duplicate 1st/2nd/3rd across provinces).
                if (($score->user?->province_id) !== $provinceId) {
                    return false;
                }

                // ONLY provincial-level matches feed the provincial standing.
                // A national match stays national — even a 2-day national.
                // If the MD wants day-1 to count provincially, they post day-1
                // as its own separate provincial match. The legacy
                // `also_counts_for_provincial` dual-count path is intentionally
                // ignored here so national scores can never leak into
                // provincial rankings.
                return $match->series_level === 'provincial'
                    && $score->normalized_score !== null;
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

        // The provincial standings table is "sum of best N provincial scores".
        // Under weighted-pools rules (PR22) best_of_count is null — the
        // provincial best-of lives in provincial_pool_best_of (used by the
        // national pooled standing) — so fall back to it here. Without this the
        // provincial table would sum EVERY provincial match instead of the best
        // N, diverging from the PRS provincial table (which uses best_of_count).
        if ($isProvincial && ! $bestOf) {
            $bestOf = $rule?->provincial_pool_best_of ?: null;
        }

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
                // Tag the breakdown with an explicit mode so consumers
                // (view, contribution merger) can distinguish weighted-pools
                // rows from the other shapes (annual_log, best_of_n) without
                // relying on the presence of specific pool keys.
                $breakdown = ['mode' => 'weighted_pools'];
                $total = 0.0;

                foreach ($this->poolConfigs($rule) as $poolKey => $config) {
                    if ($config['best_of'] <= 0 || $config['weight'] <= 0) {
                        continue;
                    }

                    // Keep per-match metadata alongside the pct so we can record
                    // which specific matches counted and the points each one
                    // contributed to the season total.
                    $eligible = $userScores
                        ->map(function (Score $s) use ($poolKey, $context) {
                            $pct = $this->contributionForPool($s, $poolKey, $context);
                            if ($pct === null) {
                                return null;
                            }

                            return [
                                'match_id' => $s->match_id,
                                'match_name' => $s->match?->name,
                                'series_level' => $s->match?->series_level,
                                'pct' => (float) $pct,
                            ];
                        })
                        ->filter()
                        ->sortByDesc('pct')
                        ->values();

                    if ($poolKey === 'national') {
                        // National pool = minimum-matches gate + best-of (NO drop-one):
                        //   - gate:      a shooter must complete at least `min` national
                        //                matches before ANY national score is earned. Below
                        //                that threshold the pool is 0.
                        //   - count:     at/above the gate, the best `best_of` scores count
                        //                and are summed (no worst-score drop).
                        //                (min=1, best_of=2 → 1 shot → best 1, 2 shot → both,
                        //                 3+ shot → best 2)
                        //   - divisor:   ALWAYS best_of, so the pool is scored out of the
                        //                same target regardless of how many were shot.
                        $min = $config['min'];
                        $countN = $eligible->count() >= $min
                            ? min($config['best_of'], $eligible->count())
                            : 0;
                        $divisor = $config['best_of'];
                        $poolAverage = $divisor > 0
                            ? $eligible->take($countN)->sum('pct') / $divisor
                            : 0.0;
                    } else {
                        // Strict: divide by best_of even when the shooter has fewer
                        // scores. Missing matches count as 0.
                        $countN = min($config['best_of'], $eligible->count());
                        $divisor = $config['best_of'];
                        $poolAverage = $eligible->take($config['best_of'])->sum('pct') / $config['best_of'];
                    }

                    $contribution = ($poolAverage * $config['weight']) / 100.0;

                    // Per-match contribution: counted scores contribute
                    // pct * weight / 100 / divisor; dropped scores contribute 0.
                    // Divisor is `best_of` for every pool now — so per-match
                    // contribution and the pool average always agree on the
                    // same "divide by the target count" rule.
                    $matches = $eligible
                        ->values()
                        ->map(function (array $row, int $idx) use ($countN, $divisor, $config) {
                            $counted = $idx < $countN;
                            $perMatch = ($counted && $divisor > 0)
                                ? ($row['pct'] * $config['weight']) / 100.0 / $divisor
                                : 0.0;

                            return [
                                'match_id' => $row['match_id'],
                                'match_name' => $row['match_name'],
                                'series_level' => $row['series_level'],
                                'pct' => round($row['pct'], 2),
                                'counted' => $counted,
                                'contribution' => round($perMatch, 4),
                            ];
                        });

                    $breakdown[$poolKey] = [
                        'scores_counted' => $countN,
                        'best_of' => $config['best_of'],
                        'weight_pct' => $config['weight'],
                        'pool_average' => round($poolAverage, 2),
                        'contribution' => round($contribution, 2),
                        'matches' => $matches->all(),
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
                'min' => (int) ($rule->national_pool_min_matches ?? 2),
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
     * Pool membership rules (strict — a national score can NEVER count as a
     * provincial score, even when historically flagged also_counts_for_provincial;
     * if day-1 should count provincially, MDs post it as a separate provincial
     * match):
     *   - provincial : provincial-level matches (full score)
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
            'provincial' => $match->series_level === 'provincial'
                ? $this->normalizedScoreForContext($score, $context)
                : null,
            'national' => $match->series_level === 'national'
                ? $this->normalizedScoreForContext($score, $context)
                : null,
            'champs' => $match->series_level === 'final'
                ? $this->normalizedScoreForContext($score, $context)
                : null,
            default => null,
        };
    }

    /**
     * Return the score value to use for season aggregation in a given context.
     *
     * IMPORTANT: both 'overall' and 'division' contexts now return the same
     * value — the shooter's OVERALL normalized_score (their raw % against the
     * top raw score in the match, across ALL divisions).
     *
     * The old behaviour normalised division standings against the top score
     * IN THAT DIVISION, which meant each division always had a shooter at
     * 100% per match but the season totals could diverge from the overall
     * standing (best-3 dropped different matches depending on whether the
     * overall winner was in the shooter's division). Users read the two
     * numbers as inconsistent — e.g. Overall 298.21 vs Open 300.00 for the
     * same shooter, same 4 matches — even though both were internally
     * consistent by their own rule.
     *
     * Under the new rule, each per-division standing is simply the overall
     * best-3 total restricted to the cohort of shooters who shot that
     * division. Every shooter's Open/Senior/Ladies/etc. points now equal
     * their Overall points (matching cohort permitting), and only the
     * ranking changes across divisions. The `division_normalized_score`
     * column is still populated by calculateMatchRankings() and remains
     * available for the per-match "By Division" tab on the match page —
     * it's just no longer used for season aggregation.
     */
    private function normalizedScoreForContext(Score $score, string $context): float
    {
        return (float) ($score->normalized_score ?? 0);
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
                // Keep the source Score attached so we can record per-match
                // metadata (match_id, name, level) alongside the value that
                // actually counts. National scores never reach this method
                // when computing a provincial standing (the filter above
                // strips them), so we always take the match's normalized
                // score directly — the legacy day-1 provincial_normalized
                // dual-count path has been removed.
                $scored = $userScores
                    ->map(function (Score $s) use ($context, $finalsMultiplier) {
                        $match = $s->match;

                        // Always go through normalizedScoreForContext() so
                        // both 'overall' and 'division' contexts sum the
                        // shooter's overall normalized_score. See that
                        // method's docblock for why the old
                        // division_normalized_score path was retired.
                        $value = $this->normalizedScoreForContext($s, $context);

                        if ($match && $match->series_level === 'final' && $finalsMultiplier > 1.0) {
                            $value = $value * $finalsMultiplier;
                        }

                        return [
                            'match_id' => $s->match_id,
                            'match_name' => $match?->name,
                            'series_level' => $match?->series_level,
                            'value' => $value,
                        ];
                    });

                $sorted = $scored->sortByDesc('value')->values();

                $limit = ($bestOf && $bestOf > 0) ? $bestOf : $sorted->count();
                $countN = min($limit, $sorted->count());

                $matches = $sorted->map(function (array $row, int $idx) use ($countN) {
                    $counted = $idx < $countN;

                    return [
                        'match_id' => $row['match_id'],
                        'match_name' => $row['match_name'],
                        'series_level' => $row['series_level'],
                        'pct' => round($row['value'], 2),
                        'counted' => $counted,
                        // In best-of-N sum mode the counted score's raw value
                        // IS its contribution to the season total.
                        'contribution' => $counted ? round($row['value'], 4) : 0.0,
                    ];
                });

                $countedRows = $sorted->take($countN);
                $points = round($countedRows->sum('value'), 4);

                return [
                    'user_id' => $userId,
                    'points' => $points,
                    'pool_breakdown' => [
                        'mode' => 'best_of_n',
                        'best_of' => $bestOf ?: null,
                        'scores_counted' => $countN,
                        'total' => $points,
                        'matches' => $matches->all(),
                    ],
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
