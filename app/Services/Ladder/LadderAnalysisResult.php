<?php

namespace App\Services\Ladder;

use App\Enums\LadderVariable;
use App\Services\Ladder\DTO\LadderPairComparison;
use App\Services\Ladder\DTO\LadderSdComparison;
use App\Services\Ladder\DTO\LadderStepStats;
use App\Services\Ladder\DTO\LadderTrendFit;
use App\Services\Ladder\DTO\LadderVerdict;

/**
 * Top-level result of {@see LadderAnalysis::analyze()}. Everything the UI
 * displays reads off this DTO — no downstream statistics live in the views.
 */
final readonly class LadderAnalysisResult
{
    /**
     * @param  list<LadderStepStats>  $steps
     * @param  array<int, float>  $residuals  Keyed by step id. Only for steps not in fit.
     * @param  list<LadderPairComparison>  $pairs
     */
    public function __construct(
        public LadderVariable $variable,
        public array $steps,
        public ?float $pooledSd,
        public ?int $pooledDf,
        public ?LadderTrendFit $trend,
        public array $residuals,
        public array $pairs,
        public ?int $roundsRequired,
        public float $resolvingDelta,
        public LadderVerdict $verdict,
        public ?LadderSdComparison $sdComparison,
    ) {}

    /**
     * Steps that contribute to the fit — n≥2 AND include_in_fit=true.
     *
     * @return list<LadderStepStats>
     */
    public function fittedSteps(): array
    {
        return array_values(array_filter(
            $this->steps,
            fn (LadderStepStats $s) => $s->contributesToFit,
        ));
    }

    /**
     * Steps NOT contributing to the fit — either explicitly excluded or n<2.
     *
     * @return list<LadderStepStats>
     */
    public function unfittedSteps(): array
    {
        return array_values(array_filter(
            $this->steps,
            fn (LadderStepStats $s) => ! $s->contributesToFit,
        ));
    }

    /**
     * Largest absolute residual across every step not in fit. Null if there
     * are no residuals (e.g. every step is in the fit).
     */
    public function largestResidual(): ?float
    {
        if ($this->residuals === []) {
            return null;
        }

        $max = 0.0;
        foreach ($this->residuals as $r) {
            if (abs($r) > abs($max)) {
                $max = $r;
            }
        }

        return $max;
    }

    /**
     * The consistency SD figure to quote — pooled-within-step when we have
     * repeats, otherwise the residual SD from a single-shot fit. This is what
     * drives roundsRequired and the ±1 SD band on the chart. Null when neither
     * is available (e.g. two-step ladder with only one shot per step).
     */
    public function effectiveSd(): ?float
    {
        if ($this->pooledSd !== null) {
            return $this->pooledSd;
        }

        return $this->trend?->residualSd;
    }

    /**
     * Degrees of freedom that back {@see effectiveSd()}, so the UI can quote
     * "df 8, pooled" or "df 6, from residual scatter" accurately.
     */
    public function effectiveDf(): ?int
    {
        if ($this->pooledSd !== null) {
            return $this->pooledDf;
        }

        return $this->trend?->residualDf;
    }

    /**
     * "pooled" when the SD figure comes from within-step scatter,
     * "residual" when it comes from the fit residuals of a single-shot
     * ladder, or "none" when we have no consistency figure at all.
     */
    public function effectiveSdSource(): string
    {
        if ($this->pooledSd !== null) {
            return 'pooled';
        }

        if ($this->trend?->residualSd !== null) {
            return 'residual';
        }

        return 'none';
    }
}
