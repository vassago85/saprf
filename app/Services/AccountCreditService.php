<?php

namespace App\Services;

use App\Models\AccountCredit;
use App\Models\FinancialTransaction;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\EntryRemovedCreditNotification;
use App\Notifications\MatchCancellationCreditNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountCreditService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * `refundable` is what may be cashed out through a refund request:
     * credit a member chose to keep when withdrawing skipped the withdrawal
     * fee, so it can only be spent on entries.
     *
     * @return array{posted: float, reserved: float, refund_reserved: float, checkout_reserved: float, available: float, withdrawal_credit: float, refundable: float, entries: Collection<int, AccountCredit>}
     */
    public function summary(User $user): array
    {
        $rows = AccountCredit::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [AccountCredit::STATUS_POSTED, AccountCredit::STATUS_RESERVED])
            ->latest('id')
            ->get();

        $posted = round((float) $rows->where('status', AccountCredit::STATUS_POSTED)->sum('amount'), 2);
        $reservedRows = $rows->where('status', AccountCredit::STATUS_RESERVED);
        $reserved = round((float) $reservedRows->sum(fn (AccountCredit $row) => abs((float) $row->amount)), 2);
        $refundReserved = round((float) $reservedRows
            ->where('type', AccountCredit::TYPE_REFUND)
            ->sum(fn (AccountCredit $row) => abs((float) $row->amount)), 2);

        $available = round($posted - $reserved, 2);
        $withdrawalCredit = $this->withdrawalCreditBalance($user)['amount'];

        return [
            'posted' => $posted,
            'reserved' => $reserved,
            'refund_reserved' => $refundReserved,
            'checkout_reserved' => round($reserved - $refundReserved, 2),
            'available' => $available,
            'withdrawal_credit' => $withdrawalCredit,
            'refundable' => round(max(0, $available - $withdrawalCredit), 2),
            'entries' => $rows
                ->where('type', AccountCredit::TYPE_CANCELLATION)
                ->where('status', AccountCredit::STATUS_POSTED)
                ->values(),
        ];
    }

    /**
     * How much of this fee can be covered from the payer's credit, putting
     * back any hold already sitting on this same entry (a retry replaces it).
     *
     * @return array{fee: float, credit: float, card: float}
     */
    public function preview(User $payer, MatchRegistration $registration): array
    {
        $fee = round((float) $registration->fee_amount, 2);
        $usable = round($this->summary($payer)['available'] + $this->heldForRegistration($payer, $registration), 2);
        $credit = round(min(max($usable, 0), max($fee, 0)), 2);

        return [
            'fee' => $fee,
            'credit' => $credit,
            'card' => round(max(0, $fee - $credit), 2),
        ];
    }

    /**
     * Credit every still-entered, paid, non-walk-in registration on a
     * cancelled match. The credit lands on the account that actually paid
     * (the completed payment's user), so a parent who paid for a child
     * receives both fees. Safe to run more than once.
     *
     * @return Collection<int, AccountCredit>
     */
    public function issueForCancelledMatch(MatchEvent $match, ?User $actor = null): Collection
    {
        if ($match->status !== 'cancelled') {
            return collect();
        }

        $issued = DB::transaction(function () use ($match, $actor) {
            $registrations = MatchRegistration::query()
                ->where('match_id', $match->id)
                ->where('payment_status', 'paid')
                ->where('registration_status', '!=', 'cancelled')
                ->where('registration_source', '!=', 'walk_in')
                ->where('fee_amount', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $registrations->load([
                'payments' => fn ($query) => $query->where('status', 'completed')->orderByDesc('id'),
            ]);

            $created = collect();

            foreach ($registrations as $registration) {
                if (AccountCredit::query()->where('issued_for_registration_id', $registration->id)->exists()) {
                    continue;
                }

                $completed = $registration->payments;
                $holderId = $completed->first()?->user_id
                    ?? $registration->registered_by_user_id
                    ?? $registration->user_id;

                $amount = $completed->isNotEmpty()
                    ? round((float) $completed->sum('amount'), 2)
                    : round((float) $registration->fee_amount, 2);

                if (! $holderId || $amount <= 0) {
                    continue;
                }

                $credit = AccountCredit::query()->create([
                    'user_id' => $holderId,
                    'amount' => $amount,
                    'status' => AccountCredit::STATUS_POSTED,
                    'type' => AccountCredit::TYPE_CANCELLATION,
                    'match_id' => $match->id,
                    'issued_for_registration_id' => $registration->id,
                    'description' => 'Entry credit: '.$match->name.' cancelled ('.$registration->shooter_name.')',
                    'created_by' => $actor?->id,
                ]);
                $credit->setAttribute('shooter_name', $registration->shooter_name);

                $registration->update([
                    'refund_amount' => $amount,
                ]);

                FinancialTransaction::query()->create([
                    'type' => 'refund',
                    'source_type' => 'match_registration',
                    'source_id' => $registration->id,
                    'user_id' => $holderId,
                    'amount' => $amount,
                    'description' => 'Entry credit issued — '.$match->name.' cancelled',
                    'meta' => [
                        'account_credit_id' => $credit->id,
                        'shooter_name' => $registration->shooter_name,
                        'match_id' => $match->id,
                    ],
                ]);

                $this->auditLogService->log(
                    $actor,
                    'registration.cancellation_credit.issued',
                    'MatchRegistration',
                    $registration->id,
                    null,
                    [
                        'account_credit_id' => $credit->id,
                        'credited_user_id' => $holderId,
                        'amount' => $amount,
                        'match_id' => $match->id,
                    ],
                    'Match cancelled — paid entry credited to the payer',
                );

                $created->push($credit);
            }

            return $created;
        });

        $this->notifyPayers($match, $issued);

        return $issued;
    }

    /**
     * Credit the payer when staff remove a single paid entry from a match
     * that is still going ahead. The full amount paid (card + any credit
     * already redeemed against it) goes back on the paying account.
     * Returns null when nothing was paid on the platform or the entry
     * was already credited.
     */
    public function issueForRemovedRegistration(MatchRegistration $registration, string $reason, ?User $actor = null): ?AccountCredit
    {
        $registration->loadMissing('match');

        $credit = DB::transaction(function () use ($registration, $reason, $actor) {
            MatchRegistration::query()->whereKey($registration->id)->lockForUpdate()->first();

            if (AccountCredit::query()->where('issued_for_registration_id', $registration->id)->exists()) {
                return null;
            }

            $amount = $registration->amountPaidOnPlatform();
            $holderId = $registration->platformPayerId();

            if (! $holderId || $amount <= 0) {
                return null;
            }

            $matchName = $registration->match?->name ?? 'event';

            $credit = AccountCredit::query()->create([
                'user_id' => $holderId,
                'amount' => $amount,
                'status' => AccountCredit::STATUS_POSTED,
                'type' => AccountCredit::TYPE_CANCELLATION,
                'match_id' => $registration->match_id,
                'issued_for_registration_id' => $registration->id,
                'description' => 'Entry credit: removed from '.$matchName.' ('.$registration->shooter_name.')',
                'created_by' => $actor?->id,
            ]);

            $registration->update([
                'refund_amount' => $amount,
                'admin_fee_charged' => 0,
            ]);

            FinancialTransaction::query()->create([
                'type' => 'refund',
                'source_type' => 'match_registration',
                'source_id' => $registration->id,
                'user_id' => $holderId,
                'amount' => $amount,
                'description' => 'Entry credit issued — removed from '.$matchName,
                'meta' => [
                    'account_credit_id' => $credit->id,
                    'shooter_name' => $registration->shooter_name,
                    'match_id' => $registration->match_id,
                    'reason' => $reason,
                ],
            ]);

            $this->auditLogService->log(
                $actor,
                'registration.removal_credit.issued',
                'MatchRegistration',
                $registration->id,
                null,
                [
                    'account_credit_id' => $credit->id,
                    'credited_user_id' => $holderId,
                    'amount' => $amount,
                    'match_id' => $registration->match_id,
                ],
                $reason,
            );

            return $credit;
        });

        if ($credit) {
            $this->notifyRemovalCredit($registration, $credit, $reason);
        }

        return $credit;
    }

    /**
     * Member withdrew before the deadline and kept the money as credit: the
     * full amount paid goes back on the paying account with no fee. The
     * PayFast fee already paid on this entry rides along with the credit and
     * is charged to whichever entries the credit is later spent on.
     *
     * Entries paid off the platform (imported from the previous site) are
     * credited at the entry fee to the shooter, with no PayFast fee carried.
     */
    public function issueForWithdrawal(MatchRegistration $registration, ?User $actor = null): ?AccountCredit
    {
        $registration->loadMissing('match');

        return DB::transaction(function () use ($registration, $actor) {
            MatchRegistration::query()->whereKey($registration->id)->lockForUpdate()->first();

            if (AccountCredit::query()->where('issued_for_registration_id', $registration->id)->exists()) {
                return null;
            }

            $paidOnPlatform = $registration->amountPaidOnPlatform();
            $offPlatform = $paidOnPlatform <= 0;

            if ($offPlatform && ! in_array($registration->payment_status, ['paid', 'waived'], true)) {
                return null;
            }

            $amount = $offPlatform ? round((float) $registration->fee_amount, 2) : $paidOnPlatform;
            $holderId = $registration->withdrawalCreditHolderId();
            $gatewayFee = $offPlatform ? 0.0 : round(min((float) $registration->gateway_fee, $amount), 2);

            if (! $holderId || $amount <= 0) {
                return null;
            }

            $matchName = $registration->match?->name ?? 'event';

            $credit = AccountCredit::query()->create([
                'user_id' => $holderId,
                'amount' => $amount,
                'gateway_fee' => $gatewayFee,
                'status' => AccountCredit::STATUS_POSTED,
                'type' => AccountCredit::TYPE_WITHDRAWAL,
                'match_id' => $registration->match_id,
                'issued_for_registration_id' => $registration->id,
                'description' => 'Entry credit: withdrew from '.$matchName.' ('.$registration->shooter_name.')',
                'created_by' => $actor?->id,
            ]);

            FinancialTransaction::query()->create([
                'type' => 'refund',
                'source_type' => 'match_registration',
                'source_id' => $registration->id,
                'user_id' => $holderId,
                'amount' => $amount,
                'description' => 'Entry credit issued — member withdrew from '.$matchName,
                'meta' => [
                    'account_credit_id' => $credit->id,
                    'shooter_name' => $registration->shooter_name,
                    'match_id' => $registration->match_id,
                    'gateway_fee_carried' => (float) $credit->gateway_fee,
                    'paid_off_platform' => $offPlatform,
                ],
            ]);

            $this->auditLogService->log(
                $actor,
                'registration.withdrawal_credit.issued',
                'MatchRegistration',
                $registration->id,
                null,
                [
                    'account_credit_id' => $credit->id,
                    'credited_user_id' => $holderId,
                    'amount' => $amount,
                    'gateway_fee_carried' => (float) $credit->gateway_fee,
                    'paid_off_platform' => $offPlatform,
                    'match_id' => $registration->match_id,
                ],
                'Member withdrew and kept the entry fee as credit',
            );

            return $credit;
        });
    }

    /**
     * Unspent credit from withdrawals, and the PayFast fee still riding on it.
     * Redemptions draw on withdrawal credit first.
     *
     * @return array{amount: float, gateway_fee: float}
     */
    public function withdrawalCreditBalance(User $user): array
    {
        $issued = AccountCredit::query()
            ->where('user_id', $user->id)
            ->where('type', AccountCredit::TYPE_WITHDRAWAL)
            ->where('status', AccountCredit::STATUS_POSTED)
            ->selectRaw('COALESCE(SUM(amount), 0) as amount, COALESCE(SUM(gateway_fee), 0) as gateway_fee')
            ->first();

        $drawn = AccountCredit::query()
            ->where('user_id', $user->id)
            ->where('type', AccountCredit::TYPE_REDEMPTION)
            ->whereIn('status', [AccountCredit::STATUS_POSTED, AccountCredit::STATUS_RESERVED])
            ->selectRaw('COALESCE(SUM(withdrawal_credit_amount), 0) as amount, COALESCE(SUM(gateway_fee), 0) as gateway_fee')
            ->first();

        return [
            'amount' => round(max(0, (float) $issued->amount - (float) $drawn->amount), 2),
            'gateway_fee' => round(max(0, (float) $issued->gateway_fee - (float) $drawn->gateway_fee), 2),
        ];
    }

    /**
     * PayFast fee from withdrawal credit that this payment's redemption
     * carried onto the entry.
     */
    public function carriedGatewayFeeForPayment(Payment $payment): float
    {
        return round((float) AccountCredit::query()
            ->where('payment_id', $payment->id)
            ->where('type', AccountCredit::TYPE_REDEMPTION)
            ->where('status', AccountCredit::STATUS_POSTED)
            ->sum('gateway_fee'), 2);
    }

    /**
     * Hold credit against this entry and open the payment that settles it.
     * A full cover completes immediately (gateway account_credit). A partial
     * cover leaves a PayFast payment for the remainder and a reserved hold.
     */
    public function applyToRegistrationPayment(User $payer, MatchRegistration $registration): Payment
    {
        $registration->loadMissing('match');

        return DB::transaction(function () use ($payer, $registration) {
            User::query()->whereKey($payer->id)->lockForUpdate()->first();

            $this->releaseHoldsForRegistration($payer, $registration);

            $fee = round((float) $registration->fee_amount, 2);
            $available = $this->summary($payer)['available'];
            $credit = round(min(max($available, 0), max($fee, 0)), 2);
            $card = round(max(0, $fee - $credit), 2);
            $coveredByCredit = $card <= 0 && $credit > 0;

            $reservation = null;
            if ($credit > 0) {
                $withdrawal = $this->withdrawalCreditBalance($payer);
                $fromWithdrawal = round(min($credit, $withdrawal['amount']), 2);
                $carriedGatewayFee = match (true) {
                    $fromWithdrawal <= 0 => 0.0,
                    $fromWithdrawal >= $withdrawal['amount'] => $withdrawal['gateway_fee'],
                    default => round($withdrawal['gateway_fee'] * $fromWithdrawal / $withdrawal['amount'], 2),
                };

                $matchName = $registration->match?->name ?? 'event';
                $reservation = AccountCredit::query()->create([
                    'user_id' => $payer->id,
                    'amount' => -$credit,
                    'gateway_fee' => $carriedGatewayFee,
                    'withdrawal_credit_amount' => $fromWithdrawal,
                    'status' => AccountCredit::STATUS_RESERVED,
                    'type' => AccountCredit::TYPE_REDEMPTION,
                    'match_id' => $registration->match_id,
                    'applied_to_registration_id' => $registration->id,
                    'description' => 'Entry credit applied to '.$matchName.' ('.$registration->shooter_name.')',
                ]);
            }

            $payment = Payment::query()->create([
                'payable_type' => MatchRegistration::class,
                'payable_id' => $registration->id,
                'user_id' => $payer->id,
                'amount' => $coveredByCredit ? $fee : $card,
                'gateway' => $coveredByCredit ? 'account_credit' : 'payfast',
                'm_payment_id' => Payment::generateReference($coveredByCredit ? 'CRD' : 'REG'),
                'status' => $coveredByCredit ? 'completed' : 'pending',
                'paid_at' => $coveredByCredit ? now() : null,
                'amount_fee' => $coveredByCredit ? 0 : null,
            ]);

            $reservation?->update(['payment_id' => $payment->id]);

            return $payment;
        });
    }

    /**
     * Turn a reserved hold into a posted redemption once its payment has
     * succeeded. Returns the credit amount captured.
     */
    public function captureReservationForPayment(Payment $payment): float
    {
        $rows = AccountCredit::query()
            ->where('payment_id', $payment->id)
            ->where('status', AccountCredit::STATUS_RESERVED)
            ->get();

        $captured = 0.0;

        foreach ($rows as $row) {
            $row->update(['status' => AccountCredit::STATUS_POSTED]);
            $captured += abs((float) $row->amount);

            if ($payment->gateway !== 'account_credit') {
                FinancialTransaction::query()->create([
                    'type' => 'payment',
                    'source_type' => 'match_registration',
                    'source_id' => $payment->payable_id,
                    'user_id' => $row->user_id,
                    'amount' => abs((float) $row->amount),
                    'description' => 'Match registration paid from account credit',
                    'meta' => [
                        'account_credit_id' => $row->id,
                        'payment_id' => $payment->id,
                        'gateway' => 'account_credit',
                    ],
                ]);
            }
        }

        return round($captured, 2);
    }

    public function releaseReservationForPayment(Payment $payment): void
    {
        AccountCredit::query()
            ->where('payment_id', $payment->id)
            ->where('status', AccountCredit::STATUS_RESERVED)
            ->update(['status' => AccountCredit::STATUS_VOIDED]);
    }

    /**
     * @param  Collection<int, AccountCredit>  $issued
     */
    private function notifyPayers(MatchEvent $match, Collection $issued): void
    {
        if ($issued->isEmpty()) {
            return;
        }

        $issued->groupBy('user_id')->each(function (Collection $credits, int|string $userId) use ($match) {
            $user = User::query()->find($userId);
            if (! $user) {
                return;
            }

            $lines = $credits->map(fn (AccountCredit $credit) => [
                'shooter' => (string) ($credit->getAttribute('shooter_name') ?: 'Entry'),
                'amount' => (float) $credit->amount,
            ])->values()->all();

            try {
                $user->notify(new MatchCancellationCreditNotification(
                    $match->name,
                    round((float) $credits->sum('amount'), 2),
                    $this->summary($user)['posted'],
                    $lines,
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to send match cancellation credit notification', [
                    'user_id' => $user->id,
                    'match_id' => $match->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function notifyRemovalCredit(MatchRegistration $registration, AccountCredit $credit, string $reason): void
    {
        $user = User::query()->find($credit->user_id);
        if (! $user) {
            return;
        }

        try {
            $user->notify(new EntryRemovedCreditNotification(
                $registration->match?->name ?? 'event',
                (string) $registration->shooter_name,
                (float) $credit->amount,
                $this->summary($user)['posted'],
                $reason,
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to send entry removal credit notification', [
                'user_id' => $user->id,
                'registration_id' => $registration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function heldForRegistration(User $payer, MatchRegistration $registration): float
    {
        return round((float) AccountCredit::query()
            ->where('user_id', $payer->id)
            ->where('applied_to_registration_id', $registration->id)
            ->where('status', AccountCredit::STATUS_RESERVED)
            ->get()
            ->sum(fn (AccountCredit $row) => abs((float) $row->amount)), 2);
    }

    private function releaseHoldsForRegistration(User $payer, MatchRegistration $registration): void
    {
        $holds = AccountCredit::query()
            ->where('user_id', $payer->id)
            ->where('applied_to_registration_id', $registration->id)
            ->where('status', AccountCredit::STATUS_RESERVED)
            ->get();

        foreach ($holds as $hold) {
            if ($hold->payment && $hold->payment->isPending()) {
                $hold->payment->update(['status' => 'cancelled']);
            }
            $hold->update(['status' => AccountCredit::STATUS_VOIDED]);
        }
    }
}
