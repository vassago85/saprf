<?php

namespace App\Enums;

/**
 * Composition mode for a single audience rule.
 *
 * The resolver walks every rule twice: first unions everything in
 * `include` mode, then subtracts everything in `exclude` mode. So
 * "All active members, EXCLUDING Exco" is one active_members/include
 * rule plus one role/exclude rule.
 *
 * Empty include set = zero recipients. There is no implicit
 * "everyone" fallback — the composer must always pick something.
 */
enum AudienceMode: string
{
    case Include = 'include';
    case Exclude = 'exclude';

    public function label(): string
    {
        return match ($this) {
            self::Include => 'Include',
            self::Exclude => 'Exclude',
        };
    }
}
