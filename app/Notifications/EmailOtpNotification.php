<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Queued onto the `high` connection so a large announcement burst on
 * `default` can't stall a user's OTP for minutes. The prod queue worker
 * runs with `--queue=high,default` and drains `high` first.
 */
class EmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $otp,
    ) {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Signed URL is self-contained (no session) so it works on any device.
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );

        return (new MailMessage)
            ->subject('Verify Your SAPRF Email')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('Click the button below to verify your email. This link works on any phone or computer — you do not need to use the same browser that registered.')
            ->action('Verify Email Address', $url)
            ->line('Or enter this 6-digit code on the verification page:')
            ->line('**'.$this->otp.'**')
            ->line('The link expires in 60 minutes; the code expires in 30 minutes.')
            ->line('If you did not create an account, no further action is required.');
    }
}
