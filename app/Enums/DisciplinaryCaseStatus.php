<?php

namespace App\Enums;

/**
 * Lightweight status track for the ExCo case register — this is not
 * the Judicial Code committee-formation / notice / hearing / appeal
 * pipeline. Codes align with the informal stages ExCo already uses
 * when tabling matters at a sitting.
 */
enum DisciplinaryCaseStatus: string
{
    case Reported = 'reported';
    case UnderReview = 'under_review';
    case Hearing = 'hearing';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Reported => 'Reported',
            self::UnderReview => 'Under review',
            self::Hearing => 'Hearing',
            self::Closed => 'Closed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Reported => 'bg-sky-100 text-sky-800',
            self::UnderReview => 'bg-amber-100 text-amber-800',
            self::Hearing => 'bg-red-100 text-red-800',
            self::Closed => 'bg-stone-100 text-stone-700',
        };
    }
}
