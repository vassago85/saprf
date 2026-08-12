<?php

namespace App\Services\Selection\Rulesets;

use App\Models\SelectionAthlete;

/**
 * Contract for a series+version specific scoring ruleset. Implementations
 * compute weighted selection scores and colour thresholds, and persist SCR-*
 * rule evaluations that expose the raw and division-relative percentages via
 * their detail JSON so the admin UI can render them without recomputation.
 */
interface ScoringRuleset
{
    /**
     * Evaluate scoring for one athlete. Some rulesets (like PR22 v1.1) need
     * division-wide context to rescale a shooter's raw weighted % against the
     * division winner; those rulesets take a second pass at the whole cycle
     * once every individual raw weighted % is on file. Callers should invoke
     * `evaluate()` first and `finalizeCycle()` afterwards.
     *
     * @return array<string, array{outcome: string, detail: array<string, mixed>}>
     */
    public function evaluate(SelectionAthlete $athlete): array;

    /**
     * Second-pass hook: rescale every athlete's raw weighted % against the
     * division top for the whole cycle. Implementations that don't need this
     * (e.g. non-weighted rulesets) may leave it as a no-op.
     */
    public function finalizeCycle(int $selectionCycleId): void;
}
