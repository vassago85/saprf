<?php

namespace App\Services\Selection\Rulesets;

use App\Models\SelectionAthlete;
use App\Models\SelectionParticipationSnapshot;
use App\Models\SelectionRuleEvaluation;
use App\Services\Selection\RulesetResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Everyone has participated enough" ruleset. Companion to
 * AutoPassEligibilityRuleset for cycles running in
 * evaluation_mode = 'assume_qualified'.
 *
 * Emits an OUTCOME_PASS row for every PART-* rule ID declared by the cycle's
 * policy (or a v1.4 fallback of PART-01..07 if none). The auto-pass is the
 * gate — the nomination letter is what ultimately decides who moves past
 * `eligible`.
 *
 * The snapshot itself is populated with *real* informational counts by
 * borrowing the strict engine's ParticipationRuleset via RulesetResolver.
 * This lets admins see who's actually hit the participation criteria (e.g.
 * "6 provincial 1-day, 2 national 2-day, out-of-home yes, SA Champs no")
 * even though the counts don't gate qualification in this mode. If the
 * informational count fails for any reason (missing policy, unexpected
 * shape), the snapshot falls back to zeros so a display feature can never
 * break the auto-pass gate.
 */
class AutoPassParticipationRuleset implements ParticipationRuleset
{
    public function __construct(private readonly RulesetResolver $resolver)
    {
    }

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

        $snapshotPayload = $this->informationalSnapshotPayload($athlete);

        return DB::transaction(function () use ($athlete, $ruleIds, $policy, $policyVersion, $snapshotPayload) {
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
                $snapshotPayload + [
                    'computed_at' => $now,
                    'computed_against_policy_id' => $policy?->id,
                ],
            );
        });
    }

    public function computeSnapshotPayload(SelectionAthlete $athlete): array
    {
        return $this->informationalSnapshotPayload($athlete);
    }

    /**
     * Borrow the engine's strict counter to produce informational numbers
     * for the snapshot. Never propagates exceptions — a display-only feature
     * must not break the auto-pass gate.
     *
     * @return array<string, mixed>
     */
    private function informationalSnapshotPayload(SelectionAthlete $athlete): array
    {
        try {
            return $this->resolver
                ->strictParticipationForCycle($athlete->cycle)
                ->computeSnapshotPayload($athlete);
        } catch (Throwable $e) {
            Log::warning('AutoPassParticipationRuleset: informational count failed, falling back to zeros', [
                'selection_athlete_id' => $athlete->id,
                'exception' => $e->getMessage(),
            ]);

            return $this->zeroPayload($athlete);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function zeroPayload(SelectionAthlete $athlete): array
    {
        return [
            'provincial_1d_count' => 0,
            'national_2d_count' => 0,
            'international_2d_count' => 0,
            'out_of_home_province_2d_count' => 0,
            'sa_champs_shot' => false,
            'counted_score_ids' => [],
            'computed_at' => now(),
            'computed_against_policy_id' => $athlete->cycle?->activePolicy?->id,
        ];
    }
}
