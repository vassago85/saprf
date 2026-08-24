<?php

namespace App\Notifications;

use App\Models\Membership;
use App\Services\MembershipValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Sent once when a membership crosses the "long-lapsed" cutoff
 * (MembershipValidationService::LAPSED_CUTOFF_DAYS after expiry_date).
 *
 * At this point the shooter no longer qualifies for the lapsed-member fee
 * bracket — their next match entry is priced at the full non-member rate.
 * Dispatched by ExpireMembershipsJob on the exact day of the cutoff so the
 * mail never repeats.
 */
class MembershipExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Membership $membership,
    ) {}

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
        $cutoffDays = MembershipValidationService::LAPSED_CUTOFF_DAYS;

        return (new MailMessage)
            ->subject('Your SAPRF Membership Has Now Expired')
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line("Your SAPRF membership expired more than {$cutoffDays} days ago and is now considered fully expired.")
            ->line('**Member Number:** ' . $this->membership->saprf_number)
            ->line('**Expired On:** ' . $this->membership->expiry_date?->format('d F Y'))
            ->line('From your next match entry onwards you will be charged the full **non-member** rate — the short-term lapsed-member grace no longer applies.')
            ->line('Renewing restores your member pricing straight away and re-opens season standings and selection eligibility.')
            ->action('Renew My Membership', route('my-membership'))
            ->line('If you believe this is an error, simply reply to this email.');
    }
}
