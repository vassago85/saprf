<?php

use App\Support\Format;
use Carbon\CarbonInterface;

if (! function_exists('money')) {
    function money(int|float|string|null $amount, bool $showCents = true): string
    {
        return Format::money($amount, $showCents);
    }
}

if (! function_exists('money_delta')) {
    function money_delta(int|float|string|null $amount, bool $showCents = true): string
    {
        return Format::moneyDelta($amount, $showCents);
    }
}

if (! function_exists('date_short')) {
    function date_short(CarbonInterface|string|null $date): string
    {
        return Format::dateShort($date);
    }
}

if (! function_exists('datetime_short')) {
    function datetime_short(CarbonInterface|string|null $date): string
    {
        return Format::datetimeShort($date);
    }
}

if (! function_exists('date_range')) {
    function date_range(
        CarbonInterface|string|null $from,
        CarbonInterface|string|null $to,
    ): string {
        return Format::dateRange($from, $to);
    }
}

if (! function_exists('impacts')) {
    function impacts(int|float|string|null $count): string
    {
        return Format::impacts($count);
    }
}

if (! function_exists('points')) {
    /**
     * Season-standings points. Defaults to one decimal. Pass 4 for a row
     * the board has flagged because one decimal would hide a real split.
     */
    function points(int|float|string|null $value, int $decimals = 1): string
    {
        return Format::points($value, $decimals);
    }
}
