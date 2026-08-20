<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\User;
use Illuminate\Support\Collection;

class QualificationService
{
    /**
     * Out-of-province national match requirement used for finals selection.
     *
     * @return array{required:int, completed:int, qualified:bool, remaining:int}
     */
    public function getQualificationStatus(User $user, string $series, string $season): array
    {
        $rule = QualificationRule::query()
            ->where('series', $series)
            ->where('season', $season)
            ->first();

        $required = $rule?->min_out_of_province_matches ?? 0;

        $completed = $this->countOutOfProvinceMatches($user, $series, $season);

        return [
            'required' => $required,
            'completed' => $completed,
            'qualified' => $required > 0 && $completed >= $required,
            'remaining' => max(0, $required - $completed),
        ];
    }

    public function isQualifiedForFinals(User $user, string $series, string $season): bool
    {
        return $this->getQualificationStatus($user, $series, $season)['qualified'];
    }

    /**
     * Bulk equivalent of getQualificationStatus() for a batch of shooters.
     * Used by the public standings leaderboard to render the finale-eligibility
     * ✓ next to each shooter's name without triggering a per-row query.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{required:int, completed:int, qualified:bool, remaining:int}>
     */
    public function bulkFinalsQualification(array $userIds, string $series, string $season): array
    {
        if (empty($userIds)) {
            return [];
        }

        $rule = QualificationRule::query()
            ->where('series', $series)
            ->where('season', $season)
            ->first();

        $required = (int) ($rule?->min_out_of_province_matches ?? 0);

        // OOP is defined *relative to each shooter's own province*, so we
        // join users into the score aggregate and filter on the mismatch.
        // One query for the whole page; MySQL/MariaDB handle the correlated
        // "!=" comparison natively. The MatchEvent model overrides its
        // table name to "matches" (legacy), so we resolve it via the model
        // rather than hardcoding.
        //
        // We filter on match season only (no YEAR(match_date) fallback
        // like the per-user path uses). The bulk method sits on the
        // public standings page, which is hit every match weekend — the
        // raw YEAR() call is MySQL-only and breaks SQLite tests, so we
        // lean on the canonical `season` column every current match
        // already carries.
        $matches = (new MatchEvent)->getTable();

        $counts = Score::query()
            ->selectRaw('scores.user_id, COUNT(DISTINCT scores.match_id) AS oop_count')
            ->join($matches, "$matches.id", '=', 'scores.match_id')
            ->join('users', 'users.id', '=', 'scores.user_id')
            ->where('scores.status', 'valid')
            ->where("$matches.series", $series)
            ->where("$matches.series_level", 'national')
            ->where("$matches.season", $season)
            ->whereColumn("$matches.province_id", '!=', 'users.province_id')
            ->whereIn('scores.user_id', $userIds)
            ->groupBy('scores.user_id')
            ->pluck('oop_count', 'scores.user_id')
            ->all();

        $out = [];
        foreach ($userIds as $userId) {
            $completed = (int) ($counts[$userId] ?? 0);
            $out[$userId] = [
                'required' => $required,
                'completed' => $completed,
                'qualified' => $required > 0 && $completed >= $required,
                'remaining' => max(0, $required - $completed),
            ];
        }

        return $out;
    }

    /**
     * Member-dashboard qualification process for PRS and PR22.
     *
     * Always returns both series so the UI can show each process side by side.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getDashboardProgress(User $user, string $season): array
    {
        $progress = [];

        foreach (['PRS', 'PR22'] as $series) {
            $progress[$series] = $this->getSeriesDashboardProgress($user, $series, $season);
        }

        return $progress;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSeriesDashboardProgress(User $user, string $series, string $season): array
    {
        $rule = QualificationRule::query()
            ->where('series', $series)
            ->where('season', $season)
            ->first();

        $oop = $this->getQualificationStatus($user, $series, $season);
        $levelCounts = $this->countMatchesByLevel($user, $series, $season);

        if (! $rule) {
            return [
                'has_rule' => false,
                'series' => $series,
                'label' => $series === 'PR22' ? 'PR22 (Rimfire)' : 'PRS (Centrefire)',
                'description' => 'No qualification rules configured for this season yet.',
                'scoring_mode' => null,
                'matches_completed' => array_sum($levelCounts),
                'matches_required' => 0,
                'oop' => $oop,
                'steps' => [],
            ];
        }

        $steps = $this->buildProcessSteps($rule, $levelCounts);
        $matchesRequired = (int) ($rule->total_qualifying_matches ?: collect($steps)->sum('required'));
        $matchesCompleted = min($matchesRequired, collect($steps)->sum(
            fn (array $step) => min($step['completed'], $step['required'])
        ));

        return [
            'has_rule' => true,
            'series' => $series,
            'label' => $series === 'PR22' ? 'PR22 (Rimfire)' : 'PRS (Centrefire)',
            'description' => $this->modeDescription($rule),
            'scoring_mode' => $rule->scoring_mode,
            'matches_completed' => $matchesCompleted,
            'matches_required' => $matchesRequired,
            'oop' => $oop,
            'steps' => $steps,
        ];
    }

    /**
     * @param  array{provincial:int, national:int, final:int}  $levelCounts
     * @return list<array{key:string, label:string, completed:int, required:int, detail:?string}>
     */
    private function buildProcessSteps(QualificationRule $rule, array $levelCounts): array
    {
        if ($rule->isPooledScoring()) {
            return [
                [
                    'key' => 'provincial',
                    'label' => 'Provincial pool',
                    'completed' => $levelCounts['provincial'],
                    'required' => (int) ($rule->provincial_pool_best_of ?: 0),
                    'detail' => ((float) $rule->provincial_pool_weight_pct).'% of season total',
                ],
                [
                    'key' => 'national',
                    'label' => 'National pool',
                    // Drop-one: need best_of + 1 shot to fully fill counting slots.
                    'completed' => $levelCounts['national'],
                    'required' => (int) ($rule->national_pool_best_of ?: 0) + 1,
                    'detail' => ((float) $rule->national_pool_weight_pct).'% · drop worst',
                ],
                [
                    'key' => 'champs',
                    'label' => 'SA Champs',
                    'completed' => $levelCounts['final'],
                    'required' => max(1, (int) ($rule->champs_pool_best_of ?: 1)),
                    'detail' => ((float) $rule->champs_pool_weight_pct).'% of season total',
                ],
            ];
        }

        if ($rule->isAnnualLogWithChamps()) {
            $regularBestOf = $rule->regularBestOf();

            return [
                [
                    'key' => 'national',
                    'label' => 'Best regular nationals',
                    'completed' => $levelCounts['national'],
                    'required' => $regularBestOf,
                    'detail' => "Best {$regularBestOf} national match %",
                ],
                [
                    'key' => 'champs',
                    'label' => 'SA Champs (fixed)',
                    'completed' => $levelCounts['final'],
                    'required' => 1,
                    'detail' => 'Non-droppable championship %',
                ],
            ];
        }

        // Classic best-of-N across national + final matches.
        $bestOf = (int) ($rule->best_of_count ?: $rule->total_qualifying_matches ?: 0);

        return [
            [
                'key' => 'national',
                'label' => 'Qualifying nationals',
                'completed' => $levelCounts['national'] + $levelCounts['final'],
                'required' => $bestOf,
                'detail' => $bestOf > 0 ? "Best {$bestOf} normalised scores" : null,
            ],
        ];
    }

    private function modeDescription(QualificationRule $rule): string
    {
        if ($rule->isPooledScoring()) {
            return 'Weighted pools: provincial, national, and SA Champs each contribute to the season total.';
        }

        if ($rule->isAnnualLogWithChamps()) {
            $n = $rule->regularBestOf();

            return "Annual national log: best {$n} regular national match percentages plus a fixed SA Champs percentage.";
        }

        $n = (int) ($rule->best_of_count ?: 0);

        return $n > 0
            ? "Best-of-{$n}: top normalised national/final scores are summed for the season."
            : 'Season standings from national and final match scores.';
    }

    /**
     * @return array{provincial:int, national:int, final:int}
     */
    private function countMatchesByLevel(User $user, string $series, string $season): array
    {
        /** @var Collection<int, string|null> $levels */
        $levels = Score::query()
            ->where('user_id', $user->id)
            ->where('status', 'valid')
            ->whereHas('match', function ($query) use ($series, $season) {
                $query->where('series', $series)
                    ->where(function ($q) use ($season) {
                        $q->where('season', $season)
                            ->orWhereRaw('YEAR(match_date) = ?', [$season]);
                    });
            })
            ->with('match:id,series_level')
            ->get()
            ->unique('match_id')
            ->map(fn (Score $score) => $score->match?->series_level);

        return [
            'provincial' => $levels->filter(fn ($level) => $level === 'provincial')->count(),
            'national' => $levels->filter(fn ($level) => $level === 'national')->count(),
            'final' => $levels->filter(fn ($level) => $level === 'final')->count(),
        ];
    }

    private function countOutOfProvinceMatches(User $user, string $series, string $season): int
    {
        return Score::query()
            ->where('user_id', $user->id)
            ->where('status', 'valid')
            ->whereHas('match', function ($query) use ($user, $series, $season) {
                $query->where('series', $series)
                    ->where('series_level', 'national')
                    ->where(function ($q) use ($season) {
                        $q->where('season', $season)
                            ->orWhereRaw('YEAR(match_date) = ?', [$season]);
                    })
                    ->where('province_id', '!=', $user->province_id);
            })
            ->distinct('match_id')
            ->count('match_id');
    }
}
