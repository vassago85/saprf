<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Single formatting vocabulary for admin lists and reports.
 */
class Format
{
    public static function money(int|float|string|null $amount, bool $showCents = true): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $value = (float) $amount;
        $formatted = number_format($value, $showCents ? 2 : 0, '.', ' ');

        return 'R'.$formatted;
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
}
