<?php

namespace App\Notifications;

use App\Models\MatchEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * One-off invitation to answer the anonymous post-match survey. Fired
 * by the Exco action on the staff Survey tab. The mail body re-states
 * the anonymity rule so the recipient knows SAPRF can see that they
 * answered, not what they said.
 */
class MatchSurveyInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly MatchEvent $match,
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
        $greetingName = $notifiable->name ?? 'shooter';

        return (new MailMessage)
            ->subject('How was ' . $this->match->name . '? Share your feedback')
            ->greeting('Hi ' . $greetingName . ',')
            ->line('Thanks for shooting **' . $this->match->name . '**.')
            ->line('Please take a few minutes to rate the match: thirteen categories from 1 to 10, plus three short questions on what you liked, what to improve, and anything else we should know.')
            ->line('The survey is anonymous — SAPRF can see that you responded, and cannot see which answers are yours. One reply per shooter.')
            ->action('Open the survey', route('matches.survey.show', $this->match))
            ->line('The survey closes 5 days after the match, so please answer as soon as you can. If you cannot see the form, make sure you are logged in with the account you used to enter.');
    }
}
