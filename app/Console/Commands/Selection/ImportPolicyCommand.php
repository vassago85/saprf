<?php

namespace App\Console\Commands\Selection;

use App\Models\SelectionCycle;
use App\Services\Selection\PolicyImportService;
use Illuminate\Console\Command;

class ImportPolicyCommand extends Command
{
    protected $signature = 'selection:import-policy
        {path : Path to the policy JSON file (absolute or relative to base_path())}
        {--cycle= : Selection cycle id (defaults to the active cycle for the JSON series/season)}
        {--series= : Series (e.g. PR22); used with --season when --cycle is omitted}
        {--season= : Season (e.g. 2027); used with --series when --cycle is omitted}';

    protected $description = 'Import a versioned selection policy JSON into a selection cycle and mark it active';

    public function handle(PolicyImportService $service): int
    {
        $path = $this->argument('path');
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('#^[A-Z]:[\\\\/]#i', $path)) {
            $path = base_path($path);
        }

        $cycle = $this->resolveCycle();
        if (! $cycle) {
            return self::FAILURE;
        }

        $this->info("Importing policy into cycle #{$cycle->id} ({$cycle->series} {$cycle->season}) from {$path}");

        $policy = $service->import($path, $cycle);

        $this->info("Imported policy version {$policy->version} (hash: ".substr($policy->source_hash, 0, 12).'...)');
        $this->info("Cycle #{$cycle->id} active policy set to version {$policy->version}.");

        return self::SUCCESS;
    }

    private function resolveCycle(): ?SelectionCycle
    {
        if ($id = $this->option('cycle')) {
            $cycle = SelectionCycle::find((int) $id);
            if (! $cycle) {
                $this->error("Cycle #{$id} not found.");

                return null;
            }

            return $cycle;
        }

        $series = $this->option('series');
        $season = $this->option('season');
        if (! $series || ! $season) {
            $this->error('Pass --cycle=<id>, or both --series and --season.');

            return null;
        }

        $cycle = SelectionCycle::where('series', $series)->where('season', $season)->first();
        if (! $cycle) {
            $this->error("No cycle found for {$series} {$season}. Create it first.");

            return null;
        }

        return $cycle;
    }
}
