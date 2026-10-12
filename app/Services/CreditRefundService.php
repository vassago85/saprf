<?php

namespace App\Services;

use App\Models\AccountCredit;
use App\Models\CreditRefundRequest;
use App\Models\FinancialTransaction;
use App\Models\MatchRegistration;
use App\Models\User;
use App\Notifications\CreditRefundRequestedNotification;
use App\Notifications\CreditRefundRequestedStaffNotification;
use App\Notifications\CreditRefundResolvedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class CreditRefundService
{
    public function __construct(
        private readonly AccountCreditService $credits,
        private readonly SettingsService $settings,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->cancellationRefundsEnabled();
    }

    /**
     * @return array<string, list<string>>
     */
    public static function bankDetailRules(): array
    {
        return [
            'account_holder' => ['required', 'string', 'max:255'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'max:30'],
            'branch_code' => ['required', 'string', 'max:12'],
            'member_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Strip spaces from validated bank details and check the numbers are digits.
     *
     * @param  array<string, mixed>  $validated
     * @return array{account_holder: string, bank_name: string, account_number: string, branch_code: string, member_note: ?string}
     */
    public static function normaliseBankDetails(array $validated): array
    {
        $accountNumber = preg_replace('/\s+/', '', (string) $validated['account_number']) ?? '';
        $branchCode = preg_replace('/\s+/', '', (string) $validated['branch_code']) ?? '';

        if (! preg_match('/^\d{6,16}$/', $accountNumber)) {
            throw ValidationException::withMessages([
                'account_number' => 'Enter the account number as digits only.',
            ]);
        }

        if (! preg_match('/^\d{4,10}$/', $branchCode)) {
            throw ValidationException::withMessages([
                'branch_code' => 'Enter the branch code as digits.',
            ]);
        }

        return [
            'account_holder' => $validated['account_holder'],
            'bank_name' => $validated['bank_name'],
            'account_number' => $accountNumber,
            'branch_code' => $branchCode,
            'member_note' => $validated['member_note'] ?? null,
        ];
    }

    public function pendingFor(User $user): ?CreditRefundRequest
    {
        return CreditRefundRequest::query()
            ->where('user_id', $user->id)
            ->where('status', CreditRefundRequest::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * Hold credit and open a cash-refund request. The match was cancelled,
     * so the amount is the full figure the member asks for — no admin fee.
     *
     * @param  array{account_holder: string, bank_name: string, account_number: string, branch_code: string, member_note?: ?string}  $bank
     */
    public function request(User $user, float $amount, array $bank): CreditRefundRequest
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages([
                'amount' => 'Refund requests are not open yet.',
            ]);
        }

        $amount = round($amount, 2);

        $refund = DB::transaction(function () use ($user, $amount, $bank) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($this->pendingFor($user)) {
                throw ValidationException::withMessages([
                    'amount' => 'You already have a refund request waiting to be paid.',
                ]);
            }

            $refundable = $this->credits->summary($user)['refundable'];
            if ($amount <= 0 || $amount > $refundable) {
                throw ValidationException::withMessages([
                    'amount' => 'Enter an amount up to the credit available for a refund (R '.number_format($refundable, 2).').',
                ]);
            }

            $hold = AccountCredit::query()->create([
                'user_id' => $user->id,
                'amount' => -$amount,
                'status' => AccountCredit::STATUS_RESERVED,
                'type' => AccountCredit::TYPE_REFUND,
                'description' => 'Held for a cash refund of cancellation credit',
            ]);

            $refund = CreditRefundRequest::query()->create([
                'user_id' => $user->id,
                'account_credit_id' => $hold->id,
                'amount' => $amount,
                'status' => CreditRefundRequest::STATUS_PENDING,
                'account_holder' => $bank['account_holder'],
                'bank_name' => $bank['bank_name'],
                'account_number' => $bank['account_number'],
                'branch_code' => $bank['branch_code'],
                'member_note' => $bank['member_note'] ?? null,
            ]);

            $this->auditLogService->log(
                $user,
                'credit_refund.requested',
                'CreditRefundRequest',
                $refund->id,
                null,
                [
                    'amount' => $amount,
                    'account_credit_id' => $hold->id,
                ],
                'Member requested a cash refund of cancellation credit',
            );

            return $refund;
        });

        $this->notifyRequested($refund);

        return $refund;
    }

    /**
     * Member withdrew before the deadline and asked for the money back in
     * cash. The withdrawal fee is already off `$amount`; post it as credit
     * and hold it straight away so it lands in the finance refund queue.
     * Not gated by cancellation_refunds_enabled — the fee is the price of
     * a cash refund here.
     *
     * @param  array{account_holder: string, bank_name: string, account_number: string, branch_code: string, member_note?: ?string}  $bank
     */
    public function requestForWithdrawal(MatchRegistration $registration, User $holder, float $amount, array $bank): CreditRefundRequest
    {
        $amount = round($amount, 2);
        $registration->loadMissing('match');
        $matchName = $registration->match?->name ?? 'event';

        $refund = DB::transaction(function () use ($registration, $holder, $amount, $bank, $matchName) {
            User::query()->whereKey($holder->id)->lockForUpdate()->first();

            AccountCredit::query()->create([
                'user_id' => $holder->id,
                'amount' => $amount,
                'status' => AccountCredit::STATUS_POSTED,
                'type' => AccountCredit::TYPE_WITHDRAWAL_REFUND,
                'match_id' => $registration->match_id,
                'issued_for_registration_id' => $registration->id,
                'description' => 'Withdrawal refund: '.$matchName.' ('.$registration->shooter_name.')',
            ]);

            $hold = AccountCredit::query()->create([
                'user_id' => $holder->id,
                'amount' => -$amount,
                'status' => AccountCredit::STATUS_RESERVED,
                'type' => AccountCredit::TYPE_REFUND,
                'match_id' => $registration->match_id,
                'description' => 'Held for a cash refund after withdrawing from '.$matchName,
            ]);

            $refund = CreditRefundRequest::query()->create([
                'user_id' => $holder->id,
                'account_credit_id' => $hold->id,
                'match_registration_id' => $registration->id,
                'amount' => $amount,
                'status' => CreditRefundRequest::STATUS_PENDING,
                'account_holder' => $bank['account_holder'],
                'bank_name' => $bank['bank_name'],
                'account_number' => $bank['account_number'],
                'branch_code' => $bank['branch_code'],
                'member_note' => $bank['member_note'] ?? null,
            ]);

            $this->auditLogService->log(
                $holder,
                'credit_refund.requested',
                'CreditRefundRequest',
                $refund->id,
                null,
                [
                    'amount' => $amount,
                    'account_credit_id' => $hold->id,
                    'match_registration_id' => $registration->id,
                ],
                'Member withdrew from a match and asked for a cash refund',
            );

            return $refund;
        });

        DB::afterCommit(fn () => $this->notifyRequested($refund));

        return $refund;
    }

    public function markPaid(CreditRefundRequest $refund, User $actor, string $reference): void
    {
        if (! $refund->isPending()) {
            throw ValidationException::withMessages([
                'payment_reference' => 'This request is no longer waiting to be paid.',
            ]);
        }

        DB::transaction(function () use ($refund, $actor, $reference) {
            $refund->refresh();
            if (! $refund->isPending()) {
                throw ValidationException::withMessages([
                    'payment_reference' => 'This request is no longer waiting to be paid.',
                ]);
            }

            $refund->hold?->update(['status' => AccountCredit::STATUS_POSTED]);

            $refund->update([
                'status' => CreditRefundRequest::STATUS_PAID,
                'payment_reference' => $reference,
                'paid_at' => now(),
                'paid_by' => $actor->id,
            ]);

            FinancialTransaction::query()->create([
                'type' => 'payout',
                'source_type' => 'credit_refund_request',
                'source_id' => $refund->id,
                'user_id' => $refund->user_id,
                'amount' => $refund->amount,
                'description' => $refund->match_registration_id
                    ? 'Cash refund after member withdrawal'
                    : 'Cash refund of cancellation credit',
                'meta' => [
                    'payment_reference' => $reference,
                    'account_credit_id' => $refund->account_credit_id,
                ],
            ]);

            $this->auditLogService->log(
                $actor,
                'credit_refund.paid',
                'CreditRefundRequest',
                $refund->id,
                ['status' => CreditRefundRequest::STATUS_PENDING],
                [
                    'status' => CreditRefundRequest::STATUS_PAID,
                    'amount' => (float) $refund->amount,
                    'payment_reference' => $reference,
                ],
                'Cancellation credit paid out as a cash refund',
            );
        });

        $this->notifyResolved($refund->fresh(['user']));
    }

    public function decline(CreditRefundRequest $refund, User $actor, string $reason): void
    {
        if (! $refund->isPending()) {
            throw ValidationException::withMessages([
                'decline_reason' => 'This request is no longer waiting to be paid.',
            ]);
        }

        DB::transaction(function () use ($refund, $actor, $reason) {
            $refund->refresh();
            if (! $refund->isPending()) {
                throw ValidationException::withMessages([
                    'decline_reason' => 'This request is no longer waiting to be paid.',
                ]);
            }

            $refund->hold?->update(['status' => AccountCredit::STATUS_VOIDED]);

            $refund->update([
                'status' => CreditRefundRequest::STATUS_DECLINED,
                'decline_reason' => $reason,
                'declined_at' => now(),
                'declined_by' => $actor->id,
            ]);

            $this->auditLogService->log(
                $actor,
                'credit_refund.declined',
                'CreditRefundRequest',
                $refund->id,
                ['status' => CreditRefundRequest::STATUS_PENDING],
                [
                    'status' => CreditRefundRequest::STATUS_DECLINED,
                    'amount' => (float) $refund->amount,
                ],
                $reason,
            );
        });

        $this->notifyResolved($refund->fresh(['user']));
    }

    private function notifyRequested(CreditRefundRequest $refund): void
    {
        $refund->loadMissing('user');

        if ($refund->user) {
            try {
                $refund->user->notify(new CreditRefundRequestedNotification($refund));
            } catch (\Throwable $e) {
                Log::warning('Failed to send refund request confirmation', [
                    'refund_id' => $refund->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $addresses = array_unique(array_filter([
            $this->settings->secretaryEmail(),
            $this->settings->ownerEmail(),
        ]));

        foreach ($addresses as $address) {
            try {
                Notification::route('mail', $address)
                    ->notify(new CreditRefundRequestedStaffNotification($refund));
            } catch (\Throwable $e) {
                Log::warning('Failed to send refund request to staff', [
                    'refund_id' => $refund->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyResolved(CreditRefundRequest $refund): void
    {
        if (! $refund->user) {
            return;
        }

        try {
            $refund->user->notify(new CreditRefundResolvedNotification($refund));
        } catch (\Throwable $e) {
            Log::warning('Failed to send refund outcome', [
                'refund_id' => $refund->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
