<?php

namespace App\Services\Selection\Rulesets;

use App\Models\SelectionAthlete;
use App\Models\SelectionRuleEvaluation;
use Illuminate\Support\Facades\DB;

/**
 * "Everyone is eligible" ruleset. Used when a SelectionCycle sets
 * evaluation_mode = 'assume_qualified' — typically historical cycles whose
 * team has already been chosen, or current cycles where the data isn't yet
 * good enough to run the strict rules and the only real gate is the
 * nomination letter (DEC-01).
 *
 * Emits an OUTCOME_PASS row for every ELG-* rule ID declared by the cycle's
 * policy (or a v1.4 fallback of ELG-01..06 if the policy doesn't list any),
 * with a "reason" of "auto_pass_mode" so the audit trail is unambiguous
 * about why the rule passed.
 */
class AutoPassEligibilityRuleset implements EligibilityRuleset
{
    public function evaluate(SelectionAthlete $athlete): array
    {
        $cycle = $athlete->cycle;
        if (! $cycle) {
            return [];
        }

        $policy = $cycle->activePolicy;
        $policyVersion = $policy?->version ?? 'auto-pass';

        $ruleIds = collect($policy?->spec_json['eligibility']['rules'] ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();
        if (empty($ruleIds)) {
            $ruleIds = ['ELG-01', 'ELG-02', 'ELG-03', 'ELG-04', 'ELG-05', 'ELG-06'];
        }

        $results = [];
        foreach ($ruleIds as $ruleId) {
            $results[$ruleId] = [
                'outcome' => SelectionRuleEvaluation::OUTCOME_PASS,
                'detail' => ['reason' => 'auto_pass_mode'],
            ];
        }

        DB::transaction(function () use ($athlete, $results, $policyVersion) {
            $now = now();
            foreach ($results as $ruleId => $result) {
                SelectionRuleEvaluation::create([
                    'selection_athlete_id' => $athlete->id,
                    'rule_id' => $ruleId,
                    'outcome' => $result['outcome'],
                    'detail' => $result['detail'],
                    'policy_version' => $policyVersion,
                    'evaluated_at' => $now,
                ]);
            }
        });

        return $results;
    }
}
