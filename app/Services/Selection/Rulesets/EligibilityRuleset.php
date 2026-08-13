<?php

namespace App\Services\Selection\Rulesets;

use App\Models\SelectionAthlete;

/**
 * Contract for a series+version specific eligibility ruleset. Implementations
 * evaluate the athlete against the ELG-* rules in the policy currently
 * attached to the athlete's cycle, persist SelectionRuleEvaluation rows for
 * the audit trail, and return the same rule → outcome map they wrote.
 */
interface EligibilityRuleset
{
    /**
     * @return array<string, array{outcome: string, detail: array<string, mixed>}>
     */
    public function evaluate(SelectionAthlete $athlete): array;

    /**
     * Compute the rule → outcome map WITHOUT persisting any
     * SelectionRuleEvaluation rows. Used for read-only status/progress
     * displays that must never mutate the audit trail or the gate.
     *
     * @return array<string, array{outcome: string, detail: array<string, mixed>}>
     */
    public function assess(SelectionAthlete $athlete): array;
}
