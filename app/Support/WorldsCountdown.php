<?php

namespace App\Support;

use App\Services\SettingsService;
use Illuminate\Support\Carbon;

/**
 * Target instant for the public landing-page countdown.
 *
 * The poster only names August 2028. Until Site Settings stores a
 * different start, the homepage says the final dates are still to be
 * announced and does not tick toward a made-up day.
 */
class WorldsCountdown
{
    public const SETTING_KEY = 'worlds_countdown_at';

    public const TIMEZONE = 'Africa/Johannesburg';

    /**
     * Placeholder written when the settings form was saved before a real
     * start existed. Treated the same as a blank value.
     */
    public const DEFAULT = '2028-08-01 00:00:00';

    public function __construct(private readonly SettingsService $settings) {}

    public static function make(): self
    {
        return app(self::class);
    }

    public function isAnnounced(): bool
    {
        $raw = $this->stored();

        return $raw !== '' && $raw !== self::DEFAULT;
    }

    public function target(): Carbon
    {
        $raw = $this->stored();
        $value = $raw !== '' ? $raw : self::DEFAULT;

        return Carbon::parse($value, self::TIMEZONE);
    }

    public function hasPassed(?Carbon $now = null): bool
    {
        if (! $this->isAnnounced()) {
            return false;
        }

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
     * Value for a datetime-local input. Blank until a real start is saved.
     */
    public function inputValue(): string
    {
        if (! $this->isAnnounced()) {
            return '';
        }

        return $this->target()->format('Y-m-d\TH:i');
    }

    private function stored(): string
    {
        $raw = $this->settings->get(self::SETTING_KEY);

        return is_string($raw) ? trim($raw) : '';
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
