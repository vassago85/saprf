<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * Where Spatie backup success/failure mail is delivered.
 *
 * The published package default is your@example.com. That address bounces,
 * so a placeholder is never used. A real address is taken from site settings
 * (backup inbox, then owner, then secretary, then the From address). If none
 * of those is a real inbox, backup mail is turned off rather than sent.
 */
class BackupNotificationAddress
{
    public static function isPlaceholder(?string $email): bool
    {
        $email = strtolower(trim((string) $email));

        if ($email === '') {
            return true;
        }

        return str_ends_with($email, '@example.com')
            || str_ends_with($email, '@example.test')
            || str_ends_with($email, '@example.org');
    }

    public static function resolve(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim((string) $candidate));

            if ($candidate === '' || self::isPlaceholder($candidate)) {
                continue;
            }

            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function applyFromSettings(): void
    {
        $configured = null;
        $owner = null;
        $secretary = null;

        try {
            if (Schema::hasTable('settings')) {
                $values = Setting::query()
                    ->whereIn('key', ['backup_notification_email', 'owner_email', 'secretary_email'])
                    ->pluck('value', 'key');
                $configured = $values->get('backup_notification_email');
                $owner = $values->get('owner_email');
                $secretary = $values->get('secretary_email');
            }
        } catch (\Throwable) {
            // Migrations and early boot have no settings table yet.
        }

        $address = self::resolve(
            $configured,
            $owner,
            $secretary,
            (string) config('mail.from.address'),
        );

        if ($address !== null) {
            Config::set('backup.notifications.mail.to', $address);

            return;
        }

        $channels = config('backup.notifications.notifications', []);
        foreach ($channels as $notification => $via) {
            $channels[$notification] = array_values(array_filter(
                (array) $via,
                fn ($channel) => $channel !== 'mail',
            ));
        }
        Config::set('backup.notifications.notifications', $channels);
    }
}
