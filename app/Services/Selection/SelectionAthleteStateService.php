<?php

namespace App\Services\Selection;

use App\Models\SelectionAthlete;
use App\Models\SelectionDeclaration;
use App\Models\SelectionRuleEvaluation;
use App\Models\SelectionWaiver;

/**
 * Walks a SelectionAthlete through the pipeline based on the latest rule
 * evaluations and declaration / waiver rows:
 *
 *   registered
 *     -> eligible          (all ELG-* pass, MANUAL treated as blocker)
 *     -> declared          (declaration submitted before cycle deadline)
 *     -> squad_qualified   (all PART-* pass, or waived-and-granted)
 *
 * Phase 1 stops before `scored`. States `selected`, `individual`,
 * `not_selected`, and `substituted` are set by future selector-workspace
 * code, not by this service.
 *
 * Post-selection states are never rolled back by this service.
 */
class SelectionAthleteStateService
{
    private const TERMINAL_STATES = [
        SelectionAthlete::STATE_SELECTED,
        SelectionAthlete::STATE_INDIVIDUAL,
        SelectionAthlete::STATE_NOT_SELECTED,
        SelectionAthlete::STATE_SUBSTITUTED,
    ];

    public function recompute(SelectionAthlete $athlete): SelectionAthlete
    {
        if (in_array($athlete->state, self::TERMINAL_STATES, true)) {
            return $athlete;
        }

        $target = $this->computeTargetState($athlete);
        $athlete->forceFill([
            'state' => $target,
            'last_evaluated_at' => now(),
            'evaluated_against_policy_id' => $athlete->cycle?->active_policy_version_id,
        ])->save();

        return $athlete;
    }

    private function computeTargetState(SelectionAthlete $athlete): string
    {
        [$elgRuleIds, $partRuleIds] = $this->ruleIdsForPolicy($athlete);

        $elgOk = $this->allRulesPass($athlete, $elgRuleIds);
        if (! $elgOk) {
            return SelectionAthlete::STATE_REGISTERED;
        }

        $declaration = $athlete->declaration()->first();
        if (! $this->declaredInTime($athlete, $declaration)) {
            return SelectionAthlete::STATE_ELIGIBLE;
        }

        $partOk = $this->allRulesPassOrWaived($athlete, $partRuleIds);
        if (! $partOk) {
            return SelectionAthlete::STATE_DECLARED;
        }

        return SelectionAthlete::STATE_SQUAD_QUALIFIED;
    }

    /**
     * Pulls the ELG / PART rule ID lists straight out of the cycle's active
     * policy JSON so the gate lists are always in step with the rules the
     * evaluator actually wrote. Falls back to the v1.4 rule set (6 ELG,
     * 7 PART) when a policy is missing or malformed so pre-refactor cycles
     * keep working.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function ruleIdsForPolicy(SelectionAthlete $athlete): array
    {
        $spec = $athlete->cycle?->activePolicy?->spec_json ?? [];
        $elg = collect($spec['eligibility']['rules'] ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();
        $part = collect($spec['participation']['rules'] ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        if (empty($elg)) {
            $elg = ['ELG-01', 'ELG-02', 'ELG-03', 'ELG-04', 'ELG-05', 'ELG-06'];
        }
        if (empty($part)) {
            $part = ['PART-01', 'PART-02', 'PART-03', 'PART-04', 'PART-05', 'PART-06', 'PART-07'];
        }

        return [$elg, $part];
    }

    /**
     * @param  array<string>  $ruleIds
     */
    private function allRulesPass(SelectionAthlete $athlete, array $ruleIds): bool
    {
        $latest = $this->latestEvaluations($athlete, $ruleIds);
        foreach ($ruleIds as $ruleId) {
            if (! $this->outcomeCountsAsPass($latest[$ruleId] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string>  $ruleIds
     */
    private function allRulesPassOrWaived(SelectionAthlete $athlete, array $ruleIds): bool
    {
        $latest = $this->latestEvaluations($athlete, $ruleIds);
        $granted = SelectionWaiver::query()
            ->where('selection_athlete_id', $athlete->id)
            ->where('outcome', SelectionWaiver::OUTCOME_GRANTED)
            ->pluck('waived_rule_id')
            ->all();

        foreach ($ruleIds as $ruleId) {
            if ($this->outcomeCountsAsPass($latest[$ruleId] ?? null)) {
                continue;
            }
            if (in_array($ruleId, $granted, true)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * PASS obviously counts. BLOCKED means the evaluator can't yet check the
     * rule (e.g. PART-06 needs a sanctioning_body column that doesn't exist
     * yet); treating BLOCKED as pass is fail-open so an unimplemented rule
     * doesn't strand every athlete. NA means the rule doesn't apply. Any
     * other outcome — FAIL, MANUAL, or null (never evaluated) — is a
     * blocker until an admin resolves it.
     */
    private function outcomeCountsAsPass(?string $outcome): bool
    {
        return in_array($outcome, [
            SelectionRuleEvaluation::OUTCOME_PASS,
            SelectionRuleEvaluation::OUTCOME_BLOCKED,
            SelectionRuleEvaluation::OUTCOME_NA,
        ], true);
    }

    private function declaredInTime(SelectionAthlete $athlete, ?SelectionDeclaration $declaration): bool
    {
        if (! $declaration) {
            return false;
        }
        if ($declaration->status !== SelectionDeclaration::STATUS_SUBMITTED) {
            return false;
        }
        if (! $declaration->submitted_at) {
            return false;
        }

        $deadline = $athlete->cycle?->declaration_deadline;
        if (! $deadline) {
            return true;
        }

        return $declaration->submitted_at->lte($deadline);
    }

    /**
     * @param  array<string>  $ruleIds
     * @return array<string, string>
     */
    private function latestEvaluations(SelectionAthlete $athlete, array $ruleIds): array
    {
        $rows = SelectionRuleEvaluation::query()
            ->where('selection_athlete_id', $athlete->id)
            ->whereIn('rule_id', $ruleIds)
            ->orderByDesc('evaluated_at')
            ->orderByDesc('id')
            ->get(['rule_id', 'outcome']);

        $out = [];
        foreach ($rows as $row) {
            if (! isset($out[$row->rule_id])) {
                $out[$row->rule_id] = $row->outcome;
            }
        }

        return $out;
    }
}
