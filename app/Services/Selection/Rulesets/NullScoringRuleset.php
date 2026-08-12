<?php

namespace App\Services\Selection\Rulesets;

use App\Models\SelectionAthlete;
use App\Models\SelectionRuleEvaluation;
use Illuminate\Support\Facades\DB;

/**
 * Scoring no-op for policies that don't declare a weighted scoring formula
 * (e.g. PR22 v1.4 which delegates Team-vs-Squad ranking entirely to the
 * Selectors' judgement). Emits a single SCR-01 row marked NA so the audit
 * trail records that scoring was intentionally skipped, not forgotten.
 */
class NullScoringRuleset implements ScoringRuleset
{
    public function evaluate(SelectionAthlete $athlete): array
    {
        $policyVersion = $athlete->cycle?->activePolicy?->version ?? 'unknown';

        DB::transaction(function () use ($athlete, $policyVersion) {
            SelectionRuleEvaluation::create([
                'selection_athlete_id' => $athlete->id,
                'rule_id' => 'SCR-01',
                'outcome' => SelectionRuleEvaluation::OUTCOME_NA,
                'detail' => ['reason' => 'no_weighted_scoring_formula_in_policy'],
                'policy_version' => $policyVersion,
                'evaluated_at' => now(),
            ]);
        });

        return [
            'SCR-01' => [
                'outcome' => SelectionRuleEvaluation::OUTCOME_NA,
                'detail' => ['reason' => 'no_weighted_scoring_formula_in_policy'],
            ],
        ];
    }

    public function finalizeCycle(int $selectionCycleId): void
    {
        // Intentional no-op.
    }
}
