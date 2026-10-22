<?php

namespace App\Support;

use App\Services\SettingsService;
use Illuminate\Support\Carbon;

/**
 * Target instant for the public landing-page countdown.
 *
 * Until Site Settings stores an exact start, the page counts down to
 * 1 August 2028 (the month on the Worlds poster) at midnight SAST.
 */
class WorldsCountdown
{
    public const SETTING_KEY = 'worlds_countdown_at';

    public const TIMEZONE = 'Africa/Johannesburg';

    public const DEFAULT = '2028-08-01 00:00:00';

    public function __construct(private readonly SettingsService $settings) {}

    public static function make(): self
    {
        return app(self::class);
    }

    public function target(): Carbon
    {
        $raw = $this->settings->get(self::SETTING_KEY);
        $value = is_string($raw) && trim($raw) !== '' ? trim($raw) : self::DEFAULT;

        return Carbon::parse($value, self::TIMEZONE);
    }

    public function hasPassed(?Carbon $now = null): bool
    {
        return ($now ?? Carbon::now(self::TIMEZONE))->greaterThanOrEqualTo($this->target());
    }

    /**
     * ISO-8601 with the SAST offset, for the browser countdown.
     */
    public function iso(): string
    {
        return $this->target()->format('Y-m-d\TH:i:sP');
    }

    /**
     * Value for a datetime-local input.
     */
    public function inputValue(): string
    {
        return $this->target()->format('Y-m-d\TH:i');
    }

    public function label(): string
    {
        $target = $this->target();
        $date = $target->format('j F Y');

        if ($target->format('H:i') === '00:00') {
            return $date;
        }

        return $date.', '.$target->format('H:i');
    }

    public static function normalize(?string $raw): string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return '';
        }

        return Carbon::parse($raw, self::TIMEZONE)->format('Y-m-d H:i:s');
    }
}
