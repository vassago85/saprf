<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;

/**
 * Single source of truth for the "how many entries does this match have?"
 * number, and the money view that sits alongside it on the workspace.
 *
 * Before this service lived here every surface made its own interpretation
 * of what counted:
 *   - the match show page used `$match->registrations->count()` (every row,
 *     including cancellations and walk-ins seeded through score imports)
 *   - the finance report ignored cancellations but hid pending rows from
 *     money totals (so counts and money disagreed)
 *   - the announcement composer counted only `['confirmed', 'waitlisted']`
 *     because that's who actually receives messages
 *
 * The result was 32/28/27 for the same match on three different screens.
 * The workspace standardises on three explicit buckets so every surface
 * picks the right one:
 *
 *   - counted:  all live entries (anything not cancelled). The "entries"
 *               figure in the header, the Entries tab table, the finance
 *               report count, and the Impact export all share this number.
 *   - paid:     subset that actually settled the entry fee. Powers money.
 *   - recipients: subset eligible for a match broadcast (confirmed +
 *                 waitlisted). Only announcements use this.
 */
class MatchFinanceService
{
    public function __construct(private readonly FinancialService $financials) {}

    /**
     * Aggregate counts shared by every workspace surface. Values are cheap
     * COUNT queries — safe to call from the chrome without worrying about
     * row-level eager loads.
     *
     * @return array{
     *     counted: int,
     *     paid: int,
     *     recipients: int,
     *     waitlisted: int,
     *     pending_payment: int,
     *     walk_ins: int,
     * }
     */
    public function entryCounts(MatchEvent $match): array
    {
        $base = MatchRegistration::query()
            ->where('match_id', $match->id)
            ->where('registration_status', '!=', 'cancelled');

        return [
            'counted' => (clone $base)->count(),
            'paid' => (clone $base)->where('payment_status', 'paid')->count(),
            'recipients' => (clone $base)
                ->whereIn('registration_status', ['confirmed', 'waitlisted'])
                ->count(),
            'waitlisted' => (clone $base)
                ->where('registration_status', 'waitlisted')
                ->count(),
            'pending_payment' => (clone $base)
                ->where('fee_amount', '>', 0)
                ->whereNotIn('payment_status', ['paid', 'waived'])
                ->count(),
            'walk_ins' => (clone $base)
                ->where('registration_source', 'walk_in')
                ->count(),
        ];
    }

    /**
     * Money view mapped to the counts above. Pulls from the authoritative
     * {@see FinancialService::matchFinancials()} but exposes:
     *
     *   - `platform_fee_total`   SUM(platform_fee) on paid rows — the real
     *                             number. Replaces the "entries × R50"
     *                             copy that drifted when any entry was
     *                             waived, imported, or seeded.
     *   - `md_net`               Settled MD take-home (the ONLY payout
     *                             figure shown anywhere — the duplicate
     *                             "MD Payout @ actual" was removed).
     *   - `md_payout_projection` Snapshot kept for the finance tab's
     *                             "projected if everyone pays" row. It is
     *                             derived from live paid rows plus the
     *                             fee-list expectation for pending rows,
     *                             NOT fabricated per-shooter × count.
     *
     * @return array<string, mixed>
     */
    public function matchMoney(MatchEvent $match): array
    {
        $financials = $this->financials->matchFinancials($match);

        $counts = $this->entryCounts($match);

        $projected = $this->projectedMdNet($match, $financials);

        return [
            'gross_revenue' => $financials['gross_revenue'],
            'saprf_fees' => $financials['saprf_fees'],
            'platform_fee_total' => $financials['platform_fees'],
            'gateway_fees' => $financials['gateway_fees'],
            'surcharges' => $financials['surcharges'],
            'md_net' => $financials['md_net'],
            'md_payout_projection' => $projected,
            'total_expenses' => $financials['total_expenses'],
            'profit_loss' => $financials['profit_loss'],
            'refunds' => $financials['refunds'],
            'paid_entries' => $financials['paid_entries'],
            'excluded_entries' => $financials['excluded_entries'],
            'counts' => $counts,
        ];
    }

    /**
     * Projected MD net if every outstanding entry collected the standard
     * platform/saprf/gateway split. Pending rows contribute the fee minus
     * the per-row standard deductions from the match's fee list rather
     * than a flat "per-shooter × count" estimate.
     */
    private function projectedMdNet(MatchEvent $match, array $financials): float
    {
        $paid = (float) $financials['md_net'];

        $pending = $financials['excluded_entries']
            ->filter(fn ($reg) => (float) $reg->fee_amount > 0)
            ->sum(function ($reg) {
                $fee = (float) $reg->fee_amount;
                $saprf = (float) ($reg->saprf_fee ?: 0);
                $platform = (float) ($reg->platform_fee ?: 0);
                $gateway = (float) ($reg->gateway_fee ?: 0);
                $surcharge = (float) ($reg->surcharge_amount ?: 0);

                return max(0.0, $fee - $saprf - $platform - $gateway - $surcharge);
            });

        return round($paid + (float) $pending, 2);
    }
}
