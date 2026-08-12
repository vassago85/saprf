<?php

namespace App\Services\Selection\Rulesets;

use App\Models\SelectionAthlete;
use App\Models\SelectionParticipationSnapshot;
use App\Models\SelectionRuleEvaluation;
use Illuminate\Support\Facades\DB;

/**
 * "Everyone has participated enough" ruleset. Companion to
 * AutoPassEligibilityRuleset for cycles running in
 * evaluation_mode = 'assume_qualified'.
 *
 * Emits an OUTCOME_PASS row for every PART-* rule ID declared by the cycle's
 * policy (or a v1.4 fallback of PART-01..07 if none), and writes an empty
 * participation snapshot with zero counts so downstream code (state service,
 * admin UI, exports) still finds the row it expects. The zero counts are
 * intentional — the whole point of auto-pass is that we are NOT asserting a
 * count, we are asserting the count doesn't matter.
 */
class AutoPassParticipationRuleset implements ParticipationRuleset
{
    public function evaluate(SelectionAthlete $athlete): SelectionParticipationSnapshot
    {
        $cycle = $athlete->cycle;
        $policy = $cycle?->activePolicy;
        $policyVersion = $policy?->version ?? 'auto-pass';

        $ruleIds = collect($policy?->spec_json['participation']['rules'] ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();
        if (empty($ruleIds)) {
            $ruleIds = ['PART-01', 'PART-02', 'PART-03', 'PART-04', 'PART-05', 'PART-06', 'PART-07'];
        }

        return DB::transaction(function () use ($athlete, $ruleIds, $policy, $policyVersion) {
            $now = now();
            foreach ($ruleIds as $ruleId) {
                SelectionRuleEvaluation::create([
                    'selection_athlete_id' => $athlete->id,
                    'rule_id' => $ruleId,
                    'outcome' => SelectionRuleEvaluation::OUTCOME_PASS,
                    'detail' => ['reason' => 'auto_pass_mode'],
                    'policy_version' => $policyVersion,
                    'evaluated_at' => $now,
                ]);
            }

            return SelectionParticipationSnapshot::updateOrCreate(
                ['selection_athlete_id' => $athlete->id],
                [
                    'provincial_1d_count' => 0,
                    'national_2d_count' => 0,
                    'international_2d_count' => 0,
                    'out_of_home_province_2d_count' => 0,
                    'sa_champs_shot' => false,
                    'counted_score_ids' => [],
                    'computed_at' => $now,
                    'computed_against_policy_id' => $policy?->id,
                ],
            );
        });
    }
}
