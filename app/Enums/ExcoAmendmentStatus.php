<?php

namespace App\Enums;

/**
 * Lifecycle of a proposed amendment to circulated minutes:
 *
 *   pending  -> submitted by an ExCo member, awaiting chair review
 *   accepted -> chair applied the change (minutes edited during
 *               the review window)
 *   rejected -> chair declined the change with a note explaining why
 *
 * Amendments are only submittable during the review window
 * (circulated → not yet adopted). Once the meeting is adopted the
 * record is frozen.
 */
enum ExcoAmendmentStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-100 text-amber-800',
            self::Accepted => 'bg-emerald-100 text-emerald-800',
            self::Rejected => 'bg-stone-100 text-stone-600',
        };
    }
}
