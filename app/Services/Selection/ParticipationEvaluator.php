<?php

namespace App\Services\Selection;

use App\Models\SelectionAthlete;
use App\Models\SelectionParticipationSnapshot;

/**
 * Thin dispatcher — resolves the right series+version ruleset for the
 * athlete's cycle policy and delegates. Concrete counting/capping logic
 * lives in Rulesets\PrsV14ParticipationRuleset (capped 2-day counting
 * with geographical caps for the PRS centrefire cycle) or
 * Pr22V11ParticipationRuleset (raw counts with one out-of-home requirement
 * for PR22 rimfire). When the cycle is in 'assume_qualified' mode the
 * resolver returns AutoPassParticipationRuleset instead.
 */
class ParticipationEvaluator
{
    public function __construct(private readonly RulesetResolver $resolver)
    {
    }

    public function evaluate(SelectionAthlete $athlete): SelectionParticipationSnapshot
    {
        return $this->resolver->forAthlete($athlete)['participation']->evaluate($athlete);
    }
}
