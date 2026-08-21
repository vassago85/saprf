<?php

namespace App\Services\Ladder\DTO;

/**
 * Per-step statistics computed from the step's non-excluded shots. n<2
 * fields are null rather than zero — the caller must respect that these
 * quantities are undefined for a single-shot string.
 */
final readonly class LadderStepStats
{
    /**
     * @param  list<float>  $velocities  All non-excluded velocities used for these stats.
     */
    public function __construct(
        public int $stepId,
        public float $value,
        public int $n,
        public float $mean,
        public ?float $sd,
        public ?float $se,
        public ?float $es,
        public ?float $sdCiLower,
        public ?float $sdCiUpper,
        public bool $includeInFit,
        public bool $contributesToFit,
        public array $velocities,
    ) {}
}
