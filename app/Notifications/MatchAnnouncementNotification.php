<?php

namespace App\Notifications;

use App\Models\MatchAnnouncement;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\HtmlString;

class MatchAnnouncementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  MatchAnnouncement  $announcement  Persisted announcement row —
     *                                           passed by reference so the
     *                                           queued job re-reads the
     *                                           current subject/body if it
     *                                           somehow drifted between
     *                                           enqueue and send.
     * @param  User  $sender  The MD (or admin) who sent it. Used only for the
     *                        Reply-To header so shooters can respond directly.
     */
    public function __construct(
        private readonly MatchAnnouncement $announcement,
        private readonly User $sender,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Route every send through the shared "mail" limiter (5/sec, 300/min)
     * registered in AppServiceProvider::registerMailRateLimiter().
     */
    public function middleware(): array
    {
        return [new RateLimited('mail')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $announcement = $this->announcement->loadMissing('match');
        $match = $announcement->match;
        $greetingName = $notifiable->name ?? 'shooter';
        $senderEmail = $this->sender->email;
        $senderName = $this->sender->name;

        $body = new HtmlString(nl2br(e($announcement->body)));

        $message = (new MailMessage)
            ->subject($announcement->subject)
            ->greeting('Hi ' . $greetingName . ',')
            ->line('From the match director of **' . $match->name . '**:')
            ->line($body)
            ->line('This message was sent to everyone on the entry list.');

        if ($senderEmail) {
            $message->replyTo($senderEmail, $senderName);
        }

        $message->action('View Match', route('events.show', $match));

        return $message;
    }
}
