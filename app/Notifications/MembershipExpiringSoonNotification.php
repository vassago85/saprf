<?php

namespace App\Notifications;

use App\Models\Membership;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MembershipExpiringSoonNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Membership $membership,
        private readonly int $daysRemaining,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your SAPRF Membership Expires in ' . $this->daysRemaining . ' Days')
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line('A friendly reminder that your SAPRF membership is approaching its expiry date.')
            ->line('**Member Number:** ' . $this->membership->saprf_number)
            ->line('**Expires On:** ' . $this->membership->expiry_date?->format('d F Y'))
            ->line('Renew now to keep your membership active and continue qualifying for season standings and selection.')
            ->action('Renew My Membership', route('my-membership'))
            ->line('Renewing in advance avoids any gap in your membership and ensures uninterrupted access to match registration.');
    }
}
