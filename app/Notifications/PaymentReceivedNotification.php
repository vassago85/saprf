<?php

namespace App\Notifications;

use App\Models\MatchRegistration;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Payment $payment,
        private readonly string $context,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Route every send through the shared "mail" limiter (50/hour, 2/min).
     * Auth-critical mail (OTP, password reset) skips this limiter.
     */
    public function middleware(): array
    {
        return [new RateLimited('mail')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $context = $this->context;
        $isMembership = $context === 'membership';

        $subject = $isMembership
            ? 'Payment Received: SAPRF Membership'
            : 'Payment Received: Match Registration';

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Hi '.$notifiable->name.',')
            ->line($this->payment->gateway === 'account_credit'
                ? 'Thank you. This entry was paid from the credit on your account.'
                : 'Thank you. We\'ve received your payment.')
            ->line('**Amount:** R'.number_format((float) $this->payment->amount, 2))
            ->line('**Reference:** '.$this->payment->m_payment_id)
            ->line('**Date:** '.$this->payment->paid_at?->format('d F Y, H:i'));

        if ($this->payment->gateway_payment_id) {
            $message->line('**Gateway ID:** '.$this->payment->gateway_payment_id);
        }

        if (! $isMembership) {
            $inviteUrl = $this->matchWhatsappInviteUrl();
            if ($inviteUrl) {
                $message->line('Join the match WhatsApp group for notifications and match books:')
                    ->line($inviteUrl);
            }
        }

        // Direct link to the PDF invoice. The route re-runs the ownership
        // check so a forwarded email can't be used by a third party.
        $message->action(
            'Download Invoice (PDF)',
            route('payments.invoice', $this->payment),
        );

        return $message
            ->line('You can also view this payment any time under '
                .'[My Invoices]('.route('invoices.index').').')
            ->line('Keep this email as your proof of payment.');
    }

    private function matchWhatsappInviteUrl(): ?string
    {
        $this->payment->loadMissing('payable.match');

        $registration = $this->payment->payable;

        if (! $registration instanceof MatchRegistration) {
            return null;
        }

        return $registration->whatsappInviteUrlAfterPayment();
    }
}
