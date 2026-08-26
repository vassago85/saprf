<?php

namespace App\Enums;

/**
 * Meeting type mirrors the constitution's regular vs special
 * distinction. Kept minimal on purpose — AGM/SGM/committee-of-the-whole
 * live outside this ExCo workspace.
 */
enum ExcoMeetingType: string
{
    case Regular = 'regular';
    case Special = 'special';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Special => 'Special',
        };
    }
}
