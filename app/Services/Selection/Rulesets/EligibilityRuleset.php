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
}
