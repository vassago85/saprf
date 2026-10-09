<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class EntryRemovedCreditNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $matchName,
        private readonly string $shooterName,
        private readonly float $amount,
        private readonly float $balance,
        private readonly string $reason,
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
            ->subject('Entry credit: '.$this->shooterName.' removed from '.$this->matchName)
            ->greeting('Hi '.$notifiable->name.',')
            ->line($this->shooterName.' has been removed from '.$this->matchName.' by the match organisers. The entry fee you paid has been added to your SAPRF account as credit.')
            ->line('**Reason:** '.$this->reason)
            ->line('**Credited now:** R'.number_format($this->amount, 2))
            ->line('**Credit on your account:** R'.number_format($this->balance, 2))
            ->line('This credit is applied automatically the next time you pay for an event.')
            ->action('View my dashboard', route('dashboard'));
    }
}
