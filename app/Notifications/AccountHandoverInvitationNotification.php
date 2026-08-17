<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class AccountHandoverInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  User  $junior  The managed account being handed over.
     * @param  User  $parent  The parent who is handing the account over.
     * @param  string  $plainToken  Plain (un-hashed) token to embed in the URL.
     */
    public function __construct(
        private readonly User $junior,
        private readonly User $parent,
        private readonly string $plainToken,
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
        $url = route('family.handover.accept', ['token' => $this->plainToken]);
        $expires = $this->junior->handover_expires_at?->format('d F Y \a\t H:i') ?? '';

        return (new MailMessage)
            ->subject('Your SAPRF account is ready — set your password')
            ->greeting('Hi ' . $this->junior->name . ',')
            ->line($this->parent->name . ' has been managing your SAPRF shooting record up until now.')
            ->line('They\'ve handed your account over to you so you can manage your own membership, match registrations, and standings going forward.')
            ->line('Click the button below to confirm your email and set your own password.')
            ->action('Activate My Account', $url)
            ->line('All your existing scores, registrations, and standings stay with this account — nothing is lost.')
            ->line('This invitation expires on ' . $expires . '.')
            ->line('If you weren\'t expecting this email, you can safely ignore it — no changes will be made.')
            ->salutation('See you on the range,
The SAPRF Team');
    }
}
