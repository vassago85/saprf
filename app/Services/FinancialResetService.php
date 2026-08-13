<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wipes every financial artefact so the platform can be handed over on a
 * clean slate. Ledger tables are emptied, membership payment flags reset,
 * and match-registration money columns zeroed (so the dashboard's match
 * revenue — which aggregates *paid* registrations — drops to R0).
 *
 * Deliberately preserves member, match, score, fee-tier and settings data:
 * only transactional/financial state is touched.
 */
class FinancialResetService
{
    /**
     * Ledger tables emptied by a reset, in child->parent FK order. Deleting
     * child-first (rather than TRUNCATE) keeps the whole thing inside a
     * transaction so a failure rolls back — you never get a half-wiped ledger.
     */
    public const LEDGER_TABLES = [
        'financial_transactions',
        'payout_items',
        'payouts',
        'platform_expenses',
        'platform_income',
        'match_expenses',
        'payments',
        'membership_payments',
    ];

    /**
     * Money columns reset on every match registration. `payment_status` is
     * flipped separately so the aggregate excludes these rows.
     *
     * @var array<string, int|null>
     */
    private const REGISTRATION_MONEY_COLUMNS = [
        'fee_amount' => 0,
        'surcharge_amount' => 0,
        'saprf_fee' => 0,
        'platform_fee' => 0,
        'gateway_fee' => 0,
        'md_net_amount' => 0,
        'refund_amount' => null,
        'admin_fee_charged' => null,
    ];

    public function __construct(
        private readonly FinancialService $financials,
    ) {}

    /**
     * Row counts for everything a reset would touch — used to show an impact
     * summary before the destructive action is confirmed.
     *
     * @return array{ledger: array<string,int>, paid_registrations: int, total_registrations: int, total: int}
     */
    public function preview(): array
    {
        $ledger = [];
        foreach (self::LEDGER_TABLES as $table) {
            $ledger[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        $totalRegistrations = Schema::hasTable('match_registrations')
            ? DB::table('match_registrations')->count()
            : 0;

        $paidRegistrations = Schema::hasTable('match_registrations')
            ? DB::table('match_registrations')->where('payment_status', 'paid')->count()
            : 0;

        return [
            'ledger' => $ledger,
            'paid_registrations' => $paidRegistrations,
            'total_registrations' => $totalRegistrations,
            'total' => array_sum($ledger) + $paidRegistrations,
        ];
    }

    /**
     * Permanently clear every financial artefact and reset match/membership
     * payment state so the platform starts fresh.
     *
     * @return array{before: array<string,int>, paid_registrations: int, registrations_reset: int}
     */
    public function wipe(): array
    {
        $preview = $this->preview();

        $registrationsReset = DB::transaction(function (): int {
            foreach (self::LEDGER_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            // Members shouldn't display "paid" against a now-deleted payment.
            // `status`, `expiry_date` and `fee_tier_id` are administrative,
            // not transaction data, so they're left untouched.
            if (Schema::hasTable('memberships') && Schema::hasColumn('memberships', 'payment_status')) {
                DB::table('memberships')->update(['payment_status' => 'unpaid']);
            }

            // Zero the money columns and mark every registration unpaid so the
            // dashboard's match-revenue aggregate reads R0. The registration
            // records themselves (shooter, match link, scores) are kept.
            $reset = 0;
            if (Schema::hasTable('match_registrations')) {
                $reset = DB::table('match_registrations')->count();
                DB::table('match_registrations')->update(array_merge(
                    self::REGISTRATION_MONEY_COLUMNS,
                    ['payment_status' => 'pending'],
                ));
            }

            return $reset;
        });

        $this->financials->clearCache();

        return [
            'before' => $preview['ledger'],
            'paid_registrations' => $preview['paid_registrations'],
            'registrations_reset' => $registrationsReset,
        ];
    }
}
