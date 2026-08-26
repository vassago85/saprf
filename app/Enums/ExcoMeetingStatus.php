<?php

namespace App\Enums;

/**
 * Lifecycle of an ExCo meeting:
 *
 *   draft  -> building the agenda before the sitting
 *   held   -> the meeting took place; minutes are being captured
 *   closed -> finalised; nothing further to record
 *
 * Draft is the only status where the meeting card offers a Delete
 * option — once minutes exist we keep the record for audit.
 */
enum ExcoMeetingStatus: string
{
    case Draft = 'draft';
    case Held = 'held';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Held => 'In progress',
            self::Closed => 'Closed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-stone-100 text-stone-700',
            self::Held => 'bg-amber-100 text-amber-800',
            self::Closed => 'bg-emerald-100 text-emerald-800',
        };
    }
}
