<?php

namespace Database\Seeders;

use App\Models\PlatformExpense;
use App\Models\PlatformIncome;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Idempotent seeder that populates Platform Income + Expense rows for the
 * 2026 season so Finance dashboards/reports aren't empty.
 *
 * Re-running is safe — each row is keyed by a stable synthetic reference so
 * firstOrCreate is a no-op after the first run.
 */
class FinancialDemoSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('email', 'owner@saprf.co.za')->first()
            ?? User::role('owner')->first()
            ?? User::role('admin')->first();

        if (! $owner) {
            $this->command?->warn('No owner/admin found to attribute financial entries to — run RolesAndUsersSeeder first.');
            return;
        }

        $season = (string) now()->year;

        $incomeRows = [
            ['category' => 'sponsorship', 'description' => 'Q1 sponsorship — Sako SA',           'amount' => 25000.00, 'month' => 2,  'source' => 'Sako'],
            ['category' => 'sponsorship', 'description' => 'Q2 sponsorship — Bergara',           'amount' => 15000.00, 'month' => 4,  'source' => 'Bergara'],
            ['category' => 'donation',    'description' => 'Anonymous donor — youth programme',  'amount' => 5000.00,  'month' => 3,  'source' => 'Anonymous'],
            ['category' => 'grant',       'description' => 'SASCOC development grant',           'amount' => 12000.00, 'month' => 1,  'source' => 'SASCOC'],
            ['category' => 'merchandise', 'description' => 'SAPRF cap & shirt sales — Q1',       'amount' => 3500.00,  'month' => 3,  'source' => 'Merch'],
            ['category' => 'interest',    'description' => 'Interest income — fed account',      'amount' => 850.00,   'month' => 4,  'source' => 'Bank'],
        ];

        $incomeCreated = 0;
        foreach ($incomeRows as $row) {
            $reference = 'demo-income-' . $season . '-' . str_pad((string) $row['month'], 2, '0', STR_PAD_LEFT);
            $income = PlatformIncome::firstOrCreate(
                ['reference' => $reference],
                [
                    'category' => $row['category'],
                    'description' => $row['description'],
                    'amount' => $row['amount'],
                    'income_date' => now()->setMonth($row['month'])->startOfMonth()->addDays(rand(1, 25))->toDateString(),
                    'source' => $row['source'],
                    'is_recurring' => false,
                    'created_by' => $owner->id,
                ],
            );
            if ($income->wasRecentlyCreated) {
                $incomeCreated++;
            }
        }

        $expenseRows = [
            ['category' => 'hosting',     'description' => 'Cloudflare + hosting — Q1',   'amount' => 1800.00, 'month' => 1, 'vendor' => 'Cloudflare'],
            ['category' => 'software',    'description' => 'PayFast monthly fees — Jan',  'amount' => 350.00,  'month' => 1, 'vendor' => 'PayFast'],
            ['category' => 'software',    'description' => 'PayFast monthly fees — Feb',  'amount' => 410.00,  'month' => 2, 'vendor' => 'PayFast'],
            ['category' => 'software',    'description' => 'PayFast monthly fees — Mar',  'amount' => 540.00,  'month' => 3, 'vendor' => 'PayFast'],
            ['category' => 'marketing',   'description' => '2026 season launch ad spend', 'amount' => 2200.00, 'month' => 1, 'vendor' => 'Meta'],
            ['category' => 'printing',    'description' => 'Scorecards + signage',        'amount' => 1450.00, 'month' => 2, 'vendor' => 'Print Co'],
            ['category' => 'bank_charges','description' => 'Monthly bank charges — Q1',   'amount' => 690.00,  'month' => 3, 'vendor' => 'ABSA'],
            ['category' => 'insurance',   'description' => 'Annual liability insurance',  'amount' => 8500.00, 'month' => 1, 'vendor' => 'Hollard'],
        ];

        $expenseCreated = 0;
        foreach ($expenseRows as $row) {
            $reference = 'demo-expense-' . $season . '-' . str_pad((string) $row['month'], 2, '0', STR_PAD_LEFT) . '-' . substr($row['category'], 0, 4);
            $expense = PlatformExpense::firstOrCreate(
                ['reference' => $reference],
                [
                    'category' => $row['category'],
                    'description' => $row['description'],
                    'amount' => $row['amount'],
                    'expense_date' => now()->setMonth($row['month'])->startOfMonth()->addDays(rand(1, 25))->toDateString(),
                    'vendor' => $row['vendor'],
                    'is_recurring' => false,
                    'created_by' => $owner->id,
                ],
            );
            if ($expense->wasRecentlyCreated) {
                $expenseCreated++;
            }
        }

        $this->command?->info("FinancialDemoSeeder: {$incomeCreated} income + {$expenseCreated} expense rows created.");
    }
}
