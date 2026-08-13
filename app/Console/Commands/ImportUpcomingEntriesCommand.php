<?php

namespace App\Console\Commands;

use App\Services\UpcomingEntriesImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Imports the old precisionrifle.co.za "upcoming matches & entries" export
 * (database/data/upcoming_entries_2026.json) into this platform: sets match
 * fees, assigns match directors, and creates confirmed+paid registrations.
 *
 * Dry-run by default — nothing is written until you pass --commit.
 */
class ImportUpcomingEntriesCommand extends Command
{
    protected $signature = 'saprf:import-upcoming-entries
        {--commit : Actually write changes (default is a dry run that rolls back)}
        {--only= : Comma list of phases to run: fees,md,registrations (default: all)}
        {--dataset= : Path to the dataset JSON (default: database/data/upcoming_entries_2026.json)}
        {--overrides= : Path to an overrides JSON (default: database/data/upcoming_entries_overrides.json if present)}';

    protected $description = 'Import old-site upcoming match fees, directors and registrations';

    public function handle(UpcomingEntriesImporter $importer): int
    {
        $datasetPath = $this->option('dataset') ?: base_path('database/data/upcoming_entries_2026.json');
        if (! is_file($datasetPath)) {
            $this->error("Dataset not found: {$datasetPath}");

            return self::FAILURE;
        }

        $dataset = json_decode((string) file_get_contents($datasetPath), true);
        if (! is_array($dataset)) {
            $this->error('Dataset JSON could not be decoded.');

            return self::FAILURE;
        }

        $overridesPath = $this->option('overrides') ?: base_path('database/data/upcoming_entries_overrides.json');
        $overrides = ['matches' => [], 'directors' => []];
        if (is_file($overridesPath)) {
            $decoded = json_decode((string) file_get_contents($overridesPath), true);
            if (is_array($decoded)) {
                $overrides = array_merge($overrides, $decoded);
                $this->line("Overrides:   {$overridesPath}");
            }
        }

        $phases = $this->resolvePhases();
        $commit = (bool) $this->option('commit');

        $this->info('=== saprf:import-upcoming-entries ===');
        $this->line("Dataset:     {$datasetPath}");
        $this->line('Mode:        '.($commit ? 'COMMIT (writing)' : 'DRY RUN (no changes saved)'));
        $this->line('Phases:      '.implode(', ', array_keys(array_filter($phases))));
        $this->line('Matches:     '.count($dataset['matches'] ?? []).', entrants: '.count($dataset['entrants'] ?? []));
        $this->newLine();

        DB::beginTransaction();
        try {
            $report = $importer->run($dataset, $overrides, $phases);

            if ($commit) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Import aborted: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        $this->renderReport($report, $phases);
        $this->writeReportFile($report);

        if (! $commit) {
            $this->newLine();
            $this->comment('DRY RUN — no changes were written. Re-run with --commit to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{fees:bool, md:bool, registrations:bool}
     */
    private function resolvePhases(): array
    {
        $only = trim((string) $this->option('only'));
        if ($only === '') {
            return ['fees' => true, 'md' => true, 'registrations' => true];
        }

        $wanted = array_map('trim', explode(',', strtolower($only)));

        return [
            'fees' => in_array('fees', $wanted, true),
            'md' => in_array('md', $wanted, true),
            'registrations' => in_array('registrations', $wanted, true),
        ];
    }

    private function renderReport(array $report, array $phases): void
    {
        $this->info('--- Match resolution ---');
        $matched = 0;
        foreach ($report['matches'] as $m) {
            if ($m['status'] === 'matched') {
                $matched++;
                $this->line(sprintf('  #%d -> match %d  [%s]  %s', $m['old_event_id'], $m['match_id'], $m['note'], $m['platform_name']));
            } else {
                $this->warn(sprintf('  #%d  UNMATCHED  (%s)  "%s"', $m['old_event_id'], $m['note'], $m['sheet_name']));
            }
        }
        $this->line("  Matched {$matched}/".count($report['matches']));

        if ($phases['fees']) {
            $this->newLine();
            $this->info('--- Fees ---');
            foreach ($report['fees'] as $f) {
                if ($f['action'] === 'set') {
                    $this->line(sprintf('  match %d: R%s -> R%s', $f['match_id'], number_format($f['old'], 0), number_format($f['new'], 0)));
                } else {
                    $this->line(sprintf('  match %d: %s (%s)', $f['match_id'], $f['action'], $f['note'] ?? ''));
                }
            }
        }

        if ($phases['md']) {
            $this->newLine();
            $this->info('--- Match directors ---');
            foreach ($report['directors'] as $d) {
                if (($d['status'] ?? '') === 'unresolved') {
                    $this->warn(sprintf('  #%d  UNRESOLVED director "%s"', $d['old_event_id'], $d['md_name']));
                } elseif (isset($d['md_name'])) {
                    $this->line(sprintf('  #%d  %s -> user %d  [%s]', $d['old_event_id'], $d['md_name'], $d['user_id'] ?? 0, $d['status']));
                } else {
                    $this->line(sprintf('  #%d  %s', $d['old_event_id'], $d['status']));
                }
            }
        }

        if ($phases['registrations']) {
            $this->newLine();
            $this->info('--- Users ---');
            $this->line("  Found existing: {$report['users']['found']}");
            $this->line("  Stub created:   {$report['users']['created']}");
            foreach ($report['users']['details'] as $u) {
                $this->line("    + {$u['name']} (SAPRF {$u['saprf']}) {$u['email']}");
            }

            $this->newLine();
            $this->info('--- Registrations ---');
            $this->line("  Created:            {$report['registrations']['created']}");
            $this->line("  Skipped (existing): {$report['registrations']['skipped_existing']}");
            $this->line("  Skipped (no match): {$report['registrations']['skipped_no_match']}");
        }

        $unresolvedDivs = array_unique($report['unresolved']['divisions']);
        if ($unresolvedDivs) {
            $this->newLine();
            $this->warn('Unknown divisions (registrations skipped): '.implode(', ', $unresolvedDivs));
        }
    }

    private function writeReportFile(array $report): void
    {
        $path = storage_path('app/upcoming-entries-report.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report['unresolved'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();
        $this->line("Unresolved items written to: {$path}");
    }
}
