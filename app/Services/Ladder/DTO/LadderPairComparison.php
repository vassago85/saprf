<?php

namespace App\Services\Ladder\DTO;

use App\Enums\PairSeparation;

/**
 * Welch's-t comparison of two adjacent steps' means. Only produced for
 * consecutive pairs where both steps have n≥2.
 */
final readonly class LadderPairComparison
{
    public function __construct(
        public int $fromStepId,
        public int $toStepId,
        public float $fromValue,
        public float $toValue,
        public float $d,
        public float $seD,
        public float $t,
        public float $df,
        public float $p,
        public PairSeparation $classification,
        public float $stepSlope,
        public bool $exceedsFittedSlope,
    ) {}
}
