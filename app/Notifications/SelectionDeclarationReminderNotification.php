<?php

namespace App\Notifications;

use App\Models\SelectionAthlete;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Sent to a shooter who has met every selection criterion except the
 * Eligibility to Compete declaration. The action link opens their Team
 * Selection page anchored on that cycle's form.
 */
class SelectionDeclarationReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly SelectionAthlete $athlete) {}

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
        $athlete = $this->athlete->loadMissing(['cycle', 'user']);
        $cycle = $athlete->cycle;
        $series = trim(($cycle?->series ?? '').' '.($cycle?->season ?? ''));
        $url = route('iprf.index').'#declaration-'.$cycle?->id;

        $mail = (new MailMessage)
            ->subject("Complete your Eligibility to Compete form · {$series}")
            ->greeting('Hello '.$notifiable->name)
            ->line("You have met every other selection criterion for {$series}. The only item still outstanding is your Eligibility to Compete declaration.");

        $deadline = $cycle?->declaration_deadline;
        if ($deadline) {
            $formatted = $deadline->timezone(config('app.timezone'))->format('l, j F Y');
            $mail->line($deadline->isFuture()
                ? "Please submit it by {$formatted}."
                : "The declaration deadline was {$formatted}. Please submit the form as soon as you can, or contact ExCo if you need help.");
        }

        return $mail
            ->action('Complete your declaration', $url)
            ->line('Sign in with your SAPRF account. The button opens your Team Selection page at the declaration form.')
            ->salutation("Regards,\nThe SAPRF Team");
    }
}
