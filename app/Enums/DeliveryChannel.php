<?php

namespace App\Enums;

/**
 * Per-recipient delivery channels tracked by `announcement_deliveries`.
 *
 *   database → always attempted, even when the global notifications kill
 *              switch is off (the /communications archive must stay honest)
 *   mail     → honours `notifications_enabled` + member mute prefs,
 *              throttled via the shared `RateLimited('mail')` middleware
 *   webpush  → honours mute prefs; only enqueued if the recipient has at
 *              least one active `push_subscriptions` row
 */
enum DeliveryChannel: string
{
    case Database = 'database';
    case Mail = 'mail';
    case WebPush = 'webpush';

    public function label(): string
    {
        return match ($this) {
            self::Database => 'In-app',
            self::Mail => 'Email',
            self::WebPush => 'Web push',
        };
    }
}
