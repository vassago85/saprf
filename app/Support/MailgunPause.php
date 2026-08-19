<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Mailgun probation (100/hour) temporarily disables the domain.
 * Each retry while disabled can restart the lock, so after a 403/420
 * we hide Resend until the unlock time Mailgun printed.
 */
class MailgunPause
{
    public const CACHE_KEY = 'mailgun.paused_until';

    public function pausedUntil(): ?Carbon
    {
        $timestamp = Cache::get(self::CACHE_KEY);

        if (! is_numeric($timestamp)) {
            return null;
        }

        $until = Carbon::createFromTimestamp((int) $timestamp);

        if ($until->isPast()) {
            Cache::forget(self::CACHE_KEY);

            return null;
        }

        return $until;
    }

    public function isPaused(): bool
    {
        return $this->pausedUntil() !== null;
    }

    public function rememberFromError(string $message): void
    {
        if (preg_match('/enabled in (\d+) seconds/i', $message, $match) === 1) {
            $seconds = max(60, (int) $match[1]);
            Cache::put(self::CACHE_KEY, now()->addSeconds($seconds)->timestamp, $seconds + 120);

            return;
        }

        if (str_contains($message, 'probation') || str_contains($message, 'code 403') || str_contains($message, 'code 420')) {
            Cache::put(self::CACHE_KEY, now()->addHour()->timestamp, 3720);
        }
    }

    public function assertAvailable(): void
    {
        $until = $this->pausedUntil();

        if ($until === null) {
            return;
        }

        throw new RuntimeException(
            'Mailgun is paused until '.$until->timezone('Africa/Johannesburg')->format('H:i').
            ' SAST. Do not retry — each retry restarts the hour lock.'
        );
    }
}
