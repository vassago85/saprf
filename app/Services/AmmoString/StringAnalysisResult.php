<?php

namespace App\Services\AmmoString;

use App\Services\AmmoString\DTO\StringFinding;
use App\Services\AmmoString\DTO\StringTrendFit;

/**
 * Top-level result of {@see StringAnalysis::analyze()}. Everything the UI
 * displays reads off this DTO — no downstream statistics live in the views.
 */
final readonly class StringAnalysisResult
{
    /**
     * @param  list<array{sequence: int, velocity: float, excluded: bool, residualFromMean: ?float, residualFromTrend: ?float}>  $shots
     * @param  list<StringFinding>  $findings
     */
    public function __construct(
        public int $n,
        public int $totalShots,
        public ?float $mean,
        public ?float $sd,
        public ?int $sdDf,
        public ?float $sdCiLower,
        public ?float $sdCiUpper,
        public ?float $es,
        public ?float $min,
        public ?float $max,
        public ?int $hiShot,
        public ?int $loShot,
        public array $shots,
        public ?StringTrendFit $trend,
        public ?bool $coldBoreOutlier,
        public ?float $coldBoreDelta,
        public array $findings,
    ) {}
}
