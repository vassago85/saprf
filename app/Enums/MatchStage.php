<?php

namespace App\Enums;

/**
 * Lifecycle stage of a match, derived from existing columns — no new
 * status is persisted. Powers the stepper, next-action banner and tab
 * availability on the match workspace.
 *
 * Resolution lives on {@see \App\Models\MatchEvent::stageContext()}.
 * The order here is the display order of the stepper.
 */
enum MatchStage: string
{
    case Draft = 'draft';
    case EntriesOpen = 'entries_open';
    case EntriesClosed = 'entries_closed';
    case Scores = 'scores';
    case Results = 'results';
    case Payout = 'payout';

    /**
     * @return list<self>
     */
    public static function orderedSteps(): array
    {
        return [
            self::Draft,
            self::EntriesOpen,
            self::EntriesClosed,
            self::Scores,
            self::Results,
            self::Payout,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::EntriesOpen => 'Entries open',
            self::EntriesClosed => 'Entries closed',
            self::Scores => 'Scores',
            self::Results => 'Results',
            self::Payout => 'Payout',
        };
    }

    public function shortDescription(): string
    {
        return match ($this) {
            self::Draft => 'Set up',
            self::EntriesOpen => 'Taking entries',
            self::EntriesClosed => 'Entries closed',
            self::Scores => 'Importing scores',
            self::Results => 'Results published',
            self::Payout => 'Payout',
        };
    }

    public function order(): int
    {
        return array_search($this, self::orderedSteps(), true);
    }

    public function isBefore(self $other): bool
    {
        return $this->order() < $other->order();
    }

    public function isAtLeast(self $other): bool
    {
        return $this->order() >= $other->order();
    }
}
