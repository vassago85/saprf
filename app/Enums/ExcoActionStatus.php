<?php

namespace App\Enums;

enum ExcoActionStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Open => 'bg-amber-100 text-amber-800',
            self::Done => 'bg-emerald-100 text-emerald-800',
            self::Cancelled => 'bg-stone-100 text-stone-600',
        };
    }
}
