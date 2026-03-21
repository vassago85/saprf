<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $otp,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your SAPRF Verification Code')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your email verification code is:')
            ->line('**' . $this->otp . '**')
            ->line('This code expires in 30 minutes.')
            ->line('If you did not create an account, no further action is required.');
    }
}
