<?php

namespace App\Notifications;

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
     * Route every send through the shared "mail" limiter (5/sec, 300/min)
     * registered in AppServiceProvider::registerMailRateLimiter().
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
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line('Thank you. We\'ve received your payment.')
            ->line('**Amount:** R' . number_format((float) $this->payment->amount, 2))
            ->line('**Reference:** ' . $this->payment->m_payment_id)
            ->line('**Date:** ' . $this->payment->paid_at?->format('d F Y, H:i'));

        if ($this->payment->gateway_payment_id) {
            $message->line('**Gateway ID:** ' . $this->payment->gateway_payment_id);
        }

        return $message
            ->action(
                $isMembership ? 'View My Membership' : 'View My Registrations',
                $isMembership ? route('my-membership') : route('registrations.index'),
            )
            ->line('Keep this email as your proof of payment.');
    }
}
