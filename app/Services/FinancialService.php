<?php

namespace App\Services;

use App\Models\FinancialTransaction;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\MembershipPayment;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PlatformExpense;
use App\Models\PlatformIncome;
use App\Models\User;
use App\Notifications\MdPayoutStatementNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinancialService
{
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    // ── Platform Summary ──

    public function platformSummary(?Carbon $from = null, ?Carbon $to = null): array
    {
        $cacheKey = 'fin_platform_' . ($from?->toDateString() ?? 'all') . '_' . ($to?->toDateString() ?? 'all');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($from, $to) {
            $matchRevenue = $this->matchRevenueAggregates($from, $to);
            $membershipRevenue = $this->membershipRevenueAggregates($from, $to);
            $otherIncome = $this->otherIncomeAggregates($from, $to);
            $platformExpenses = $this->platformExpenseAggregates($from, $to);

            $grossIncome = $matchRevenue['gross'] + $membershipRevenue['gross'] + $otherIncome['total'];
            $totalPlatformFees = $matchRevenue['platform_fees'] + $membershipRevenue['platform_cost'];
            $totalSaprfFees = $matchRevenue['saprf_fees'];
            $totalGatewayFees = $matchRevenue['gateway_fees'] + $membershipRevenue['gateway_fees'];
            $totalSurcharges = $matchRevenue['surcharges'];
            $totalMdPayouts = $matchRevenue['md_net'];
            $netRevenue = $totalPlatformFees + $totalSaprfFees + $totalSurcharges + $membershipRevenue['net_to_saprf'] + $otherIncome['total'];
            $netAfterExpenses = $netRevenue - $platformExpenses['total'];

            return [
                'gross_income' => $grossIncome,
                'match_revenue' => $matchRevenue,
                'membership_revenue' => $membershipRevenue,
                'other_income' => $otherIncome,
                'platform_expenses' => $platformExpenses,
                'total_platform_fees' => $totalPlatformFees,
                'total_saprf_fees' => $totalSaprfFees,
                'total_gateway_fees' => $totalGatewayFees,
                'total_surcharges' => $totalSurcharges,
                'net_revenue' => $netRevenue,
                'net_after_expenses' => $netAfterExpenses,
                'total_md_payouts' => $totalMdPayouts,
            ];
        });
    }

    private function matchRevenueAggregates(?Carbon $from, ?Carbon $to): array
    {
        $query = MatchRegistration::query()
            ->where('registration_status', '!=', 'cancelled')
            ->where('payment_status', 'paid');

        if ($from) {
            $query->where('registered_at', '>=', $from->startOfDay());
        }
        if ($to) {
            $query->where('registered_at', '<=', $to->endOfDay());
        }

        $agg = $query->selectRaw('
            COUNT(*) as total_entries,
            COALESCE(SUM(fee_amount), 0) as gross,
            COALESCE(SUM(saprf_fee), 0) as saprf_fees,
            COALESCE(SUM(platform_fee), 0) as platform_fees,
            COALESCE(SUM(gateway_fee), 0) as gateway_fees,
            COALESCE(SUM(surcharge_amount), 0) as surcharges,
            COALESCE(SUM(md_net_amount), 0) as md_net,
            SUM(CASE WHEN membership_fee_category = "active_member" THEN 1 ELSE 0 END) as member_entries,
            SUM(CASE WHEN membership_fee_category = "lapsed_member" THEN 1 ELSE 0 END) as lapsed_entries,
            SUM(CASE WHEN membership_fee_category = "non_member" THEN 1 ELSE 0 END) as non_member_entries
        ')->first();

        // Withdrawal fees kept on cash refunds: the entry is cancelled, but
        // the developer, PayFast and SAPRF shares still count as income.
        $withdrawals = MatchRegistration::query()
            ->where('registration_status', 'cancelled')
            ->where('withdrawal_option', 'refund')
            ->when($from, fn ($q) => $q->where('cancelled_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($q) => $q->where('cancelled_at', '<=', $to->copy()->endOfDay()))
            ->selectRaw('
                COALESCE(SUM(penalty_platform_fee), 0) as platform_fees,
                COALESCE(SUM(penalty_gateway_fee), 0) as gateway_fees,
                COALESCE(SUM(penalty_saprf_fee), 0) as saprf_fees
            ')->first();

        $withdrawalFees = (float) $withdrawals->platform_fees + (float) $withdrawals->gateway_fees + (float) $withdrawals->saprf_fees;

        return [
            'total_entries' => (int) $agg->total_entries,
            'gross' => (float) $agg->gross + $withdrawalFees,
            'saprf_fees' => (float) $agg->saprf_fees + (float) $withdrawals->saprf_fees,
            'platform_fees' => (float) $agg->platform_fees + (float) $withdrawals->platform_fees,
            'gateway_fees' => (float) $agg->gateway_fees + (float) $withdrawals->gateway_fees,
            'withdrawal_fees' => $withdrawalFees,
            'surcharges' => (float) $agg->surcharges,
            'md_net' => (float) $agg->md_net,
            'member_entries' => (int) $agg->member_entries,
            'lapsed_entries' => (int) $agg->lapsed_entries,
            'non_member_entries' => (int) $agg->non_member_entries,
        ];
    }

    private function membershipRevenueAggregates(?Carbon $from, ?Carbon $to): array
    {
        $query = MembershipPayment::query()->where('status', 'confirmed');

        if ($from) {
            $query->where('payment_date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->where('payment_date', '<=', $to->toDateString());
        }

        $agg = $query->selectRaw('
            COUNT(*) as total_payments,
            COALESCE(SUM(amount), 0) as gross,
            COALESCE(SUM(gateway_fee), 0) as stored_gateway_fees,
            SUM(CASE WHEN gateway_fee IS NULL THEN 1 ELSE 0 END) as estimated_count,
            COALESCE(SUM(CASE WHEN gateway_fee IS NULL THEN amount ELSE 0 END), 0) as estimated_gross
        ')->first();

        $gross = (float) $agg->gross;
        $membershipPlatformPct = (float) $this->settings->get('membership_platform_fee_pct', 2.5) / 100;
        $gatewayPct = (float) $this->settings->get('estimated_gateway_fee_percentage', 3.5) / 100;
        $gatewayFlat = (float) $this->settings->get('estimated_gateway_flat_fee', 2);

        $platformFees = round($gross * $membershipPlatformPct, 2);
        $estimatedGateway = (int) $agg->estimated_count > 0
            ? ((float) $agg->estimated_gross * $gatewayPct) + ($gatewayFlat * (int) $agg->estimated_count)
            : 0;
        $gatewayFees = (float) $agg->stored_gateway_fees + $estimatedGateway;

        return [
            'total_payments' => (int) $agg->total_payments,
            'gross' => $gross,
            'platform_cost' => $platformFees,
            'gateway_fees' => round($gatewayFees, 2),
            'net_to_saprf' => round($gross - $gatewayFees, 2),
        ];
    }

    private function otherIncomeAggregates(?Carbon $from, ?Carbon $to): array
    {
        $query = PlatformIncome::query();

        if ($from) {
            $query->where('income_date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->where('income_date', '<=', $to->toDateString());
        }

        $agg = $query->selectRaw('
            COUNT(*) as count,
            COALESCE(SUM(amount), 0) as total
        ')->first();

        $byCategory = PlatformIncome::query()
            ->when($from, fn ($q) => $q->where('income_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('income_date', '<=', $to->toDateString()))
            ->selectRaw('category, COALESCE(SUM(amount), 0) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->toArray();

        return [
            'count' => (int) $agg->count,
            'total' => (float) $agg->total,
            'by_category' => $byCategory,
        ];
    }

    private function platformExpenseAggregates(?Carbon $from, ?Carbon $to): array
    {
        $query = PlatformExpense::query();

        if ($from) {
            $query->where('expense_date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->where('expense_date', '<=', $to->toDateString());
        }

        $agg = $query->selectRaw('
            COUNT(*) as count,
            COALESCE(SUM(amount), 0) as total
        ')->first();

        $byCategory = PlatformExpense::query()
            ->when($from, fn ($q) => $q->where('expense_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('expense_date', '<=', $to->toDateString()))
            ->selectRaw('category, COALESCE(SUM(amount), 0) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->toArray();

        return [
            'count' => (int) $agg->count,
            'total' => (float) $agg->total,
            'by_category' => $byCategory,
        ];
    }

    // ── Match-Level Financials ──

    /**
     * Reconciliation-grade match totals.
     *
     * Only `payment_status === 'paid'` rows contribute to money totals.
     * Pending / failed / refunded / waived rows are tracked separately
     * (`excluded_entries`) and show as zero in the report, so a sum of
     * the MD net column always matches the payout net to the cent.
     *
     * The `surcharges` figure is cash SAPRF retains on behalf of
     * non-/lapsed members — it is already included in `fee_amount` and
     * already subtracted from `md_net_amount`. The view surfaces it as
     * a *deduction* from gross revenue, never an addition.
     */
    public function matchFinancials(MatchEvent $match): array
    {
        $registrations = $match->registrations()
            ->with('payments')
            ->where('registration_status', '!=', 'cancelled')
            ->get()
            ->sortBy(fn ($r) => strtolower($r->shooter_name ?? $r->user?->name ?? ''))
            ->values();

        $paid = $registrations->where('payment_status', 'paid')->values();
        $excluded = $registrations->where('payment_status', '!=', 'paid')->values();

        $expenses = $match->expenses;
        $estimatedShooters = $match->estimated_shooters ?: ($match->max_competitors ?: 0);
        $totalExpenses = $expenses->sum(fn ($e) => $e->effectiveAmount($estimatedShooters));

        $gross = round((float) $paid->sum('fee_amount'), 2);
        $saprf = round((float) $paid->sum('saprf_fee'), 2);
        $platform = round((float) $paid->sum('platform_fee'), 2);
        $gateway = round((float) $paid->sum('gateway_fee'), 2);
        $surcharges = round((float) $paid->sum('surcharge_amount'), 2);
        $mdNet = round((float) $paid->sum('md_net_amount'), 2);

        return [
            'total_registrations' => $registrations->count(),
            'paid_registrations' => $paid->count(),
            'pending_registrations' => $excluded->count(),
            'member_entries' => $registrations->where('membership_fee_category', 'active_member')->count(),
            'lapsed_entries' => $registrations->where('membership_fee_category', 'lapsed_member')->count(),
            'non_member_entries' => $registrations->where('membership_fee_category', 'non_member')->count(),
            'gross_revenue' => $gross,
            'saprf_fees' => $saprf,
            'platform_fees' => $platform,
            'gateway_fees' => $gateway,
            // Surcharges are kept by SAPRF — view treats this as a deduction
            // from gross revenue, never an addition.
            'surcharges' => $surcharges,
            'md_net' => $mdNet,
            'total_expenses' => $totalExpenses,
            'profit_loss' => $mdNet - $totalExpenses,
            'refunds' => (float) $registrations->sum('refund_amount'),
            'paid_entries' => $paid,
            'excluded_entries' => $excluded,
        ];
    }

    /**
     * Create a pending match-director payout for a completed match and record
     * the matching finance-transaction ledger entry. Returns the fresh payout.
     *
     * Callers must guard against duplicate payouts (a match should never carry
     * more than one MD payout row) and against un-completed matches. Both the
     * admin "Create Payout" screen and the match-director "Complete & request
     * payout" flow funnel through this method so amounts, references, and the
     * transaction log stay consistent regardless of who initiated the payout.
     */
    public function createMdPayout(MatchEvent $match, User $creator, ?string $notes = null): Payout
    {
        $financials = $this->matchFinancials($match);

        return DB::transaction(function () use ($match, $creator, $notes, $financials) {
            $payout = Payout::create([
                'reference' => Payout::generateReference(),
                'payee_type' => 'match_director',
                'payee_user_id' => $match->created_by,
                'match_id' => $match->id,
                'gross_amount' => $financials['gross_revenue'],
                // What SAPRF retains out of gross: SAPRF levy + platform fee
                // + gateway fee + non-member surcharge. Identity check:
                //   gross - fees_deducted == net_amount
                'fees_deducted' => round(
                    $financials['saprf_fees']
                        + $financials['platform_fees']
                        + $financials['gateway_fees']
                        + $financials['surcharges'],
                    2,
                ),
                'net_amount' => $financials['md_net'],
                'status' => 'pending',
                'notes' => $notes,
                'created_by' => $creator->id,
            ]);

            $this->snapshotMatchPayout($payout, $match, $financials);

            FinancialTransaction::create([
                'type' => 'payout',
                'source_type' => 'payout',
                'source_id' => $payout->id,
                'user_id' => $creator->id,
                'amount' => $payout->net_amount,
                'description' => "Payout {$payout->reference} created for match: {$match->name}",
            ]);

            $fresh = $payout->fresh(['items']);

            // Email the MD the frozen statement PDF, once per raise.
            // Failure logged but never rolls the payout back.
            $recipient = $match->user ?? User::find($match->created_by);
            if ($recipient) {
                try {
                    $recipient->notify(new MdPayoutStatementNotification($fresh, 'raised'));
                } catch (\Throwable $e) {
                    Log::warning('Failed to dispatch payout-raised notification', [
                        'payout_id' => $fresh->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $fresh;
        });
    }

    /**
     * Freeze the per-entry snapshot for an MD payout.
     *
     * Writes one `payout_items` row per non-cancelled registration — paid
     * rows carry real money, excluded rows (unpaid/failed/refunded/waived)
     * carry zeros and the reason — plus a summary snapshot on the payout
     * itself. Called from both {@see createMdPayout()} (first freeze) and
     * {@see regenerateMatchPayoutStatement()} (admin regenerate).
     *
     * The caller is responsible for the audit log entry around regenerates;
     * this method is purely data.
     *
     * @param  array<string, mixed>  $financials  result of {@see matchFinancials()}
     */
    public function snapshotMatchPayout(Payout $payout, MatchEvent $match, array $financials): void
    {
        $payout->items()->delete();

        $sort = 0;
        foreach (['paid_entries', 'excluded_entries'] as $bucket) {
            $excluded = $bucket === 'excluded_entries';
            foreach ($financials[$bucket] as $reg) {
                $payout->items()->create([
                    'source_type' => 'match_registration',
                    'source_id' => $reg->id,
                    'match_registration_id' => $reg->id,
                    'description' => $reg->shooter_name ?: ($reg->user?->name ?? 'Unknown shooter'),
                    'shooter_name' => $reg->shooter_name ?: ($reg->user?->name ?? 'Unknown shooter'),
                    'membership_fee_category' => $reg->membership_fee_category,
                    'payment_status' => $reg->payment_status,
                    'payment_method' => $reg->paymentMethodSlug(),
                    'gross_amount' => $excluded ? 0 : (float) $reg->fee_amount,
                    'surcharge_amount' => $excluded ? 0 : (float) $reg->surcharge_amount,
                    'saprf_fee' => $excluded ? 0 : (float) $reg->saprf_fee,
                    'platform_fee' => $excluded ? 0 : (float) $reg->platform_fee,
                    'gateway_fee' => $excluded ? 0 : (float) $reg->gateway_fee,
                    'net_amount' => $excluded ? 0 : (float) $reg->md_net_amount,
                    'excluded' => $excluded,
                    'excluded_reason' => $excluded ? ($reg->payment_status ?: 'pending') : null,
                    'sort_order' => $sort++,
                ]);
            }
        }

        $payout->update([
            'statement_generated_at' => now(),
            'statement_snapshot' => [
                'match' => [
                    'id' => $match->id,
                    'name' => $match->name,
                    'match_date' => optional($match->match_date)->toDateString(),
                    'match_type' => $match->match_type,
                    'series_level' => $match->series_level,
                    'md_name' => $match->user?->name,
                ],
                'summary' => [
                    'paid_registrations' => $financials['paid_registrations'],
                    'excluded_registrations' => $financials['pending_registrations'],
                    'gross_revenue' => $financials['gross_revenue'],
                    'saprf_fees' => $financials['saprf_fees'],
                    'platform_fees' => $financials['platform_fees'],
                    'gateway_fees' => $financials['gateway_fees'],
                    'surcharges' => $financials['surcharges'],
                    'md_net' => $financials['md_net'],
                ],
            ],
        ]);
    }

    /**
     * Rebuild the frozen statement from live match data, bumping version.
     *
     * Only exposed through the admin "Regenerate statement" action;
     * the caller writes the audit entry (`payout.statement_regenerated`).
     */
    public function regenerateMatchPayoutStatement(Payout $payout): Payout
    {
        if (! $payout->match) {
            return $payout;
        }

        return DB::transaction(function () use ($payout) {
            $financials = $this->matchFinancials($payout->match);

            $payout->update([
                'gross_amount' => $financials['gross_revenue'],
                'fees_deducted' => round(
                    $financials['saprf_fees']
                        + $financials['platform_fees']
                        + $financials['gateway_fees']
                        + $financials['surcharges'],
                    2,
                ),
                'net_amount' => $financials['md_net'],
                'statement_version' => ((int) $payout->statement_version) + 1,
            ]);

            $this->snapshotMatchPayout($payout->fresh(), $payout->match, $financials);

            return $payout->fresh(['items']);
        });
    }

    // ── Revenue by Match (for listing) ──

    public function revenueByMatch(?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = DB::table('match_registrations')
            ->join('matches', 'match_registrations.match_id', '=', 'matches.id')
            ->where('match_registrations.registration_status', '!=', 'cancelled')
            ->where('match_registrations.payment_status', 'paid')
            ->select([
                'matches.id',
                'matches.name',
                'matches.match_type',
                'matches.series_level',
                'matches.match_date',
                'matches.status as match_status',
                DB::raw('COUNT(*) as entries'),
                DB::raw('COALESCE(SUM(match_registrations.fee_amount), 0) as gross'),
                DB::raw('COALESCE(SUM(match_registrations.saprf_fee), 0) as saprf_fees'),
                DB::raw('COALESCE(SUM(match_registrations.platform_fee), 0) as platform_fees'),
                DB::raw('COALESCE(SUM(match_registrations.gateway_fee), 0) as gateway_fees'),
                DB::raw('COALESCE(SUM(match_registrations.md_net_amount), 0) as md_net'),
            ])
            ->groupBy('matches.id', 'matches.name', 'matches.match_type', 'matches.series_level', 'matches.match_date', 'matches.status');

        if ($from) {
            $query->where('matches.match_date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->where('matches.match_date', '<=', $to->toDateString());
        }

        return $query->orderByDesc('matches.match_date')->get()->toArray();
    }

    // ── Monthly Trend ──

    public function monthlyTrend(int $months = 12): array
    {
        $results = [];
        $now = now();

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = $now->copy()->subMonths($i)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $label = $start->format('M Y');

            $matchGross = MatchRegistration::query()
                ->where('registration_status', '!=', 'cancelled')
                ->where('payment_status', 'paid')
                ->whereBetween('registered_at', [$start, $end])
                ->sum('fee_amount');

            $memberGross = MembershipPayment::query()
                ->where('status', 'confirmed')
                ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
                ->sum('amount');

            $results[] = [
                'label' => $label,
                'match_revenue' => (float) $matchGross,
                'membership_revenue' => (float) $memberGross,
                'total' => (float) $matchGross + (float) $memberGross,
            ];
        }

        return $results;
    }

    public function clearCache(): void
    {
        Cache::forget('fin_platform_all_all');
    }
}
