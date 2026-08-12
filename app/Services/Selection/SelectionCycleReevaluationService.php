<?php

namespace App\Services\Selection;

use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use Closure;

/**
 * Bulk driver: runs ELG + PART + SCR evaluators, calls the scoring
 * finalize hook (needed for the v1.1 division-relative rescale), then
 * recomputes state for every SelectionAthlete in a cycle. Used by the admin
 * "Re-evaluate cycle" button and by the selection:reevaluate artisan.
 */
class SelectionCycleReevaluationService
{
    public function __construct(
        private readonly EligibilityEvaluator $elg,
        private readonly ParticipationEvaluator $part,
        private readonly ScoringEvaluator $scoring,
        private readonly SelectionAthleteStateService $state,
    ) {
    }

    /**
     * @return array{athletes: int, elg: int, part: int, scr: int}
     */
    public function run(SelectionCycle $cycle, ?Closure $progress = null): array
    {
        $athletes = SelectionAthlete::forCycle($cycle->id)
            ->with(['user.membership', 'user.club', 'cycle.activePolicy', 'declaration'])
            ->get();

        $elgCount = 0;
        $partCount = 0;
        $scrCount = 0;

        foreach ($athletes as $athlete) {
            $this->elg->evaluate($athlete);
            $elgCount++;

            $refreshed = $athlete->fresh(['user.membership', 'user.club', 'cycle.activePolicy', 'declaration']);
            $this->part->evaluate($refreshed);
            $partCount++;

            $this->scoring->evaluate($refreshed);
            $scrCount++;
        }

        // Second pass: rescale raw weighted % against division tops so the
        // Protea threshold can be measured. No-op for policies (like v1.4)
        // that use NullScoringRuleset.
        $this->scoring->finalizeCycle($cycle);

        // State recompute must come last — it consumes the SCR-* rows from
        // the finalize pass to gate on Protea eligibility where relevant.
        foreach ($athletes as $athlete) {
            $this->state->recompute($athlete->fresh(['cycle.activePolicy', 'declaration']));
            if ($progress) {
                $progress($athlete);
            }
        }

        return [
            'athletes' => $athletes->count(),
            'elg' => $elgCount,
            'part' => $partCount,
            'scr' => $scrCount,
        ];
    }
}
