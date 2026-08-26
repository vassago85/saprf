<?php

namespace App\Enums;

/**
 * `confidential` items may hold personal information (typically the
 * agenda line tied to a disciplinary matter) that must not leak into
 * any future member-facing minutes export. The flag is stored now so
 * that when/if we add a members-only "sanitised minutes" view we can
 * filter without a schema change.
 */
enum ExcoAgendaItemVisibility: string
{
    case Ordinary = 'ordinary';
    case Confidential = 'confidential';

    public function label(): string
    {
        return match ($this) {
            self::Ordinary => 'Ordinary',
            self::Confidential => 'Confidential',
        };
    }
}
