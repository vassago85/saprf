<?php

namespace App\Enums;

enum DecisionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-stone-100 text-stone-700',
            self::Published => 'bg-emerald-50 text-emerald-800',
        };
    }
}
