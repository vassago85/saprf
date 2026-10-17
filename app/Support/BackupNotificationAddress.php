<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;

/**
 * Where Spatie backup failure mail is delivered.
 *
 * Successful backups never mail. The published package default is
 * your@example.com; that address bounces, so a placeholder is never used.
 * Preference order: site setting, BACKUP_NOTIFICATION_EMAIL, then
 * paul@charsley.co.za. Owner/secretary federation inboxes are not used —
 * several of those addresses bounce.
 */
class BackupNotificationAddress
{
    public const DEFAULT_ADDRESS = 'paul@charsley.co.za';

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
        self::limitMailToFailuresOnly();

        $configured = null;

        try {
            if (Schema::hasTable('settings')) {
                $configured = Setting::query()
                    ->where('key', 'backup_notification_email')
                    ->value('value');
            }
        } catch (\Throwable) {
            // Migrations and early boot have no settings table yet.
        }

        $address = self::resolve(
            $configured,
            env('BACKUP_NOTIFICATION_EMAIL'),
            self::DEFAULT_ADDRESS,
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

    private static function limitMailToFailuresOnly(): void
    {
        $channels = config('backup.notifications.notifications', []);

        foreach ($channels as $notification => $via) {
            if ($notification === BackupHasFailedNotification::class) {
                $channels[$notification] = ['mail'];
                continue;
            }

            $channels[$notification] = array_values(array_filter(
                (array) $via,
                fn ($channel) => $channel !== 'mail',
            ));
        }

        Config::set('backup.notifications.notifications', $channels);
    }
}
