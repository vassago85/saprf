<?php

namespace App\Notifications;

use App\Models\MatchEvent;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Email informing a shooter that the MD has marked scores complete for
 * a match. Fired from NotifyScoresFinalizedJob — never dispatched by
 * Laravel's standard event flow, because the audience resolver already
 * applied preference checks.
 *
 * Deliberately plain: a line about which match, who shot it (if they
 * did), a link to the public results page, and a reminder that the
 * recipient can retune their preferences at any time.
 *
 * ShouldQueue + RateLimited('mail') (50/hour) keeps a provincial or
 * national finalisation from tripping Mailgun's probation cap. Mirrors
 * FederationAnnouncementNotification's pattern.
 */
class ScoresFinalizedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public function retryUntil(): \DateTime
    {
        return now()->addHour()->toDateTime();
    }

    public function __construct(
        private readonly MatchEvent $match,
        /**
         * Why this recipient is on the list. Used purely for copy and
         * telemetry — "we're emailing you because you shot this match"
         * versus "we're emailing you because you follow all PRS".
         */
        private readonly string $reason = 'own',
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
        /** @var User $notifiable */
        $match = $this->match->fresh() ?? $this->match;

        $seriesLabel = $match->match_type ?: ($match->series ?: 'match');
        $resultsUrl = route('events.show', $match) . '#results';
        $prefsUrl = route('profile') . '#notification-preferences';

        $subject = sprintf('%s results are final — %s', strtoupper($seriesLabel), $match->name);

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Hi ' . ($notifiable->name ?: 'shooter') . ',');

        if ($this->reason === 'own') {
            $message->line(sprintf(
                'The scores for %s (%s) are now marked complete by the match director.',
                $match->name,
                $match->formatted_date ?: 'recent match',
            ));
        } else {
            $message->line(sprintf(
                'Scores for the %s match %s (%s) are now marked complete. You are on this list because you subscribed to all %s results.',
                strtoupper($seriesLabel),
                $match->name,
                $match->formatted_date ?: 'recent',
                strtoupper($seriesLabel),
            ));
        }

        $message->line('Names and entries have been checked. Final standings are posted on the public results page.')
            ->action('View final results', $resultsUrl)
            ->line(sprintf(
                'You can change these notification preferences at any time in your [Notification Preferences](%s).',
                $prefsUrl,
            ))
            ->salutation('Shoot straight,  '."\n".'SAPRF');

        return $message;
    }

    public function match(): MatchEvent
    {
        return $this->match;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
