<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfills `scores.status` from NULL -> 'pending'. The create-scores
 * migration set a default of 'pending' and the importers all pass the
 * literal 'pending', but historical rows (seeded before the default was
 * added, or explicitly INSERTed with NULL) still slip through.
 *
 * Default behaviour is report-only. Pass `--apply` to actually write.
 */
class BackfillPendingScoreStatusCommand extends Command
{
    protected $signature = 'scores:backfill-pending-status
        {--apply : Write the backfill. Without this flag, the command only reports.}';

    protected $description = "Set scores.status = 'pending' for any row where it is NULL. Reports counts first.";

    public function handle(): int
    {
        $this->info('=== scores:backfill-pending-status ===');

        $nullCount = DB::table('scores')->whereNull('status')->count();
        $totalCount = DB::table('scores')->count();

        $this->line("Total scores:        {$totalCount}");
        $this->line("Scores with NULL:    {$nullCount}");
        $this->newLine();

        if ($nullCount === 0) {
            $this->info('Nothing to backfill. All scores already have a status.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->warn("Dry run — pass --apply to update {$nullCount} row(s) to 'pending'.");

            return self::SUCCESS;
        }

        $updated = DB::table('scores')
            ->whereNull('status')
            ->update(['status' => 'pending']);

        $this->info("Updated {$updated} row(s) to status = 'pending'.");

        return self::SUCCESS;
    }
}
