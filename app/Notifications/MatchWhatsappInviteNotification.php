<?php

namespace App\Notifications;

use App\Models\MatchEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * One-button mail: the only action is the match WhatsApp group invite.
 */
class MatchWhatsappInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly MatchEvent $match,
        private readonly string $inviteUrl,
        private readonly ?string $greetingName = null,
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
        $name = $this->greetingName ?: ($notifiable->name ?? 'there');

        return (new MailMessage)
            ->subject('Join the WhatsApp group for '.$this->match->name)
            ->greeting('Hi '.$name.',')
            ->line('You are entered in '.$this->match->name.'.')
            ->line('Join the match WhatsApp group for notifications, match books, and range updates.')
            ->action('Join match WhatsApp group', $this->inviteUrl);
    }
}
