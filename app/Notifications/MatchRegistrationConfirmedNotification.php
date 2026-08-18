<?php

namespace App\Notifications;

use App\Models\MatchRegistration;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class MatchRegistrationConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  MatchRegistration  $registration  The entry the mail is about.
     * @param  User|null  $sponsor  When present, the account that entered
     *                              the shooter on their behalf (not the parent
     *                              of a managed junior). Adjusts the copy to
     *                              read "{sponsor} entered you..." so the
     *                              shooter isn't confused by an entry they
     *                              did not create themselves.
     */
    public function __construct(
        private readonly MatchRegistration $registration,
        private readonly ?User $sponsor = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Route every send through the shared "mail" limiter (50/hour, 2/min).
     * Auth-critical mail (OTP, password reset) skips this limiter.
     */
    public function middleware(): array
    {
        return [new RateLimited('mail')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $registration = $this->registration->loadMissing('match.province');
        $match = $registration->match;
        $isWaitlist = $registration->registration_status === 'waitlisted';
        $isSponsored = $this->sponsor !== null && $this->sponsor->id !== $notifiable->id;

        if ($isSponsored) {
            $subject = 'You have been entered in ' . $match->name;
            $intro = $this->sponsor->name . ' has entered you in the following match and is covering the entry fee. See you on the range!';
        } elseif ($isWaitlist) {
            $subject = 'You are on the waitlist for ' . $match->name;
            $intro = 'You have been added to the waitlist for the following match. We will notify you if a spot becomes available.';
        } else {
            $subject = 'Registration Confirmed: ' . $match->name;
            $intro = 'Your registration has been received. See you on the range!';
        }

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line($intro)
            ->line('**Match:** ' . $match->name)
            ->line('**Date:** ' . $match->formatted_date)
            ->line('**Venue:** ' . trim(($match->venue_name ?: '—') . ', ' . ($match->location_display ?: '')))
            ->line('**Discipline:** ' . $match->match_type . ' · ' . ucfirst((string) $match->series_level));

        if ((float) $registration->fee_amount > 0) {
            $paymentLabel = $registration->payment_status === 'paid' ? 'Paid' : 'Outstanding';
            $message->line('**Entry Fee:** R' . number_format((float) $registration->fee_amount, 2) . ' (' . $paymentLabel . ')');
        }

        if (! $isSponsored && $registration->payment_status !== 'paid' && (float) $registration->fee_amount > 0) {
            $message->line('Please complete payment as soon as possible to confirm your spot.');
        }

        $message->action('View Registration', route('registrations.show', $registration));

        if ($isSponsored) {
            $message->line('You can still change your division from your dashboard while registration is open.');
        } else {
            $message->line('You can update your rifle selection or withdraw at any time before the registration close date from your dashboard.');
        }

        return $message;
    }
}
