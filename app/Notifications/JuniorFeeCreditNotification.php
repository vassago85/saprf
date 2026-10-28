<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class JuniorFeeCreditNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $matchName,
        private readonly string $shooterName,
        private readonly float $amount,
        private readonly float $juniorFee,
        private readonly float $balance,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function middleware(): array
    {
        return [new RateLimited('mail')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Junior fee credit: '.$this->matchName)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('The junior entry fee for '.$this->matchName.' has been reduced to R'.number_format($this->juniorFee, 2).'. The difference paid for '.$this->shooterName.' has been added to your SAPRF account as credit.')
            ->line('**Credited now:** R'.number_format($this->amount, 2))
            ->line('**Credit on your account:** R'.number_format($this->balance, 2))
            ->line('This credit is applied automatically the next time you pay for an event.')
            ->action('View my dashboard', route('dashboard'));
    }
}
