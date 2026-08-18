<?php

namespace App\Enums;

/**
 * Purely presentational — controls the badge colour on lists and the
 * sort weight when unread items are shown in the bell dropdown. It does
 * NOT control whether channels fire (that is `AnnouncementCategory::isMandatory()`).
 */
enum AnnouncementPriority: string
{
    case Normal = 'normal';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::High => 'High',
        };
    }
}
