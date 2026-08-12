<?php

namespace App\Services\Selection\Rulesets;

use App\Models\SelectionAthlete;
use App\Models\SelectionParticipationSnapshot;

/**
 * Contract for a series+version specific participation ruleset. Writes both
 * the numeric snapshot (for admin lists / division cards) and the individual
 * PART-* rule evaluations (for the audit trail) in one call.
 */
interface ParticipationRuleset
{
    public function evaluate(SelectionAthlete $athlete): SelectionParticipationSnapshot;
}
