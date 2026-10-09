<?php

namespace App\Notifications;

use App\Models\CreditRefundRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class CreditRefundRequestedStaffNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly CreditRefundRequest $refund) {}

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
        $member = $this->refund->user;

        return (new MailMessage)
            ->subject('Refund request: R'.number_format((float) $this->refund->amount, 2).' — '.($member?->name ?? 'member'))
            ->line(($member?->name ?? 'A member').' asked for a cash refund of cancellation credit.')
            ->line('**Amount:** R'.number_format((float) $this->refund->amount, 2))
            ->line('Bank details are on the finance page, not in this email.')
            ->action('Review refund requests', route('financials.credit-refunds.index'));
    }
}
