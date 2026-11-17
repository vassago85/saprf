<?php

namespace App\Notifications;

use App\Models\MatchRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Tells one side of a staff fee transfer what happened.
 *
 * `received` goes to the member whose existing entry was paid.
 * `sent` goes to the member whose fee was moved away.
 */
class FeeTransferNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly MatchRegistration $registration,
        private readonly string $counterpartyName,
        private readonly float $amount,
        private readonly string $direction,
        private readonly bool $withdrawn = false,
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
        $registration = $this->registration->loadMissing('match');
        $match = $registration->match;
        $matchName = $match?->name ?? 'the match';
        $when = $match?->match_date?->format('d M Y');
        $fee = 'R'.number_format($this->amount, 2);
        $received = $this->direction === 'received';

        $subject = $received
            ? 'Entry fee transferred to you: '.$matchName
            : 'Your entry fee was transferred: '.$matchName;

        $what = $received
            ? 'The entry fee of '.$fee.' for '.$matchName.' was transferred to you from '.$this->counterpartyName.'.'
            : 'Your entry fee of '.$fee.' for '.$matchName.' was transferred to '.$this->counterpartyName.'.';

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Hi '.$notifiable->name.',')
            ->line($what);

        if ($when) {
            $message->line('**Match date:** '.$when);
        }

        if ($received) {
            $message->line('Your entry is now paid. The original payer is unchanged, so their invoice still shows the payment.');
        } elseif ($this->withdrawn) {
            $message->line('This entry has been withdrawn. You are no longer entered in the match, and no refund is due because the fee now sits on '.$this->counterpartyName.'\'s entry.');
        } else {
            $message->line('This entry is now unpaid. Pay it if you are still shooting, or withdraw from the registration page.');
        }

        return $message->action('View registration', route('registrations.show', $registration));
    }
}
