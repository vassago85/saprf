<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Services\ChampionshipLogHold;
use Illuminate\Console\Command;
use InvalidArgumentException;

class HoldChampionshipCommand extends Command
{
    protected $signature = 'scores:hold-championship
        {match? : Championship match id. Omit when this series and season have one final.}
        {--series=PRS : Series used when no match id is given}
        {--season=2026 : Season used when no match id is given}';

    protected $description = 'Take an unfinished championship off the year log without deleting its scores';

    public function handle(ChampionshipLogHold $hold): int
    {
        $match = $this->resolveMatch();
        if ($match === null) {
            return self::FAILURE;
        }

        try {
            $result = $hold->hold($match);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Match #{$result['match_id']} {$result['name']}");
        $this->info("Held {$result['held']} score(s) off the year log. Score rows are still on the match.");
        $this->line('Mark the match complete after day 2 and the championship counts again.');

        return self::SUCCESS;
    }

    private function resolveMatch(): ?MatchEvent
    {
        $id = $this->argument('match');
        if ($id !== null && $id !== '') {
            $match = MatchEvent::query()->find((int) $id);
            if ($match === null) {
                $this->error('Match not found.');
            }

            return $match;
        }

        $series = strtoupper((string) $this->option('series'));
        $season = (string) $this->option('season');
        $matches = MatchEvent::query()
            ->where('series', $series)
            ->where('season', $season)
            ->where('series_level', 'final')
            ->orderBy('id')
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->isEmpty()) {
            $this->error("No {$series} {$season} championship match found.");

            return null;
        }

        $this->error('More than one championship match. Pass the match id.');
        foreach ($matches as $match) {
            $this->line("{$match->id}  {$match->name}");
        }

        return null;
    }
}
