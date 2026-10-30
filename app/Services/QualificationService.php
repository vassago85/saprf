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

        // A rule of 0 (or no rule row at all) means "no out-of-province
        // requirement for this series/season" — every shooter who shows up
        // on the standings list qualifies for finals. Anything above 0 is
        // the normal "shoot N nationals outside your province" gate.
        $qualified = $required === 0 || $completed >= $required;

        return [
            'required' => $required,
            'completed' => $completed,
            'qualified' => $qualified,
            'remaining' => max(0, $required - $completed),
        ];
    }

    public function isQualifiedForFinals(User $user, string $series, string $season): bool
    {
        return $this->getQualificationStatus($user, $series, $season)['qualified'];
    }

    /**
     * 2027 rules (29 October 2026): entering the championships needs a
     * fully paid-up SAPRF membership, plus 1 national and 1 provincial
     * match in the same discipline and season. Any shot match counts
     * toward the two matches, whatever the shooter's membership status
     * was on the day. The paid-up check is the membership held now.
     * `applies` is false for every other match or scoring mode.
     *
     * @return array{applies:bool, national:int, provincial:int, paid_up:bool, eligible:bool}
     */
    public function championshipEntryStatus(User $user, MatchEvent $match): array
    {
        $season = $match->season ?: (string) $match->match_date->year;

        $applies = $match->series_level === 'final'
            && QualificationRule::query()
                ->where('series', $match->series)
                ->where('season', $season)
                ->where('scoring_mode', QualificationRule::MODE_NATIONAL_SERIES_2027)
                ->exists();

        if (! $applies) {
            return ['applies' => false, 'national' => 0, 'provincial' => 0, 'paid_up' => true, 'eligible' => true];
        }

        $levels = $user->exists
            ? Score::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ScoreValidationService::VISIBLE_STATUSES)
                ->whereHas('match', fn ($q) => $q
                    ->where('series', $match->series)
                    ->where('season', $season)
                    ->whereIn('series_level', ['national', 'provincial']))
                ->with('match:id,series_level')
                ->get()
                ->unique('match_id')
                ->map(fn (Score $score) => $score->match?->series_level)
            : collect();

        $national = $levels->filter(fn ($level) => $level === 'national')->count();
        $provincial = $levels->filter(fn ($level) => $level === 'provincial')->count();
        $paidUp = $user->isActiveMember();

        return [
            'applies' => true,
            'national' => $national,
            'provincial' => $provincial,
            'paid_up' => $paidUp,
            'eligible' => $paidUp && $national >= 1 && $provincial >= 1,
        ];
    }

    /**
     * Sentence shown when championshipEntryStatus() blocks an entry.
     * Names only the requirements the shooter has not met yet.
     *
     * @param  array{national?:int, provincial?:int, paid_up?:bool}  $status
     */
    public function championshipEntryMessage(array $status, string $series, string $who = 'You'): string
    {
        $parts = [];

        if (array_key_exists('paid_up', $status) && $status['paid_up'] === false) {
            $parts[] = 'a fully paid-up SAPRF membership';
        }

        if (($status['national'] ?? 0) < 1 || ($status['provincial'] ?? 0) < 1) {
            $parts[] = sprintf(
                '1 national and 1 provincial %s match (nationals shot: %d, provincials shot: %d)',
                $series,
                $status['national'] ?? 0,
                $status['provincial'] ?? 0,
            );
        }

        $need = $parts === []
            ? 'the 2027 championship entry requirements'
            : implode(' and ', $parts);

        return "{$who} can’t enter the championships yet. The SAPRF rules require {$need}.";
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
            // See getQualificationStatus() — required=0 means "no OOP
            // requirement for this series", so everyone qualifies.
            $qualified = $required === 0 || $completed >= $required;
            $out[$userId] = [
                'required' => $required,
                'completed' => $completed,
                'qualified' => $qualified,
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
     * @param  array{provincial:int, national:int, international:int, final:int}  $levelCounts
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

        if ($rule->isNationalSeries2027()) {
            $bestOf = $rule->regularBestOf();
            $homeCap = QualificationRule::NATIONAL_SERIES_2027_HOME_PROVINCE_CAP;

            return [
                [
                    'key' => 'national',
                    'label' => 'Best nationals',
                    'completed' => $levelCounts['national'] + ($levelCounts['international'] ?? 0),
                    'required' => $bestOf,
                    'detail' => "Best {$bestOf} nationals or the PRS International · max {$homeCap} from your home province",
                ],
                [
                    'key' => 'champs_entry',
                    'label' => 'Championship entry',
                    'completed' => min(1, $levelCounts['national']) + min(1, $levelCounts['provincial']),
                    'required' => 2,
                    'detail' => 'Paid-up member, plus 1 national and 1 provincial match',
                ],
                [
                    'key' => 'champs',
                    'label' => 'Championships (whole match)',
                    'completed' => $levelCounts['final'],
                    'required' => 1,
                    'detail' => 'One match, max 200 on the log. Selection counts it as 100 and it is mandatory',
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

        if ($rule->isNationalSeries2027()) {
            $n = $rule->regularBestOf();

            return "2027 national series: best {$n} nationals or the PRS International (max ".QualificationRule::NATIONAL_SERIES_2027_HOME_PROVINCE_CAP." from your home province) plus the SA Championships as one match, out of 500. Selection scores the championships out of 100 (400 total) and they are mandatory.";
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
     * Short, mockup-ready counting-rule line for the top of the public
     * standings page. Keeps the specific "best N / out of M" shape the
     * design calls for ("Best 3 match scores count, each out of 100.")
     * rather than the longer dashboard sentence.
     *
     * The `$level` arg is required because provincial standings run under
     * a different formula to the national ones even for the same series:
     * PRS national uses the annual log, PRS provincial uses best-of-N,
     * PR22 national uses weighted pools, PR22 provincial uses best-of-N.
     *
     * Returns a plain sentence (no HTML). Safe to echo via {{ }}.
     */
    public function countingRuleText(string $series, string $season, string $level = 'national'): string
    {
        $rule = QualificationRule::query()
            ->where('series', $series)
            ->where('season', $season)
            ->first();

        if ($level === 'provincial') {
            $bestOf = (int) ($rule?->provincial_pool_best_of ?: $rule?->best_of_count ?: 3);

            return "Provincial standings: best {$bestOf} match scores count toward the season total.";
        }

        if (! $rule) {
            return 'Season standings from national and final match scores.';
        }

        if ($rule->isNationalSeries2027()) {
            $n = $rule->regularBestOf();
            $cap = QualificationRule::NATIONAL_SERIES_2027_HOME_PROVINCE_CAP;

            return "Best {$n} nationals or the PRS International (max {$cap} from your home province) plus the SA Championships weighted as one match, out of 500.";
        }

        if ($rule->isAnnualLogWithChamps()) {
            $n = $rule->regularBestOf();

            return "Best {$n} regular national match scores plus a fixed SA Champs percentage, out of 400.";
        }

        if ($rule->isPooledScoring()) {
            $prov = (int) $rule->provincial_pool_weight_pct;
            $nat = (int) $rule->national_pool_weight_pct;
            $champs = (int) $rule->champs_pool_weight_pct;

            return "Weighted pools: provincial {$prov}% + national {$nat}% + SA Champs {$champs}%, out of 100.";
        }

        $n = (int) ($rule->best_of_count ?: 3);

        return "Best {$n} national or final match scores count toward the season total, out of ".($n * 100).'.';
    }

    /**
     * Numeric "/ max" denominator rendered in the standings table header
     * next to the Points column. Matches the mockup "POINTS / 300" copy.
     *
     * Falls back to 100 for provincial / pooled modes where every row is
     * already a percentage of its own pool, which is what the table
     * already shows today.
     */
    public function countingRuleMax(string $series, string $season, string $level = 'national'): int
    {
        $rule = QualificationRule::query()
            ->where('series', $series)
            ->where('season', $season)
            ->first();

        if ($level === 'provincial') {
            $bestOf = (int) ($rule?->provincial_pool_best_of ?: $rule?->best_of_count ?: 3);

            return $bestOf * 100;
        }

        if (! $rule) {
            return 300;
        }

        if ($rule->isNationalSeries2027()) {
            return 500;
        }

        if ($rule->isAnnualLogWithChamps()) {
            return 400;
        }

        if ($rule->isPooledScoring()) {
            return 100;
        }

        $n = (int) ($rule->best_of_count ?: 3);

        return $n * 100;
    }

    /**
     * @return array{provincial:int, national:int, international:int, final:int}
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
                        // whereYear is SQLite-safe; raw YEAR() is MySQL-only.
                        $q->where('season', $season)
                            ->orWhereYear('match_date', $season);
                    });
            })
            ->with('match:id,series_level')
            ->get()
            ->unique('match_id')
            ->map(fn (Score $score) => $score->match?->series_level);

        return [
            'provincial' => $levels->filter(fn ($level) => $level === 'provincial')->count(),
            'national' => $levels->filter(fn ($level) => $level === 'national')->count(),
            'international' => $levels->filter(fn ($level) => $level === 'international')->count(),
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
                            ->orWhereYear('match_date', $season);
                    });

                if ($user->province_id) {
                    $query->where('province_id', '!=', $user->province_id);
                } else {
                    $query->whereNotNull('province_id');
                }
            })
            ->distinct('match_id')
            ->count('match_id');
    }
}
