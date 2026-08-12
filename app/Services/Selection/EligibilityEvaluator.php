<?php

namespace App\Services\Selection;

use App\Models\SelectionAthlete;

/**
 * Thin dispatcher — picks the right series+version ruleset (or the
 * AutoPassEligibilityRuleset when the cycle is running in
 * 'assume_qualified' mode) from the athlete's cycle policy and delegates.
 * All the actual rule logic lives in the concrete Rulesets\* classes. This
 * keeps callers (controllers, artisan commands, other services) agnostic
 * of which policy version or mode they're evaluating against.
 */
class EligibilityEvaluator
{
    public function __construct(private readonly RulesetResolver $resolver)
    {
    }

    /**
     * @return array<string, array{outcome: string, detail: array<string, mixed>}>
     */
    public function evaluate(SelectionAthlete $athlete): array
    {
        return $this->resolver->forAthlete($athlete)['eligibility']->evaluate($athlete);
    }
}
