<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invites an existing member to activate their account on the new SAPRF
 * platform. The link auto-verifies their email and lets them set a password
 * once — no OTP required.
 */
class MemberInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('invitation.accept', ['token' => $this->token], false));

        return (new MailMessage)
            ->subject('Activate Your SAPRF Account')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('The South African Precision Rifle Federation has launched a new membership platform, and an account has been created for you.')
            ->line('Click the button below to set your password and activate your account. There is nothing else to verify — you are ready to go.')
            ->action('Set Your Password', $url)
            ->line('This invitation link will expire in '.\App\Models\User::INVITATION_TTL_DAYS.' days.')
            ->line('If you were not expecting this email, you can safely ignore it.')
            ->salutation("Regards,\nThe SAPRF Team");
    }
}
