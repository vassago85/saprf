<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\Standing;
use App\Models\StandingsSnapshot;
use App\Services\StandingsSnapshotRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Walks every finalised match in a season (optionally filtered by series)
 * in match_date order and writes a historical snapshot batch for each.
 * Dry-run by default so you can see the plan before anything is written.
 * Pass --write to actually insert.
 *
 * Refuses to write against a season that already has snapshots unless
 * --write is passed explicitly — guards production against accidental
 * double-backfills that would make the movement arrows all read ▼0.
 *
 * This is the single command Paul should run on production to backfill
 * the 2026 season after the first deploy. Ask-first rule is printed at
 * the end of every dry run.
 */
class BackfillStandingsSnapshotsCommand extends Command
{
    protected $signature = 'standings:backfill-snapshots
                            {season : Season year, e.g. 2026}
                            {--series= : Limit to PRS or PR22 (default: both)}
                            {--write : Actually insert rows. Default is dry-run.}';

    protected $description = 'Backfill standings_snapshots from finalised matches so movement arrows have history to compare against.';

    public function handle(StandingsSnapshotRecorder $recorder): int
    {
        $season = (string) $this->argument('season');
        $seriesFilter = $this->option('series');
        $write = (bool) $this->option('write');

        if ($seriesFilter !== null && ! in_array($seriesFilter, ['PRS', 'PR22'], true)) {
            $this->error("--series must be PRS or PR22 (got '{$seriesFilter}')");
            return self::INVALID;
        }

        $existing = StandingsSnapshot::query()
            ->where('season', $season)
            ->when($seriesFilter, fn ($q) => $q->where('series', $seriesFilter))
            ->count();

        if ($existing > 0 && ! $write) {
            $this->warn("Season {$season} already has {$existing} snapshot row(s). Re-running will add more; dry-run reports what WOULD happen. Pass --write to persist anyway.");
        }

        $matches = MatchEvent::query()
            ->whereNotNull('scores_finalized_at')
            ->where(fn ($q) => $q->where('season', $season)->orWhereYear('match_date', $season))
            ->when($seriesFilter, fn ($q) => $q->where('series', $seriesFilter))
            ->orderBy('match_date')
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            $this->info("No finalised matches found for season {$season}".($seriesFilter ? " and series {$seriesFilter}" : '').'.');
            return self::SUCCESS;
        }

        $this->info(($write ? 'WRITING' : 'DRY RUN').' — '.$matches->count().' finalised match(es) in '.$season.($seriesFilter ? ' ('.$seriesFilter.')' : ''));

        $planned = 0;
        foreach ($matches as $match) {
            // Count the standings rows that WOULD be snapshotted — same
            // scope the recorder uses (series + season).
            $would = Standing::query()
                ->where('season', $match->season ?: $season)
                ->where('series', $match->series)
                ->count();

            $taken = $match->scores_finalized_at
                ? Carbon::parse($match->scores_finalized_at)->format('Y-m-d H:i')
                : 'now';
            $this->line(sprintf(
                '  %-10s %-5s %-18s %s match=%d rows=%d',
                $match->match_date?->format('Y-m-d') ?? '—',
                $match->series,
                '('.$taken.')',
                str_pad(substr((string) $match->name, 0, 40), 42),
                $match->id,
                $would,
            ));

            $planned += $would;

            if ($write) {
                $recorder->recordForMatch($match);
            }
        }

        $this->newLine();
        $this->info(($write ? 'Wrote' : 'Would write').' '.$planned.' snapshot row(s) across '.$matches->count().' match(es).');

        if (! $write) {
            $this->warn('Dry run — no rows written. Pass --write to persist. On production, ask Paul before running against the live DB.');
        }

        return self::SUCCESS;
    }
}
