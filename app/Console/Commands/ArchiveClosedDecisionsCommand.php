<?php

namespace App\Console\Commands;

use App\Enums\DecisionStatus;
use App\Models\Decision;
use Illuminate\Console\Command;

/**
 * Flip published decisions to archived once their voting window has ended.
 * Safe to run often — only touches rows that are still `published` and past
 * the configured reaction window.
 */
class ArchiveClosedDecisionsCommand extends Command
{
    protected $signature = 'saprf:archive-closed-decisions';

    protected $description = 'Archive Decision Register items whose voting window has closed.';

    public function handle(): int
    {
        $archived = 0;

        Decision::query()
            ->where('status', DecisionStatus::Published)
            ->whereNotNull('published_at')
            ->orderBy('id')
            ->each(function (Decision $decision) use (&$archived): void {
                if ($decision->archiveIfVotingClosed()) {
                    $archived++;
                }
            });

        $this->info($archived === 0
            ? 'No decisions to archive.'
            : "Archived {$archived} decision(s).");

        return self::SUCCESS;
    }
}
