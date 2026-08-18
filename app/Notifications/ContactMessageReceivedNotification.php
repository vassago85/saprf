<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Emailed to admin recipients when a fresh /contact form submission
 * lands. Uses replyTo() so hitting "Reply" in the admin's mail client
 * responds directly to the enquirer, keeping the platform out of the
 * middle of the ensuing conversation.
 */
class ContactMessageReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ContactMessage $contactMessage) {}

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
        $m = $this->contactMessage;

        return (new MailMessage)
            ->subject('SAPRF enquiry: '.$m->subject)
            ->replyTo($m->email, $m->fullName())
            ->greeting('New SAPRF enquiry')
            ->line("**From:** {$m->fullName()} <{$m->email}>")
            ->line("**Subject:** {$m->subject}")
            ->line('---')
            ->line($m->message)
            ->line('---')
            ->line("Received at ".$m->created_at->format('Y-m-d H:i')." from IP {$m->ip_address}.")
            ->action('Open in admin', url(route('contact-messages.show', $m)))
            ->line('Reply to this email to respond directly to the enquirer.');
    }
}
