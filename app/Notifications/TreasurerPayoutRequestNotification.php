<?php

namespace App\Notifications;

use App\Models\Payout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Delayed notice to the Exco treasurer that a match director has asked
 * SAPRF to pay out a match. Queued two hours after the request so a
 * payout that is settled immediately does not generate a stale email.
 */
class TreasurerPayoutRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Payout $payout,
    ) {}

    public function via(object $notifiable): array
    {
        $payout = $this->payout->fresh();

        if ($payout === null || $payout->status !== 'pending') {
            return [];
        }

        return ['mail'];
    }

    public function middleware(): array
    {
        return [new RateLimited('mail')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payout = $this->payout->fresh(['match', 'payeeUser']);
        $matchName = $payout->match?->name ?? 'a match';
        $director = $payout->payeeUser?->name ?? 'The match director';

        return (new MailMessage)
            ->subject("Payout request waiting: {$payout->reference}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$director} requested a payout for **{$matchName}**.")
            ->line('**Net due:** R'.number_format((float) $payout->net_amount, 2))
            ->line('**Reference:** '.$payout->reference)
            ->action('Review payout requests', route('financials.payouts'))
            ->line('This note was sent two hours after the request, and only while the payout is still waiting to be paid.');
    }
}
