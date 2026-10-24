<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Single formatting vocabulary for admin lists and reports.
 */
class Format
{
    /**
     * Narrow no-break space (U+202F) used as the thousands separator.
     * It is visually tighter than a regular space, won't line-wrap mid-number,
     * and gives us a consistent "R1 234.56" across every money cell.
     */
    public const THOUSANDS_SEPARATOR = "\u{202F}";

    public static function money(int|float|string|null $amount, bool $showCents = true): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $value = (float) $amount;

        // number_format turns -0.0 into "-0.00". Clamp near-zero so we never
        // render "-R0.00" on the dashboard.
        if (abs($value) < 0.005) {
            $value = 0.0;
        }

        $formatted = number_format($value, $showCents ? 2 : 0, '.', self::THOUSANDS_SEPARATOR);

        return 'R'.$formatted;
    }

    /**
     * Signed money for delta lines (fees in/out). Near-zero always renders
     * as plain R0.00 — never "-R0.00" or "+R0.00".
     */
    public static function moneyDelta(int|float|string|null $amount, bool $showCents = true): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $value = (float) $amount;

        if (abs($value) < 0.005) {
            return self::money(0.0, $showCents);
        }

        $sign = $value > 0 ? '+' : '-';

        return $sign.self::money(abs($value), $showCents);
    }

    public static function dateShort(CarbonInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }

        return Carbon::parse($date)->timezone('Africa/Johannesburg')->format('d M Y');
    }

    public static function datetimeShort(CarbonInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }

        return Carbon::parse($date)->timezone('Africa/Johannesburg')->format('d M Y, H:i');
    }

    public static function dateRange(
        CarbonInterface|string|null $from,
        CarbonInterface|string|null $to,
    ): string {
        if ($from === null && $to === null) {
            return '—';
        }

        if ($from === null) {
            return '– '.self::dateShort($to);
        }

        if ($to === null) {
            return self::dateShort($from).' –';
        }

        $start = Carbon::parse($from)->timezone('Africa/Johannesburg');
        $end = Carbon::parse($to)->timezone('Africa/Johannesburg');

        if ($start->isSameDay($end)) {
            return $start->format('d M Y');
        }

        if ($start->year === $end->year && $start->month === $end->month) {
            return $start->format('j').' – '.$end->format('j M Y');
        }

        if ($start->year === $end->year) {
            return $start->format('j M').' – '.$end->format('j M Y');
        }

        return $start->format('j M Y').' – '.$end->format('j M Y');
    }

    public static function impacts(int|float|string|null $count): string
    {
        if ($count === null || $count === '') {
            return '—';
        }

        return (string) (int) $count;
    }

    /**
     * Season-standings points to one decimal — the single source of truth
     * for how we render a shooter's season total on the homepage, the
     * public standings pages and the shooter profile.
     *
     * The dot is forced as the decimal separator (never a comma) and the
     * thousands grouping is suppressed so points parse cleanly in Alpine
     * / JS. Null and empty string collapse to the em-dash we use elsewhere.
     *
     *   points(283.456)  → '283.5'
     *   points(283.5)    → '283.5'
     *   points(100)      → '100.0'
     *   points(0)        → '0.0'
     *   points(null)     → '—'
     */
    public static function points(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format((float) $value, 1, '.', '');
    }
}
