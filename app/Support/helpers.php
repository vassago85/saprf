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
     * Season-standings points to one decimal. The one and only entry point —
     * every homepage widget, standings page and shooter-profile card runs
     * through here so 283.5 can never render as 283 or 283.50 somewhere.
     */
    function points(int|float|string|null $value): string
    {
        return Format::points($value);
    }
}
