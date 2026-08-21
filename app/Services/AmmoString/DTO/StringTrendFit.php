<?php

namespace App\Services\AmmoString\DTO;

/**
 * OLS fit of shot velocity against shot number. Slope is in fps per shot —
 * positive means the string climbed as it went on (bore heating on most
 * loads, though sign depends on the powder and cartridge). Only computed
 * for strings with n ≥ 3 non-excluded shots.
 */
final readonly class StringTrendFit
{
    public function __construct(
        public float $slope,
        public float $intercept,
        public float $rSquared,
        public float $slopeSe,
        public float $slopeT,
        public float $slopeP,
        public float $slopeCiLower,
        public float $slopeCiUpper,
        public int $df,
    ) {}

    public function predict(int $shotNumber): float
    {
        return $this->intercept + $this->slope * $shotNumber;
    }

    /**
     * The slope significantly departs from zero at α = 0.05, i.e. there is a
     * real trend across the string rather than random shot-to-shot noise.
     */
    public function isSignificant(): bool
    {
        return $this->slopeP < 0.05;
    }
}
