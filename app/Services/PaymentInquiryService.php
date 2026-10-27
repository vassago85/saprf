<?php

namespace App\Services;

use App\Models\MatchRegistration;
use App\Models\User;
use App\Notifications\PaymentInquiryNotification;
use Illuminate\Support\Collection;

/**
 * Sends the existing payment-inquiry email to one or many unpaid entries.
 * The 24-hour cooldown on each registration still applies.
 */
class PaymentInquiryService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  Collection<int, MatchRegistration>  $registrations
     */
    public function sendOutstanding(User $actor, Collection $registrations): string
    {
        $sent = 0;
        $cooldown = 0;
        $noEmail = 0;

        foreach ($registrations as $registration) {
            if (! $registration->hasOutstandingPayment() || $registration->registration_status === 'cancelled') {
                continue;
            }

            $shooter = $registration->user;
            if (! $shooter || ! $shooter->email || str_contains((string) $shooter->email, '@saprf.walkin')) {
                $noEmail++;

                continue;
            }

            if (! $registration->canSendPaymentInquiry()) {
                $cooldown++;

                continue;
            }

            try {
                $shooter->notify(new PaymentInquiryNotification($registration, $actor));
            } catch (\Throwable $e) {
                report($e);
                $noEmail++;

                continue;
            }

            $registration->update(['payment_inquiry_sent_at' => now()]);
            $sent++;

            $this->auditLogService->log(
                $actor,
                'registration.payment_inquiry.sent',
                'MatchRegistration',
                $registration->id,
                null,
                [
                    'match_id' => $registration->match_id,
                    'shooter_email' => $shooter->email,
                    'fee_amount' => (float) $registration->fee_amount,
                    'batch' => true,
                ],
            );
        }

        if ($sent === 0 && $cooldown === 0 && $noEmail === 0) {
            return 'No unpaid shooters to email.';
        }

        $parts = [];
        if ($sent > 0) {
            $parts[] = 'Emailed '.$sent.' unpaid '.($sent === 1 ? 'shooter' : 'shooters');
        } else {
            $parts[] = 'No new payment emails sent';
        }
        if ($cooldown > 0) {
            $parts[] = $cooldown.' already emailed in the last 24 hours';
        }
        if ($noEmail > 0) {
            $parts[] = $noEmail.' could not be emailed (no address)';
        }

        return implode('. ', $parts).'.';
    }
}
