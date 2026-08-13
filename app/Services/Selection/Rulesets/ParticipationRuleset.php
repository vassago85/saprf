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

    /**
     * Compute the informational snapshot payload without persisting it or
     * emitting any rule outcomes. Used by AutoPassParticipationRuleset so
     * admins can see the real participation numbers even when the cycle is
     * in assume_qualified mode — the numbers are informational, the auto-pass
     * gate is unaffected.
     *
     * @return array<string, mixed>  Column values for a
     *                               SelectionParticipationSnapshot row.
     */
    public function computeSnapshotPayload(SelectionAthlete $athlete): array;
}
