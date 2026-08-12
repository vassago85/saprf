<?php

namespace App\Services\Selection;

use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;

/**
 * Thin dispatcher for scoring — v1.1's 30/40/30 weighted formula, or a
 * NullScoringRuleset for policies (like v1.4) that leave ranking to the
 * Selectors' judgement. Two-pass: `evaluate()` computes each athlete's raw
 * weighted %, then `finalizeCycle()` rescales those against the top scorer
 * in each division so the 85% Protea threshold can be measured.
 */
class ScoringEvaluator
{
    public function __construct(private readonly RulesetResolver $resolver)
    {
    }

    /**
     * @return array<string, array{outcome: string, detail: array<string, mixed>}>
     */
    public function evaluate(SelectionAthlete $athlete): array
    {
        return $this->resolver->forAthlete($athlete)['scoring']->evaluate($athlete);
    }

    public function finalizeCycle(SelectionCycle $cycle): void
    {
        $this->resolver->forCycle($cycle)['scoring']->finalizeCycle($cycle->id);
    }
}
