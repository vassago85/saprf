<?php

namespace App\Notifications;

use App\Models\CreditRefundRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class CreditRefundRequestedNotification extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject('Refund request received')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('We have your request to refund the entry credit from a cancelled match. The match was cancelled, so there is no admin fee.')
            ->line('**Amount:** R'.number_format((float) $this->refund->amount, 2))
            ->line('**Account:** '.$this->refund->account_holder.' — '.$this->refund->bank_name)
            ->line('That amount is held on your account until SAPRF pays it, so it will not also be used for another event. Anything you left as credit is still available.')
            ->action('View refund request', route('account.refund'));
    }
}
