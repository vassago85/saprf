<?php

namespace App\Console\Commands\Selection;

use App\Models\SelectionCycle;
use App\Services\Selection\SelectionCycleReevaluationService;
use Illuminate\Console\Command;

class ReevaluateCommand extends Command
{
    protected $signature = 'selection:reevaluate {--cycle=all : Cycle id to re-evaluate, or "all" for every cycle}';

    protected $description = 'Re-evaluate ELG + PART rules and recompute state for every athlete in one or all selection cycles';

    public function handle(SelectionCycleReevaluationService $service): int
    {
        $target = $this->option('cycle');
        $cycles = $target === 'all'
            ? SelectionCycle::query()->get()
            : SelectionCycle::query()->whereKey((int) $target)->get();

        if ($cycles->isEmpty()) {
            $this->warn('No selection cycles to process.');

            return self::SUCCESS;
        }

        foreach ($cycles as $cycle) {
            $this->info("Re-evaluating cycle #{$cycle->id} ({$cycle->series} {$cycle->season})...");
            $bar = $this->output->createProgressBar();
            $bar->start();
            $summary = $service->run($cycle, function () use ($bar) {
                $bar->advance();
            });
            $bar->finish();
            $this->newLine();
            $this->line("  athletes: {$summary['athletes']}, ELG evals: {$summary['elg']}, PART evals: {$summary['part']}");
        }

        return self::SUCCESS;
    }
}
