<?php

namespace App\Notifications;

use App\Models\CreditRefundRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class CreditRefundResolvedNotification extends Notification implements ShouldQueue
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
        $amount = 'R'.number_format((float) $this->refund->amount, 2);

        if ($this->refund->status === CreditRefundRequest::STATUS_PAID) {
            return (new MailMessage)
                ->subject('Refund paid: '.$amount)
                ->greeting('Hi '.$notifiable->name.',')
                ->line('Your refund of '.$amount.' has been paid to '.$this->refund->account_holder.' at '.$this->refund->bank_name.'.')
                ->line('**Reference:** '.($this->refund->payment_reference ?: '—'))
                ->action('Back to my dashboard', route('dashboard'));
        }

        return (new MailMessage)
            ->subject('Refund request declined')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your refund request for '.$amount.' was not paid. The credit is back on your account and can be used for another event.')
            ->line('**Reason:** '.($this->refund->decline_reason ?: 'No reason was given.'))
            ->action('View my credit', route('account.refund'));
    }
}
