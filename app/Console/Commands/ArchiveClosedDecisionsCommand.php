<?php

namespace App\Console\Commands;

use App\Enums\DecisionStatus;
use App\Models\Decision;
use App\Services\DecisionResultsNotifier;
use Illuminate\Console\Command;

/**
 * Flip published decisions to archived once their voting window has ended,
 * then email ExCo + voters a results digest (one per publish batch).
 */
class ArchiveClosedDecisionsCommand extends Command
{
    protected $signature = 'saprf:archive-closed-decisions';

    protected $description = 'Archive Decision Register items whose voting window has closed and email results to ExCo and voters.';

    public function handle(DecisionResultsNotifier $resultsNotifier): int
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

        $digests = $resultsNotifier->sendPending();

        if ($archived === 0 && $digests === 0) {
            $this->info('No decisions to archive.');
        } else {
            $this->info("Archived {$archived} decision(s); sent {$digests} results digest(s).");
        }

        return self::SUCCESS;
    }
}
