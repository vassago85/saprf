<?php

use App\Support\Format;
use Carbon\Carbon;

it('formats money with a thin space thousands separator', function () {
    expect(Format::money(850))->toBe('R850.00')
        ->and(Format::money(24255, false))->toBe('R24 255')
        ->and(money(null))->toBe('—');
});

it('formats short dates in Johannesburg time', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 15:41:00', 'UTC'));

    expect(date_short('2026-11-21'))->toBe('21 Nov 2026')
        ->and(datetime_short(Carbon::parse('2026-10-16 15:41:00', 'UTC')))->toBe('16 Oct 2026, 17:41')
        ->and(date_range('2026-01-01', '2026-12-31'))->toBe('1 Jan – 31 Dec 2026')
        ->and(impacts(87))->toBe('87');
});
