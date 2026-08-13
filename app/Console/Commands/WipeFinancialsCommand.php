<?php

namespace App\Console\Commands;

use App\Services\FinancialResetService;
use Illuminate\Console\Command;

/**
 * Nukes every financial artefact from the database while leaving member,
 * match, score, fee-tier and settings data intact.
 *
 * Wipes: financial_transactions, payout_items, payouts, platform_expenses,
 * platform_income, match_expenses, payments, membership_payments. Also
 * resets membership payment flags and zeroes match-registration money
 * columns so the dashboard reads R0. The heavy lifting lives in
 * {@see FinancialResetService} — shared with the admin "Clear Finance Data"
 * UI so both paths behave identically.
 *
 * Guarded behind a --confirm flag AND a typed 'WIPE' string. In production
 * add --force to bypass the interactive TTY prompt.
 */
class WipeFinancialsCommand extends Command
{
    protected $signature = 'financials:wipe
        {--confirm : Actually run the wipe (default is dry-run showing row counts)}
        {--force : Skip the interactive confirmation prompt (for scripted server runs)}';

    protected $description = 'Delete every financial transaction, payment, payout, expense and income record.';

    public function handle(FinancialResetService $reset): int
    {
        $preview = $reset->preview();

        $this->newLine();
        $this->info('Financial tables — current row counts:');
        $rows = collect($preview['ledger'])->map(fn ($c, $t) => [$t, number_format($c)])->values()->all();
        $rows[] = ['match_registrations (paid)', number_format($preview['paid_registrations'])];
        $this->table(['Table', 'Rows'], $rows);

        if (! $this->option('confirm')) {
            $this->warn('Dry-run only. Re-run with --confirm to actually delete rows.');
            return self::SUCCESS;
        }

        if ($preview['total'] === 0) {
            $this->info('Nothing to wipe — every financial table is already empty.');
            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $typed = (string) $this->ask("Type WIPE to permanently delete {$preview['total']} financial rows");
            if ($typed !== 'WIPE') {
                $this->error('Aborted — you did not type WIPE.');
                return self::FAILURE;
            }
        }

        $result = $reset->wipe();

        $this->newLine();
        $this->info('Wipe complete.');
        $this->line("  Reset {$result['registrations_reset']} match registration(s) to unpaid.");

        return self::SUCCESS;
    }
}
