<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\MembershipPayment;
use App\Models\Payment;
use App\Models\PlatformExpense;
use App\Models\PlatformIncome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

        return [
            'total_entries' => (int) $agg->total_entries,
            'gross' => (float) $agg->gross,
            'saprf_fees' => (float) $agg->saprf_fees,
            'platform_fees' => (float) $agg->platform_fees,
            'gateway_fees' => (float) $agg->gateway_fees,
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
            COALESCE(SUM(amount), 0) as gross
        ')->first();

        $gross = (float) $agg->gross;
        $membershipPlatformPct = (float) $this->settings->get('membership_platform_fee_pct', 2.5) / 100;
        $gatewayPct = (float) $this->settings->get('estimated_gateway_fee_percentage', 3.5) / 100;
        $gatewayFlat = (float) $this->settings->get('estimated_gateway_flat_fee', 2);

        $platformFees = round($gross * $membershipPlatformPct, 2);
        $gatewayFees = $agg->total_payments > 0
            ? ($gross * $gatewayPct) + ($gatewayFlat * (int) $agg->total_payments)
            : 0;

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

    public function matchFinancials(MatchEvent $match): array
    {
        $registrations = $match->registrations()
            ->where('registration_status', '!=', 'cancelled')
            ->get();

        $paid = $registrations->where('payment_status', 'paid');
        $pending = $registrations->where('payment_status', '!=', 'paid');

        $expenses = $match->expenses;
        $estimatedShooters = $match->estimated_shooters ?: ($match->max_competitors ?: 0);
        $totalExpenses = $expenses->sum(fn ($e) => $e->effectiveAmount($estimatedShooters));

        return [
            'total_registrations' => $registrations->count(),
            'paid_registrations' => $paid->count(),
            'pending_registrations' => $pending->count(),
            'member_entries' => $registrations->where('membership_fee_category', 'active_member')->count(),
            'lapsed_entries' => $registrations->where('membership_fee_category', 'lapsed_member')->count(),
            'non_member_entries' => $registrations->where('membership_fee_category', 'non_member')->count(),
            'gross_revenue' => (float) $paid->sum('fee_amount'),
            'saprf_fees' => (float) $paid->sum('saprf_fee'),
            'platform_fees' => (float) $paid->sum('platform_fee'),
            'gateway_fees' => (float) $paid->sum('gateway_fee'),
            'surcharges' => (float) $paid->sum('surcharge_amount'),
            'md_net' => (float) $paid->sum('md_net_amount'),
            'total_expenses' => $totalExpenses,
            'profit_loss' => (float) $paid->sum('md_net_amount') - $totalExpenses,
            'refunds' => (float) $registrations->sum('refund_amount'),
        ];
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
