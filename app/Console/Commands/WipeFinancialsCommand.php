<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nukes every financial artefact from the database while leaving member,
 * match, score, and fee-tier data intact.
 *
 * Wipes: financial_transactions, payout_items, payouts, platform_expenses,
 * platform_income, match_expenses, payments, membership_payments.
 *
 * Also clears the financial-status columns on `memberships` so members
 * aren't left in a limbo state pointing at rows that no longer exist.
 * `membership_fee_tiers` (price catalog) is preserved.
 *
 * Guarded behind a --confirm flag AND a typed 'WIPE' string. In
 * production add --force to bypass the interactive TTY prompt.
 */
class WipeFinancialsCommand extends Command
{
    protected $signature = 'financials:wipe
        {--confirm : Actually run the wipe (default is dry-run showing row counts)}
        {--force : Skip the interactive confirmation prompt (for scripted server runs)}';

    protected $description = 'Delete every financial transaction, payment, payout, expense and income record.';

    /**
     * Tables to truncate, in child->parent FK order. Rows are deleted
     * inside a transaction so a failure anywhere rolls the whole thing
     * back — you never end up with a half-wiped ledger.
     */
    private const TABLES_IN_ORDER = [
        'financial_transactions',
        'payout_items',
        'payouts',
        'platform_expenses',
        'platform_income',
        'match_expenses',
        'payments',
        'membership_payments',
    ];

    public function handle(): int
    {
        $counts = $this->currentRowCounts();

        $this->newLine();
        $this->info('Financial tables — current row counts:');
        $this->table(
            ['Table', 'Rows'],
            collect($counts)->map(fn ($c, $t) => [$t, number_format($c)])->values()->all()
        );

        if (! $this->option('confirm')) {
            $this->warn('Dry-run only. Re-run with --confirm to actually delete rows.');
            return self::SUCCESS;
        }

        $total = array_sum($counts);
        if ($total === 0) {
            $this->info('Nothing to wipe — every financial table is already empty.');
            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $typed = (string) $this->ask("Type WIPE to permanently delete {$total} financial rows");
            if ($typed !== 'WIPE') {
                $this->error('Aborted — you did not type WIPE.');
                return self::FAILURE;
            }
        }

        DB::transaction(function () {
            // MySQL enforces FKs even inside a transaction. Delete
            // child-first (per TABLES_IN_ORDER) rather than TRUNCATE,
            // because TRUNCATE bypasses transactions on some engines.
            foreach (self::TABLES_IN_ORDER as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::table($table)->delete();
            }

            // Reset the memberships table's payment_status flag so
            // members don't display "paid" against a now-deleted
            // membership_payments row. Deliberately does not touch
            // `status`, `expiry_date`, or `fee_tier_id` — those are
            // administrative fields, not transaction data.
            if (Schema::hasTable('memberships') && Schema::hasColumn('memberships', 'payment_status')) {
                DB::table('memberships')->update([
                    'payment_status' => 'unpaid',
                ]);
            }
        });

        $after = $this->currentRowCounts();
        $this->newLine();
        $this->info('Wipe complete. Row counts after:');
        $this->table(
            ['Table', 'Rows after'],
            collect($after)->map(fn ($c, $t) => [$t, number_format($c)])->values()->all()
        );

        return self::SUCCESS;
    }

    /** @return array<string,int> */
    private function currentRowCounts(): array
    {
        $counts = [];
        foreach (self::TABLES_IN_ORDER as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }
        return $counts;
    }
}
