<?php

namespace App\Services;

use App\Models\MatchRegistration;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Monthly platform-fee payout.
 *
 * SAPRF is billed a platform fee (currently R50 per shooter per match — see
 * platform_fee_type / platform_fee_value) that is booked against every paid
 * registration. The platform operator (nominated via the
 * platform_operator_user_id setting) is settled monthly, based on when the
 * registration was PAID — not when the match runs. Cashflow arrives when the
 * shooter pays PayFast, so grouping by registered_at (the paid-at proxy) keeps
 * the operator's monthly invoice in step with what's actually in the bank.
 *
 * All matched rows are attached as PayoutItems so the operator invoice has a
 * defensible per-registration audit trail.
 */
class PlatformPayoutService
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Preview what a monthly payout would total, without creating anything.
     *
     * @param  Carbon  $month  Any date inside the target month.
     * @return array{
     *     period_start: Carbon,
     *     period_end: Carbon,
     *     platform_fees: float,
     *     entry_count: int,
     *     existing_payout: ?Payout,
     *     operator_user_id: ?int,
     * }
     */
    public function preview(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $agg = $this->baseQuery($start, $end)
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(platform_fee), 0) as fees')
            ->first();

        $withdrawals = $this->withdrawalFeeQuery($start, $end)
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(penalty_platform_fee), 0) as fees')
            ->first();

        return [
            'period_start' => $start,
            'period_end' => $end,
            'platform_fees' => round((float) $agg->fees + (float) $withdrawals->fees, 2),
            'entry_count' => (int) $agg->entries + (int) $withdrawals->entries,
            'existing_payout' => $this->existingPayoutForMonth($start, $end),
            'operator_user_id' => $this->operatorUserId(),
        ];
    }

    /**
     * Create a platform_operator payout for the given month.
     *
     * @throws RuntimeException when no operator is configured, when a payout
     *                          for the month already exists, or when the month
     *                          has no billable platform fees to settle.
     */
    public function createForMonth(Carbon $month, User $creator, ?string $notes = null): Payout
    {
        $operatorId = $this->operatorUserId();
        if ($operatorId === null) {
            throw new RuntimeException('Platform operator is not configured. Set it in Site Settings before generating a payout.');
        }

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        if ($this->existingPayoutForMonth($start, $end)) {
            throw new RuntimeException("A platform payout already exists for {$start->format('F Y')}.");
        }

        $registrations = $this->baseQuery($start, $end)
            ->with(['user:id,name', 'match:id,name,match_date'])
            ->orderBy('registered_at')
            ->get();

        $withdrawals = $this->withdrawalFeeQuery($start, $end)
            ->with(['user:id,name', 'match:id,name,match_date'])
            ->orderBy('cancelled_at')
            ->get();

        $total = round((float) $registrations->sum('platform_fee') + (float) $withdrawals->sum('penalty_platform_fee'), 2);

        if ($total <= 0) {
            throw new RuntimeException("No platform fees to settle for {$start->format('F Y')}.");
        }

        return DB::transaction(function () use ($start, $end, $operatorId, $creator, $notes, $registrations, $withdrawals, $total) {
            $payout = Payout::create([
                'reference' => Payout::generateReference(),
                'payee_type' => 'platform_operator',
                'payee_user_id' => $operatorId,
                'match_id' => null,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'gross_amount' => $total,
                'fees_deducted' => 0,
                'net_amount' => $total,
                'status' => 'pending',
                'notes' => $notes,
                'created_by' => $creator->id,
            ]);

            $items = $registrations->map(fn (MatchRegistration $reg) => [
                'payout_id' => $payout->id,
                'source_type' => 'match_registration',
                'source_id' => $reg->id,
                'description' => sprintf(
                    '%s — %s (%s)',
                    $reg->match?->name ?? 'Match #' . $reg->match_id,
                    $reg->user?->name ?? 'Shooter #' . $reg->user_id,
                    $reg->registered_at?->format('d M Y') ?? '—',
                ),
                'gross_amount' => (float) $reg->platform_fee,
                'platform_fee' => (float) $reg->platform_fee,
                'gateway_fee' => 0,
                'saprf_fee' => 0,
                'net_amount' => (float) $reg->platform_fee,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            foreach ($withdrawals as $reg) {
                $items[] = [
                    'payout_id' => $payout->id,
                    'source_type' => 'match_registration',
                    'source_id' => $reg->id,
                    'description' => sprintf(
                        'Withdrawal fee: %s — %s (%s)',
                        $reg->match?->name ?? 'Match #' . $reg->match_id,
                        $reg->user?->name ?? 'Shooter #' . $reg->user_id,
                        $reg->cancelled_at?->format('d M Y') ?? '—',
                    ),
                    'gross_amount' => (float) $reg->penalty_platform_fee,
                    'platform_fee' => (float) $reg->penalty_platform_fee,
                    'gateway_fee' => 0,
                    'saprf_fee' => 0,
                    'net_amount' => (float) $reg->penalty_platform_fee,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            PayoutItem::insert($items);

            return $payout;
        });
    }

    /**
     * List months where paid registrations exist that haven't yet been rolled
     * into a platform payout. Excludes the current month by default — we only
     * settle completed months so late-arriving payments don't fall between
     * two invoices.
     *
     * @return list<array{month: Carbon, platform_fees: float, entry_count: int}>
     */
    public function unsettledMonths(int $lookbackMonths = 12): array
    {
        $now = now();
        $months = [];

        for ($i = 1; $i <= $lookbackMonths; $i++) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $preview = $this->preview($month);

            if ($preview['existing_payout'] || $preview['entry_count'] === 0) {
                continue;
            }

            $months[] = [
                'month' => $month,
                'platform_fees' => $preview['platform_fees'],
                'entry_count' => $preview['entry_count'],
            ];
        }

        return $months;
    }

    private function baseQuery(Carbon $start, Carbon $end)
    {
        return MatchRegistration::query()
            ->where('registration_status', '!=', 'cancelled')
            ->where('payment_status', 'paid')
            ->whereBetween('registered_at', [$start->startOfDay(), $end->endOfDay()])
            ->where('platform_fee', '>', 0);
    }

    /**
     * Paid entries the member withdrew from for a cash refund: the developer
     * keeps a share of the withdrawal fee, billed in the month they withdrew.
     */
    private function withdrawalFeeQuery(Carbon $start, Carbon $end)
    {
        return MatchRegistration::query()
            ->where('registration_status', 'cancelled')
            ->where('withdrawal_option', 'refund')
            ->whereBetween('cancelled_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->where('penalty_platform_fee', '>', 0);
    }

    private function existingPayoutForMonth(Carbon $start, Carbon $end): ?Payout
    {
        return Payout::query()
            ->where('payee_type', 'platform_operator')
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $end->toDateString())
            ->first();
    }

    private function operatorUserId(): ?int
    {
        $raw = $this->settings->get('platform_operator_user_id');

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }
}
