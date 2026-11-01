<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Reports every (match_id, user_id) group on the `scores` table that has
 * more than one row — the condition the Phase 1.1 unique constraint will
 * forbid. The command is strictly read-only: it never writes, so it is
 * safe to run on production at any time.
 *
 * The non-zero exit code when duplicates are present makes this suitable
 * to wire into a scheduled job later (`fail-on-dup` is implicit).
 */
class FindDuplicateScoresCommand extends Command
{
    protected $signature = 'scores:find-duplicates
        {--csv : Also write a CSV report to storage/app/reports/}';

    protected $description = 'Report (match_id, user_id) groups with more than one score row. Read-only.';

    public function handle(): int
    {
        $this->info('=== scores:find-duplicates ===');
        $this->line('Scanning for shooters with more than one score on the same match…');
        $this->newLine();

        $groups = DB::table('scores')
            ->selectRaw('match_id, user_id, COUNT(*) AS n')
            ->whereNotNull('user_id')
            ->groupBy('match_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('match_id')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicate (match_id, user_id) groups found.');

            return self::SUCCESS;
        }

        $this->warn($groups->count().' duplicate group(s) found.');
        $this->newLine();

        $rows = [];

        foreach ($groups as $group) {
            $match = DB::table('matches')
                ->where('id', $group->match_id)
                ->first(['id', 'name', 'match_date']);

            $user = DB::table('users')
                ->where('id', $group->user_id)
                ->first(['id', 'name']);

            $scoreRows = DB::table('scores')
                ->where('match_id', $group->match_id)
                ->where('user_id', $group->user_id)
                ->orderBy('id')
                ->get(['id', 'placement', 'raw_score', 'score_import_id', 'status', 'created_at']);

            $header = sprintf(
                'Match #%d — %s (%s) · User #%d %s · %d rows',
                $match?->id ?? 0,
                $match?->name ?? '(deleted)',
                $match?->match_date ? Carbon::parse($match->match_date)->toDateString() : '-',
                $user?->id ?? 0,
                $user?->name ?? '(deleted)',
                $group->n,
            );

            $this->line($header);
            foreach ($scoreRows as $row) {
                $this->line(sprintf(
                    '  score #%d  place=%s  raw=%s  import=%s  status=%s  created=%s',
                    $row->id,
                    $row->placement ?? '-',
                    $row->raw_score ?? '-',
                    $row->score_import_id ?? '-',
                    $row->status ?? '-',
                    $row->created_at ?? '-',
                ));

                $rows[] = [
                    'match_id' => $match?->id,
                    'match_name' => $match?->name,
                    'match_date' => $match?->match_date,
                    'user_id' => $user?->id,
                    'user_name' => $user?->name,
                    'score_id' => $row->id,
                    'placement' => $row->placement,
                    'raw_score' => $row->raw_score,
                    'score_import_id' => $row->score_import_id,
                    'status' => $row->status,
                    'created_at' => $row->created_at,
                ];
            }
            $this->newLine();
        }

        if ($this->option('csv')) {
            $this->writeCsv($rows);
        }

        // Non-zero so CI / scheduler can fail loudly if drift reappears.
        return self::FAILURE;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeCsv(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $filename = 'reports/duplicate-scores-'.now()->format('Ymd-His').'.csv';
        Storage::disk('local')->makeDirectory('reports');

        $csv = fopen('php://temp', 'r+');
        if ($csv === false) {
            $this->error('Could not open temp stream for CSV output.');

            return;
        }

        fputcsv($csv, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($csv, $row);
        }

        rewind($csv);
        $contents = stream_get_contents($csv);
        fclose($csv);

        Storage::disk('local')->put($filename, (string) $contents);
        $this->line('CSV written: '.Storage::disk('local')->path($filename));
    }
}
