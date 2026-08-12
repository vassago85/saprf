<?php

namespace App\Services\Selection\Rulesets;

use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionRuleEvaluation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SAPRF PR22 v1.1 scoring ruleset — implements the explicit 30/40/30
 * weighted formula from the v1.1 document.
 *
 * Per athlete:
 *   raw_weighted_pct =
 *     avg(top-3 provincial normalized_score) * 0.30 +
 *     avg(top-2 national/international normalized_score) * 0.40 +
 *     sa_champs normalized_score * 0.30
 *
 * Missing components contribute 0. So an athlete who never shot SA Champs
 * caps at 70%; who shot fewer than 3 provincials averages what they did shoot
 * (or 0 with none). This mirrors the plain reading of the document — it
 * doesn't say to zero out the entire result if any component is short.
 *
 * Then, per division: division_pct = raw / division_top_raw * 100. Protea
 * threshold is measured on division_pct at 85%. finalizeCycle() writes SCR-02
 * (division-relative %) and SCR-03 (colour eligibility) once every athlete
 * has an SCR-01 row on file.
 */
class Pr22V11ScoringRuleset implements ScoringRuleset
{
    public function evaluate(SelectionAthlete $athlete): array
    {
        $cycle = $athlete->cycle;
        $policy = $cycle?->activePolicy;
        $policyVersion = $policy?->version ?? 'unknown';
        $formula = $policy?->spec_json['scoring']['formula'] ?? [];
        $components = $formula['components'] ?? $this->defaultComponents();

        $scores = $this->scoresInPeriod($athlete)->get();

        $breakdown = [];
        $rawWeighted = 0.0;
        foreach ($components as $component) {
            $entry = $this->computeComponent($component, $scores);
            $breakdown[$component['source']] = $entry;
            $rawWeighted += $entry['contribution_pct'];
        }
        $rawWeighted = round($rawWeighted, 4);

        $now = now();
        $result = [
            'SCR-01' => [
                'outcome' => SelectionRuleEvaluation::OUTCOME_PASS,
                'detail' => [
                    'raw_weighted_pct' => $rawWeighted,
                    'raw_weighted_max' => 100.0,
                    'components' => $breakdown,
                ],
            ],
        ];

        DB::transaction(function () use ($athlete, $result, $policyVersion, $now) {
            SelectionRuleEvaluation::create([
                'selection_athlete_id' => $athlete->id,
                'rule_id' => 'SCR-01',
                'outcome' => $result['SCR-01']['outcome'],
                'detail' => $result['SCR-01']['detail'],
                'policy_version' => $policyVersion,
                'evaluated_at' => $now,
            ]);
        });

        return $result;
    }

    public function finalizeCycle(int $selectionCycleId): void
    {
        $athletes = SelectionAthlete::query()
            ->where('selection_cycle_id', $selectionCycleId)
            ->with(['cycle.activePolicy'])
            ->get();

        if ($athletes->isEmpty()) {
            return;
        }

        $policy = $athletes->first()->cycle?->activePolicy;
        $policyVersion = $policy?->version ?? 'unknown';
        $threshold = (float) ($policy?->spec_json['scoring']['formula']['protea_threshold_pct'] ?? 85.0);

        // Collect each athlete's latest SCR-01 raw_weighted_pct, keyed by athlete id.
        $latestScr01 = SelectionRuleEvaluation::query()
            ->whereIn('selection_athlete_id', $athletes->pluck('id'))
            ->where('rule_id', 'SCR-01')
            ->orderBy('selection_athlete_id')
            ->orderByDesc('id')
            ->get()
            ->groupBy('selection_athlete_id')
            ->map(fn (Collection $rows) => $rows->first());

        // Division top raw_weighted_pct, keyed by division id. Athletes with
        // no claimed_division_id are bucketed under 0 and get a division_pct
        // of 0 (they can't be Protea-ranked until admin assigns a division).
        $divisionTops = [];
        foreach ($athletes as $athlete) {
            $row = $latestScr01[$athlete->id] ?? null;
            $raw = (float) ($row?->detail['raw_weighted_pct'] ?? 0.0);
            $divisionId = (int) ($athlete->claimed_division_id ?? 0);
            $divisionTops[$divisionId] = max($divisionTops[$divisionId] ?? 0.0, $raw);
        }

        $now = now();
        DB::transaction(function () use ($athletes, $latestScr01, $divisionTops, $policyVersion, $threshold, $now) {
            foreach ($athletes as $athlete) {
                $row = $latestScr01[$athlete->id] ?? null;
                $raw = (float) ($row?->detail['raw_weighted_pct'] ?? 0.0);
                $divisionId = (int) ($athlete->claimed_division_id ?? 0);
                $divisionTop = $divisionTops[$divisionId] ?? 0.0;

                $divisionPct = $divisionTop > 0
                    ? round($raw / $divisionTop * 100, 4)
                    : 0.0;

                $proteaEligible = $divisionPct >= $threshold;

                SelectionRuleEvaluation::create([
                    'selection_athlete_id' => $athlete->id,
                    'rule_id' => 'SCR-02',
                    'outcome' => SelectionRuleEvaluation::OUTCOME_PASS,
                    'detail' => [
                        'raw_weighted_pct' => $raw,
                        'division_top_raw_weighted_pct' => $divisionTop,
                        'division_pct' => $divisionPct,
                        'claimed_division_id' => $athlete->claimed_division_id,
                    ],
                    'policy_version' => $policyVersion,
                    'evaluated_at' => $now,
                ]);

                SelectionRuleEvaluation::create([
                    'selection_athlete_id' => $athlete->id,
                    'rule_id' => 'SCR-03',
                    'outcome' => $proteaEligible
                        ? SelectionRuleEvaluation::OUTCOME_PASS
                        : SelectionRuleEvaluation::OUTCOME_FAIL,
                    'detail' => [
                        'division_pct' => $divisionPct,
                        'threshold_pct' => $threshold,
                        'colour_eligibility' => $proteaEligible ? 'protea' : 'federation',
                    ],
                    'policy_version' => $policyVersion,
                    'evaluated_at' => $now,
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  Collection<int, Score>  $scores
     * @return array{normalized_scores: array<float>, average_normalized: float, per_match_weight_pct: float, cap_pct: float, contribution_pct: float}
     */
    private function computeComponent(array $component, Collection $scores): array
    {
        $source = $component['source'];
        $perMatchWeight = (float) ($component['per_match_weight_pct'] ?? 0);
        $capPct = (float) ($component['cap_pct'] ?? 0);
        $selection = $component['match_selection'] ?? '';

        [$targetLevels, $takeCount] = match ($source) {
            'provincial_1d' => [['provincial'], 3],
            'national_or_international_2d' => [['national', 'international'], 2],
            'sa_champs' => [['final'], 1],
            default => [[], 0],
        };

        $eligible = $scores
            ->filter(fn (Score $s) => in_array($s->match?->series_level, $targetLevels, true))
            ->map(fn (Score $s) => (float) ($s->normalized_score ?? 0.0))
            ->sortDesc()
            ->values();

        $picked = $eligible->take($takeCount);

        $countedMatches = $picked->count();

        // Contribution is per-match: each counted match contributes
        // (normalized_score/100) * per_match_weight_pct toward the final %.
        $contribution = $picked->sum(fn (float $normalized) => ($normalized / 100.0) * $perMatchWeight);
        $contribution = min(round((float) $contribution, 4), $capPct);

        $averageNormalized = $countedMatches > 0
            ? round((float) $picked->avg(), 4)
            : 0.0;

        return [
            'match_selection' => $selection,
            'normalized_scores' => $picked->all(),
            'average_normalized' => $averageNormalized,
            'per_match_weight_pct' => $perMatchWeight,
            'cap_pct' => $capPct,
            'contribution_pct' => $contribution,
            'counted_matches' => $countedMatches,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultComponents(): array
    {
        return [
            ['source' => 'provincial_1d', 'match_selection' => 'top_3_normalized_scores', 'per_match_weight_pct' => 10, 'cap_pct' => 30],
            ['source' => 'national_or_international_2d', 'match_selection' => 'top_2_normalized_scores', 'per_match_weight_pct' => 20, 'cap_pct' => 40],
            ['source' => 'sa_champs', 'match_selection' => 'single_final_match', 'per_match_weight_pct' => 30, 'cap_pct' => 30],
        ];
    }

    private function scoresInPeriod(SelectionAthlete $athlete)
    {
        $cycle = $athlete->cycle;

        return Score::query()
            ->with('match')
            ->where('user_id', $athlete->user_id)
            ->where('counts_for_season', true)
            ->where('status', 'valid')
            ->whereHas('match', fn ($q) => $q
                ->where('series', $cycle->series)
                ->whereBetween('match_date', [
                    $cycle->qualifying_period_start,
                    $cycle->qualifying_period_end,
                ])
                ->whereIn('series_level', ['provincial', 'national', 'international', 'final']));
    }
}
