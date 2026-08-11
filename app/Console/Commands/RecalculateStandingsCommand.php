<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\Province;
use App\Services\StandingsCalculationService;
use Illuminate\Console\Command;

/**
 * Rebuild season standings (national + every province) from the scores that are
 * ALREADY persisted, without re-running membership validation or re-ranking.
 *
 * Use this after a scoring-rule change (e.g. adjusting a qualification pool)
 * when you want the standings recomputed but must NOT disturb per-score status.
 * Unlike scores:reevaluate, this never calls the validation service, so forced
 * or manually-set score statuses are preserved.
 */
class RecalculateStandingsCommand extends Command
{
    protected $signature = 'saprf:recalc-standings
        {--series= : Limit to a single series (e.g. PR22, PRS)}
        {--season= : Limit to a single season (e.g. 2026)}';

    protected $description = 'Recalculate season standings (national + all provinces) without re-validating scores';

    public function handle(StandingsCalculationService $standings): int
    {
        $query = MatchEvent::query()
            ->whereNotNull('series')
            ->whereNotNull('season')
            ->select('series', 'season')
            ->distinct();

        if ($series = $this->option('series')) {
            $query->where('series', $series);
        }
        if ($season = $this->option('season')) {
            $query->where('season', $season);
        }

        $combos = $query->get();
        if ($combos->isEmpty()) {
            $this->warn('No matching series/season combinations found.');

            return self::SUCCESS;
        }

        $provinceIds = Province::query()->pluck('id');

        foreach ($combos as $combo) {
            $standings->recalculateSeasonStandings($combo->series, $combo->season, null);
            foreach ($provinceIds as $provinceId) {
                $standings->recalculateSeasonStandings($combo->series, $combo->season, (int) $provinceId);
            }
            $this->line("Recalculated {$combo->series} {$combo->season} (national + {$provinceIds->count()} province table(s)).");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
