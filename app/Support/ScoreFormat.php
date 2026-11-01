<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Display formatting for raw match scores.
 *
 * PRS and PR22 are both impact-count disciplines — a shooter either hits a
 * plate or does not. The `raw_score` column is `decimal(10,3)` for other
 * precision disciplines, but for PRS / PR22 the fractional part is always
 * .000, so showing "112.0" instead of "112" is pure noise. Other match
 * types (long-range precision, bench) might legitimately score to one
 * decimal, so they stay at `number_format($score, 1)`.
 */
final class ScoreFormat
{
    private function __construct() {}

    /**
     * Format a raw score for display, keyed off the match type / series.
     * `$matchType` and `$series` are tried in that order — some older
     * fixtures only set `match_type`, newer ones also carry `series`.
     */
    public static function format(float|int|string|null $score, ?string $matchType = null, ?string $series = null): string
    {
        if ($score === null || $score === '') {
            return '—';
        }

        $numeric = (float) $score;

        if (self::isIntegerDiscipline($matchType) || self::isIntegerDiscipline($series)) {
            return (string) (int) round($numeric);
        }

        return number_format($numeric, 1);
    }

    private static function isIntegerDiscipline(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return in_array(strtoupper(trim($value)), ['PRS', 'PR22'], true);
    }
}
