<?php

namespace App\Console\Commands;

use App\Models\FinancialTransaction;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\Payment;
use App\Notifications\MembershipConfirmedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Manually mark a PayFast payment complete when the ITN webhook never arrived
 * (common during sandbox testing). Mirrors PaymentController::handleSuccessfulPayment.
 */
class CompletePaymentCommand extends Command
{
    protected $signature = 'payments:complete {reference : m_payment_id e.g. REG-20260723-762FA716}';

    protected $description = 'Mark a pending PayFast payment as completed and update the linked registration/membership';

    public function handle(AuditLogService $auditLogService): int
    {
        $reference = (string) $this->argument('reference');
        $payment = Payment::where('m_payment_id', $reference)->first();

        if (! $payment) {
            $this->error("Payment not found: {$reference}");

            return self::FAILURE;
        }

        if ($payment->isCompleted()) {
            $this->info("Payment {$reference} is already completed.");

            return self::SUCCESS;
        }

        $payment->update([
            'status' => 'completed',
            'paid_at' => now(),
            'gateway_response' => array_merge($payment->gateway_response ?? [], [
                'manually_completed' => true,
                'completed_at' => now()->toIso8601String(),
            ]),
        ]);

        $this->applyPayableSideEffects($payment, $auditLogService);

        $this->info("Payment {$reference} marked completed.");

        return self::SUCCESS;
    }

    private function applyPayableSideEffects(Payment $payment, AuditLogService $auditLogService): void
    {
        $payable = $payment->payable;

        if ($payable instanceof MatchRegistration) {
            $payable->update([
                'payment_status' => 'paid',
                'registration_status' => $payable->registration_status === 'pending' ? 'confirmed' : $payable->registration_status,
            ]);

            $auditLogService->log(
                $payment->user,
                'registration.payment.completed',
                'MatchRegistration',
                $payable->id,
                ['payment_status' => 'unpaid'],
                ['payment_status' => 'paid', 'payment_id' => $payment->id, 'manual' => true],
            );

            FinancialTransaction::create([
                'type' => 'payment',
                'source_type' => 'match_registration',
                'source_id' => $payable->id,
                'user_id' => $payment->user_id,
                'amount' => $payment->amount,
                'description' => 'Match registration payment (manually completed)',
                'meta' => [
                    'payment_id' => $payment->id,
                    'm_payment_id' => $payment->m_payment_id,
                    'manual' => true,
                    'match_id' => $payable->match_id,
                ],
            ]);

            if ($payment->user) {
                try {
                    $payment->user->notify(new PaymentReceivedNotification($payment, 'registration'));
                } catch (\Throwable $e) {
                    Log::warning('Failed to send registration payment notification', ['error' => $e->getMessage()]);
                }
            }
        }

        if ($payable instanceof Membership) {
            // Manually completing a Membership payment must upgrade the row to
            // a paying member, otherwise the record shows "Type: Free" but
            // Payment Status: Paid — same bug as the ITN path had.
            $payable->update([
                'membership_type' => 'paid',
                'payment_status' => 'paid',
                'status' => 'active',
            ]);

            $membershipPayment = MembershipPayment::create([
                'membership_id' => $payable->id,
                'amount' => $payment->amount,
                'payment_date' => now()->toDateString(),
                'payment_reference' => $payment->m_payment_id,
                'payment_method' => 'payfast',
                'status' => 'confirmed',
                'notes' => 'Manually marked paid (ITN missing)',
            ]);

            $auditLogService->log(
                $payment->user,
                'membership.payment.completed',
                'Membership',
                $payable->id,
                null,
                ['payment_status' => 'paid', 'payment_id' => $payment->id, 'manual' => true],
            );

            FinancialTransaction::create([
                'type' => 'payment',
                'source_type' => 'membership',
                'source_id' => $payable->id,
                'user_id' => $payment->user_id,
                'amount' => $payment->amount,
                'description' => 'Membership payment (manually completed)',
                'meta' => [
                    'payment_id' => $payment->id,
                    'm_payment_id' => $payment->m_payment_id,
                    'manual' => true,
                    'saprf_number' => $payable->saprf_number,
                ],
            ]);

            if ($payment->user) {
                try {
                    $payment->user->notify(new MembershipConfirmedNotification($payable, $membershipPayment));
                    $payment->user->notify(new PaymentReceivedNotification($payment, 'membership'));
                } catch (\Throwable $e) {
                    Log::warning('Failed to send membership confirmation notification', ['error' => $e->getMessage()]);
                }
            }
        }
    }
}
