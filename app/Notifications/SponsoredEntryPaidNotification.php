<?php

namespace App\Notifications;

use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the shooter when another member pays for their previously unpaid
 * match entry. The sponsor themselves still receives the standard
 * {@see PaymentReceivedNotification} receipt.
 */
class SponsoredEntryPaidNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly MatchRegistration $registration,
        private readonly Payment $payment,
        private readonly User $sponsor,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $match = $this->registration->loadMissing('match.province')->match;

        return (new MailMessage)
            ->subject('Your entry has been paid: ' . $match->name)
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line($this->sponsor->name . ' has paid your entry fee for the following match.')
            ->line('**Match:** ' . $match->name)
            ->line('**Date:** ' . $match->formatted_date)
            ->line('**Venue:** ' . trim(($match->venue_name ?: '—') . ', ' . ($match->location_display ?: '')))
            ->line('**Entry Fee Paid:** R' . number_format((float) $this->payment->amount, 2))
            ->action('View Registration', route('registrations.show', $this->registration))
            ->line('Your spot is confirmed. Please thank ' . $this->sponsor->name . ' — you might want to buy them a drink after the match.');
    }
}
