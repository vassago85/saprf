<?php

namespace App\Enums;

/**
 * Classification returned by the adjacent Welch's t-test between two steps.
 *
 * The cutoffs are:
 *   - Separates:        p < 0.05
 *   - Marginal:         0.05 ≤ p < 0.15
 *   - Indistinguishable: p ≥ 0.15
 *
 * "Marginal" is deliberately narrow — most of the ladder should live in
 * "indistinguishable", and the tool's job is to keep it that way when the
 * evidence does not support anything stronger.
 */
enum PairSeparation: string
{
    case Separates = 'separates';
    case Marginal = 'marginal';
    case Indistinguishable = 'indistinguishable';

    public function label(): string
    {
        return match ($this) {
            self::Separates => 'Separates',
            self::Marginal => 'Marginal',
            self::Indistinguishable => 'Indistinguishable',
        };
    }
}
