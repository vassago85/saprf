<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use Illuminate\Support\Collection;

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
    public function __construct(
        private readonly FinancialService $financials,
        private readonly SettingsService $settings,
    ) {}

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

        $projectedEntries = $this->projectedEntries($match, $financials['excluded_entries']);
        $projectedTotals = $this->projectedTotals($financials, $projectedEntries);

        return [
            'gross_revenue' => $financials['gross_revenue'],
            'saprf_fees' => $financials['saprf_fees'],
            'platform_fee_total' => $financials['platform_fees'],
            'gateway_fees' => $financials['gateway_fees'],
            'surcharges' => $financials['surcharges'],
            'md_net' => $financials['md_net'],
            'md_payout_projection' => $projectedTotals['md_net'],
            'total_expenses' => $financials['total_expenses'],
            'profit_loss' => $financials['profit_loss'],
            'refunds' => $financials['refunds'],
            'paid_registrations' => $financials['paid_registrations'],
            'platform_collected_count' => $financials['platform_collected_count'],
            'saprf_levy_count' => $financials['saprf_levy_count'],
            'platform_fee_count' => $financials['platform_fee_count'],
            'paid_entries' => $financials['paid_entries'],
            'excluded_entries' => $financials['excluded_entries'],
            'projected_entries' => $projectedEntries,
            'projected_totals' => $projectedTotals,
            'counts' => $counts,
        ];
    }

    /**
     * Returns the pending registrations wrapped in a tiny value-object that
     * exposes the SAME per-row keys as a paid registration (fee_amount,
     * saprf_fee, platform_fee, gateway_fee, surcharge_amount, md_net_amount)
     * but with the fees back-filled from the worst-case rate list when they
     * are stored as zero.
     *
     * Why: shooters who register but haven't paid yet frequently have the
     * fee columns stored as 0 (import stubs, manual adds, waived entries
     * flipped back). Showing those as R0 deductions misleads the MD into
     * thinking the pending row is pure profit. The MD will eventually
     * collect that money from the shooter — the gateway cut applies either
     * way — so we project the WORST-case gateway fee onto those rows.
     *
     * Zero-fee rows (true waives, R0 juniors) stay excluded from the
     * projection — they neither pay nor cost anything.
     */
    private function projectedEntries(MatchEvent $match, Collection $excluded): Collection
    {
        $saprfType = (string) $this->settings->get('saprf_fee_type', 'fixed');
        $saprfValue = (float) $this->settings->get('saprf_fee_value', 50);
        $platformType = (string) $this->settings->get('platform_fee_type', 'fixed');
        $platformValue = (float) $this->settings->get('platform_fee_value', 0);
        $gatewayPct = (float) $this->settings->get('estimated_gateway_fee_percentage', 3.5) / 100;
        $gatewayFlat = (float) $this->settings->get('estimated_gateway_flat_fee', 2.00);

        return $excluded
            ->filter(fn ($reg) => (float) $reg->fee_amount > 0 && $reg->payment_status !== 'paid')
            ->map(function ($reg) use ($match, $saprfType, $saprfValue, $platformType, $platformValue, $gatewayPct, $gatewayFlat) {
                $fee = (float) $reg->fee_amount;
                $surchargeStored = (float) $reg->surcharge_amount;
                $baseFee = max(0.0, $fee - $surchargeStored);

                // Prefer the fees already stored on the registration — they
                // were computed at register-time with the then-current rate
                // list and may differ from the current global settings. If
                // the row is a zero/stub (import, waive-and-re-add), fall
                // back to the current rate list to get a worst-case estimate.
                //
                // NB: must compare as float — decimal:2 columns are cast to a
                // string like "0.00", which PHP's `?:` operator treats as
                // truthy and would skip the fallback.
                $saprfStored = (float) $reg->saprf_fee;
                $platformStored = (float) $reg->platform_fee;
                $gatewayStored = (float) $reg->gateway_fee;

                $saprf = $saprfStored > 0
                    ? $saprfStored
                    : $this->resolveRate($saprfType, $saprfValue, $baseFee);
                $platform = $platformStored > 0
                    ? $platformStored
                    : $this->resolveRate($platformType, $platformValue, $baseFee);
                $gateway = $gatewayStored > 0
                    ? $gatewayStored
                    : round($fee * $gatewayPct + $gatewayFlat, 2);

                $mdNet = round(max(0.0, $fee - $saprf - $platform - $gateway - $surchargeStored), 2);

                // Mutate the model instance (safe — we're not calling save()
                // and the controller treats these as read-only projections).
                $reg->saprf_fee = round($saprf, 2);
                $reg->platform_fee = round($platform, 2);
                $reg->gateway_fee = round($gateway, 2);
                $reg->md_net_amount = $mdNet;

                return $reg;
            })
            ->values();
    }

    /**
     * Rolls up the paid-plus-projected totals into one array so the view can
     * render a "projected" totals row next to the "settled" one without
     * recomputing anything.
     *
     * @return array{
     *     fee_amount: float,
     *     surcharges: float,
     *     saprf_fees: float,
     *     platform_fees: float,
     *     gateway_fees: float,
     *     md_net: float,
     *     entry_count: int,
     * }
     */
    private function projectedTotals(array $financials, Collection $projectedEntries): array
    {
        $paidCount = $financials['paid_entries']->count();

        return [
            'fee_amount' => round((float) $financials['gross_revenue'] + (float) $projectedEntries->sum('fee_amount'), 2),
            'surcharges' => round((float) $financials['surcharges'] + (float) $projectedEntries->sum('surcharge_amount'), 2),
            'saprf_fees' => round((float) $financials['saprf_fees'] + (float) $projectedEntries->sum('saprf_fee'), 2),
            'platform_fees' => round((float) $financials['platform_fees'] + (float) $projectedEntries->sum('platform_fee'), 2),
            'gateway_fees' => round((float) $financials['gateway_fees'] + (float) $projectedEntries->sum('gateway_fee'), 2),
            'md_net' => round((float) $financials['md_net'] + (float) $projectedEntries->sum('md_net_amount'), 2),
            'entry_count' => $paidCount + $projectedEntries->count(),
        ];
    }

    /**
     * Mini-copy of {@see RegistrationPricingService::resolveFee()} — kept
     * local so this service has no new dependency on that class. "fixed"
     * values pass through unchanged; "percentage" values apply to the base
     * fee (fee minus surcharge).
     */
    private function resolveRate(string $type, float $value, float $baseFee): float
    {
        return $type === 'fixed'
            ? round($value, 2)
            : round($baseFee * ($value / 100), 2);
    }
}
