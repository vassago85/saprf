<?php

namespace App\Notifications;

use App\Models\MatchRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MatchRegistrationConfirmedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly MatchRegistration $registration,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $registration = $this->registration->loadMissing('match.province');
        $match = $registration->match;
        $isWaitlist = $registration->registration_status === 'waitlisted';

        $subject = $isWaitlist
            ? 'You are on the waitlist for ' . $match->name
            : 'Registration Confirmed: ' . $match->name;

        $intro = $isWaitlist
            ? 'You have been added to the waitlist for the following match. We will notify you if a spot becomes available.'
            : 'Your registration has been received. See you on the range!';

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line($intro)
            ->line('**Match:** ' . $match->name)
            ->line('**Date:** ' . $match->formatted_date)
            ->line('**Venue:** ' . trim(($match->venue_name ?: '—') . ', ' . ($match->location_display ?: '')))
            ->line('**Discipline:** ' . $match->match_type . ' &middot; ' . ucfirst((string) $match->series_level));

        if ((float) $registration->fee_amount > 0) {
            $paymentLabel = $registration->payment_status === 'paid' ? 'Paid' : 'Outstanding';
            $message->line('**Entry Fee:** R' . number_format((float) $registration->fee_amount, 2) . ' (' . $paymentLabel . ')');
        }

        if ($registration->payment_status !== 'paid' && (float) $registration->fee_amount > 0) {
            $message->line('Please complete payment as soon as possible to confirm your spot.');
        }

        return $message
            ->action('View Registration', route('registrations.show', $registration))
            ->line('You can update your rifle selection or withdraw at any time before the registration close date from your dashboard.');
    }
}
