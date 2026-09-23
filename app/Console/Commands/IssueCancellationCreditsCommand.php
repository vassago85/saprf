<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Services\AccountCreditService;
use Illuminate\Console\Command;

/**
 * Backfill entry credit for matches that were cancelled before crediting
 * existed, or re-run it safely after a cancel. Already-credited entries
 * are skipped.
 */
class IssueCancellationCreditsCommand extends Command
{
    protected $signature = 'matches:issue-cancellation-credits
                            {match? : Only this match id}';

    protected $description = 'Credit payers for paid entries on cancelled matches';

    public function handle(AccountCreditService $credits): int
    {
        $query = MatchEvent::query()->where('status', 'cancelled')->orderBy('id');

        $matchId = $this->argument('match');
        if ($matchId !== null && $matchId !== '') {
            $query->whereKey((int) $matchId);
        }

        $matches = $query->get();

        if ($matches->isEmpty()) {
            $this->info('No cancelled matches to credit.');

            return self::SUCCESS;
        }

        $rows = [];
        $total = 0.0;

        foreach ($matches as $match) {
            $issued = $credits->issueForCancelledMatch($match);
            foreach ($issued as $credit) {
                $rows[] = [
                    $match->id,
                    $match->name,
                    $credit->user?->name ?? ('user #'.$credit->user_id),
                    $credit->getAttribute('shooter_name') ?: '—',
                    number_format((float) $credit->amount, 2),
                ];
                $total += (float) $credit->amount;
            }
        }

        if ($rows === []) {
            $this->info('Cancelled matches are already credited. Nothing new to issue.');

            return self::SUCCESS;
        }

        $this->table(['Match', 'Name', 'Credited account', 'Shooter', 'Amount'], $rows);
        $this->info('Issued R '.number_format($total, 2).' across '.count($rows).' entry credit(s).');

        return self::SUCCESS;
    }
}
