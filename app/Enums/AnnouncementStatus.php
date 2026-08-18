<?php

namespace App\Enums;

/**
 * Lifecycle of a federation announcement:
 *
 *   draft      → composer is still editing (may sit indefinitely)
 *   scheduled  → send_at is in the future; DispatchScheduledAnnouncementsJob will pick it up
 *   sending    → ResolveAudienceJob started; recipients are being frozen / fanned out
 *   sent       → every per-recipient chunk has been queued; individual delivery rows track per-channel state
 *   cancelled  → author or approver killed it before send
 */
enum AnnouncementStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft, self::Scheduled => true,
            default => false,
        };
    }
}
