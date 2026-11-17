<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\User;
use App\Services\ImpactScoring\Day1ProvincialScoreAligner;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AlignDay1ProvincialScoresCommand extends Command
{
    protected $signature = 'scores:align-day1-provincial
        {match : Day-1 provincial match id, or the 2-day national it belongs to}';

    protected $description = 'Point a day-1 provincial\'s scores at the division on the national entry';

    public function handle(Day1ProvincialScoreAligner $aligner): int
    {
        $match = MatchEvent::query()->find((int) $this->argument('match'));
        if ($match === null) {
            $this->error('Match not found.');

            return self::FAILURE;
        }

        if ($match->isTwoDayNational()) {
            $sibling = $match->provincialDay1Match;
            if ($sibling === null) {
                $this->error('That national has no day-1 provincial yet.');

                return self::FAILURE;
            }
            $match = $sibling;
        }

        $actor = User::query()->find($match->paidEntryMatch()->created_by)
            ?? User::query()->find($match->created_by);
        if ($actor === null) {
            $this->error('No user on the match to record the link against.');

            return self::FAILURE;
        }

        try {
            $result = $aligner->align($match, $actor);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Match #{$match->id} {$match->name}");
        $this->info("Divisions updated from the national entry: {$result['divisions']}");
        $this->info("Scoreboard names linked to a national entry: {$result['linked']}");
        if ($result['unmatched'] !== []) {
            $this->warn('Still unlinked (no exact name on the national entry list): '.implode(', ', $result['unmatched']));
        }

        return self::SUCCESS;
    }
}
