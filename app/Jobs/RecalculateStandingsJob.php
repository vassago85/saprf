<?php

namespace App\Jobs;

use App\Models\MatchEvent;
use App\Services\StandingsCalculationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateStandingsJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(
        public int $matchId,
    ) {}

    public function handle(StandingsCalculationService $standingsCalculationService): void
    {
        $match = MatchEvent::query()->find($this->matchId);

        if ($match === null) {
            return;
        }

        $standingsCalculationService->recalculateForMatch($match);
    }
}
