<?php

namespace App\Services\Ladder\DTO;

/**
 * Result of the OLS fit on step means. Present only when at least two
 * distinct include_in_fit=true steps have n≥2 shots.
 */
final readonly class LadderTrendFit
{
    public function __construct(
        public float $slope,
        public float $intercept,
        public int $stepsUsed,
    ) {}

    public function predict(float $x): float
    {
        return $this->intercept + $this->slope * $x;
    }
}
