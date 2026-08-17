<?php

namespace App\Notifications;

use App\Models\Membership;
use App\Models\MembershipPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class MembershipConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Membership $membership,
        private readonly ?MembershipPayment $payment = null,
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
        $message = (new MailMessage)
            ->subject('Your SAPRF Membership is Active')
            ->greeting('Welcome, ' . $notifiable->name . '!')
            ->line('Your SAPRF membership has been activated. Thank you for supporting precision rifle shooting in South Africa.')
            ->line('**Member Number:** ' . $this->membership->saprf_number)
            ->line('**Valid Until:** ' . $this->membership->expiry_date?->format('d F Y'));

        if ($this->payment) {
            $message
                ->line('**Amount Paid:** R' . number_format((float) $this->payment->amount, 2))
                ->line('**Payment Reference:** ' . $this->payment->payment_reference);
        }

        return $message
            ->action('View My Membership', route('my-membership'))
            ->line('Your membership card and certificate are available in your dashboard.')
            ->line('If you have any questions, simply reply to this email.');
    }
}
