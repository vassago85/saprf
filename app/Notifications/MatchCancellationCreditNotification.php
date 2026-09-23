<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class MatchCancellationCreditNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{shooter: string, amount: float}>  $lines
     */
    public function __construct(
        private readonly string $matchName,
        private readonly float $amount,
        private readonly float $balance,
        private readonly array $lines,
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
        $message = (new MailMessage)
            ->subject('Entry credit: '.$this->matchName.' cancelled')
            ->greeting('Hi '.$notifiable->name.',')
            ->line($this->matchName.' was cancelled. The entry fees you paid have been added to your SAPRF account as credit.')
            ->line('**Credited now:** R'.number_format($this->amount, 2));

        foreach ($this->lines as $line) {
            $message->line('• '.$line['shooter'].' — R'.number_format((float) $line['amount'], 2));
        }

        return $message
            ->line('**Credit on your account:** R'.number_format($this->balance, 2))
            ->line('This credit is applied automatically the next time you pay for an event. If you paid for a family member, the credit is on your account — the one that made the payment.')
            ->action('View my dashboard', route('dashboard'));
    }
}
