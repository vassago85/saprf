<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\HtmlString;

/**
 * Federation-wide announcement email. Wraps the announcement body in
 * the standard Laravel MailMessage (SAPRF-branded via the published
 * mail vendor views) and routes back to the member's /communications/{id}
 * page so they can acknowledge / view attachments.
 *
 * ShouldQueue + RateLimited('mail') is what keeps a broadcast to
 * hundreds of members from tripping Mailgun's connection ceiling.
 */
class FederationAnnouncementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Announcement $announcement,
    ) {}

    /**
     * We only ever call this notification from SendAnnouncementChunkJob,
     * and always for the mail channel. Keeping database + webpush out
     * of via() means the Laravel notification event listeners don't
     * try to hydrate them.
     */
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
        $announcement = $this->announcement->fresh() ?? $this->announcement;

        $greetingName = $notifiable->name ?? 'shooter';

        $body = new HtmlString(nl2br(e($announcement->body)));

        $message = (new MailMessage)
            ->subject($announcement->title)
            ->greeting('Hi ' . $greetingName . ',')
            ->line('From SAPRF — ' . $announcement->category->label() . ':')
            ->line($body);

        if ($announcement->requires_acknowledgement) {
            $message->line('This is a policy-critical notice. Please open it in the portal and click "I acknowledge" so we have your receipt on file.');
        }

        $message->action('View in your archive', route('communications.show', $announcement));

        return $message;
    }

    /**
     * Exposed for the chunk job so it can persist a stable exception
     * signature into announcement_deliveries.error.
     */
    public function announcement(): Announcement
    {
        return $this->announcement;
    }
}
