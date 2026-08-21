<?php

namespace App\Services\Ladder\DTO;

/**
 * Comparison of the lowest- vs highest-SD steps' chi-square intervals.
 *
 * If the intervals overlap, there is no evidence one step is more consistent
 * than the other — the spec calls this out as the single most useful thing
 * the tool can report, and copy must not soften it.
 */
final readonly class LadderSdComparison
{
    public function __construct(
        public int $lowestStepId,
        public int $highestStepId,
        public float $lowestSd,
        public float $highestSd,
        public float $lowestCiLower,
        public float $lowestCiUpper,
        public float $highestCiLower,
        public float $highestCiUpper,
        public bool $intervalsOverlap,
        public string $text,
    ) {}
}
