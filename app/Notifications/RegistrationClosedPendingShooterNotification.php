<?php

namespace App\Notifications;

use App\Models\MatchRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Sent to a shooter whose entry is still pending / unpaid when match
 * registration closes. Offers Pay now or withdraw from the entry page.
 */
class RegistrationClosedPendingShooterNotification extends Notification implements ShouldQueue
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
        $registration = $this->registration->loadMissing(['match', 'division']);
        $match = $registration->match;
        $fee = number_format((float) $registration->fee_amount, 2);
        $name = $notifiable->name ?? $registration->shooter_name ?? 'there';
        $entryUrl = route('registrations.show', $registration);
        $close = $match?->registration_close_date?->format('d M Y') ?? 'now';

        $message = (new MailMessage)
            ->subject('Action needed — '.$match->name.' registration has closed')
            ->greeting('Hi '.$name.',')
            ->line('Registration for **'.$match->name.'** closed on **'.$close.'**, and your entry is still **pending / unpaid** (R'.$fee.').')
            ->line('Please choose one of the following:')
            ->line('**1. Pay now** — complete payment on the portal with PayFast so your place is confirmed.')
            ->action('Pay or manage entry', $entryUrl)
            ->line('**2. Withdraw** — if you cannot shoot, open your entry and withdraw so the match director can free the slot.')
            ->line('Leaving the entry unpaid and pending may mean you lose your place.');

        $md = $match?->creator;
        if ($md?->email) {
            $message->replyTo($md->email, $md->name);
            $message->line('Questions? Reply to this email — it goes to your match director ('.$md->name.').');
        }

        return $message->salutation('SAPRF Platform');
    }
}
