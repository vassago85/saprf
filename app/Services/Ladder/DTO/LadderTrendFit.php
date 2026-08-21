<?php

namespace App\Services\Ladder\DTO;

/**
 * Result of the OLS fit on step means.
 *
 * Two fit paths land here:
 *
 *   - Multi-shot path: the fit runs through steps with n≥2 and include_in_fit
 *     true. singleShotMode is false. This is the strict path — pooled SD from
 *     within-step scatter typically supersedes residualSd for consistency
 *     figures.
 *
 *   - Single-shot path: engaged only when the multi-shot path returns nothing,
 *     e.g. a friend-style eight-charge one-shot-per-step ladder. The fit runs
 *     through every include_in_fit step regardless of n. In this mode
 *     residualSd is the shot-to-shot SD estimate at n−2 df and is the only
 *     consistency figure available.
 *
 * R² and the slope 95% CI are computed for both paths whenever there are ≥3
 * points in the fit (df ≥ 1). Below that they're null because there's no
 * scatter to estimate uncertainty from.
 */
final readonly class LadderTrendFit
{
    public function __construct(
        public float $slope,
        public float $intercept,
        public int $stepsUsed,
        public ?float $rSquared = null,
        public ?float $residualSd = null,
        public ?int $residualDf = null,
        public ?float $slopeSe = null,
        public ?float $slopeCiLower = null,
        public ?float $slopeCiUpper = null,
        public bool $singleShotMode = false,
    ) {}

    public function predict(float $x): float
    {
        return $this->intercept + $this->slope * $x;
    }
}
