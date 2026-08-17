<?php

namespace App\Notifications;

use App\Models\Membership;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class MembershipLapsedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Membership $membership,
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
        return (new MailMessage)
            ->subject('Your SAPRF Membership Has Lapsed')
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line('Your SAPRF membership has now lapsed.')
            ->line('**Member Number:** ' . $this->membership->saprf_number)
            ->line('**Expired On:** ' . $this->membership->expiry_date?->format('d F Y'))
            ->line('Lapsed members can still register for matches but will be charged the lapsed-member surcharge and scores will not count toward season standings until membership is reinstated.')
            ->action('Renew My Membership', route('my-membership'))
            ->line('It only takes a minute to renew online.');
    }
}
