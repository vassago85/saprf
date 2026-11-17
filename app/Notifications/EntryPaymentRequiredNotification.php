<?php

namespace App\Notifications;

use App\Models\MatchRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Sent when staff add a shooter after the fact and the fee is still due.
 * The shooter chooses a division and rifle, then pays.
 */
class EntryPaymentRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly MatchRegistration $registration,
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
        $registration = $this->registration->loadMissing('match', 'division');
        $match = $registration->match;
        $matchName = $match?->name ?? 'the match';
        $fee = number_format((float) $registration->fee_amount, 2);
        $needsDivision = $registration->division_id === null;

        $message = (new MailMessage)
            ->subject('Payment still required: '.$matchName)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('You have been added to '.$matchName.'. Payment is still required.')
            ->line('**Match:** '.$matchName)
            ->line('**Date:** '.($match?->formatted_date ?: '—'));

        if ($needsDivision) {
            $message->line('Choose your division and rifle, then pay the entry fee of **R'.$fee.'** to confirm your place. A junior division uses the junior fee when this match has one.');
        } else {
            $division = $registration->division?->name;
            if ($division) {
                $message->line('**Division:** '.$division);
            }
            $message->line('Your entry fee of **R'.$fee.'** is still outstanding. Set your rifle if you need to, then pay to confirm your place.');
        }

        return $message
            ->action($needsDivision ? 'Choose division and pay' : 'Pay your entry', route('registrations.show', $registration))
            ->line('Your place is not confirmed until the fee is paid.');
    }
}
