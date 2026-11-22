<?php

namespace App\Services;

use App\Models\MatchEvent;
use InvalidArgumentException;

/**
 * Take an unfinished championship off the year log.
 *
 * The score rows stay, so day 2 can keep running. Marking the match
 * complete again publishes them. A day-1 provincial sibling is left
 * alone — those scores already counted.
 */
class ChampionshipLogHold
{
    public function __construct(
        private readonly ScoreValidationService $validation,
        private readonly StandingsCalculationService $standings,
    ) {}

    /**
     * @return array{match_id:int, name:string, held:int}
     */
    public function hold(MatchEvent $match): array
    {
        if ($match->series_level !== 'final') {
            throw new InvalidArgumentException('Only a championship match can be held. Day 1 provincial scores stay on the log.');
        }

        $match->forceFill([
            'scores_finalized_at' => null,
            'scores_finalized_by' => null,
        ])->save();

        $held = 0;
        foreach ($match->scores()->get() as $score) {
            $score->forceFill([
                'counts_for_season' => false,
                'counts_for_log' => false,
            ])->save();
            $this->validation->syncOfficialLog($score);
            $held++;
        }

        $this->standings->recalculateForMatch($match->fresh());

        return [
            'match_id' => $match->id,
            'name' => $match->name,
            'held' => $held,
        ];
    }
}
