<?php

namespace App\Notifications;

use App\Models\MatchEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Sent to the match director when registration closes, listing every
 * entry that is still pending / unpaid so they can chase or release slots.
 */
class RegistrationClosedPendingMdNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, \App\Models\MatchRegistration>  $pending
     */
    public function __construct(
        private readonly MatchEvent $match,
        private readonly Collection $pending,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $close = $this->match->registration_close_date?->format('d M Y') ?? 'recently';
        $mail = (new MailMessage)
            ->subject('Registration closed — '.$this->pending->count().' pending · '.$this->match->name)
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line('Registration for **'.$this->match->name.'** closed on **'.$close.'**.')
            ->line('The following '.$this->pending->count().' entrant(s) are still pending or unpaid. Each has been emailed with options to **pay** or **withdraw**.');

        foreach ($this->pending as $reg) {
            $name = $reg->user?->name ?: $reg->shooter_name ?: 'Shooter';
            $fee = number_format((float) $reg->fee_amount, 2);
            $mail->line(sprintf(
                '• **%s** — reg %s · payment %s · R%s',
                $name,
                $reg->registration_status ?? '—',
                $reg->payment_status ?? '—',
                $fee,
            ));
        }

        return $mail
            ->action('View registrations', route('registrations.index', ['match_id' => $this->match->id]))
            ->line('You can still send a payment inquiry or mark settled from the registrations list.')
            ->salutation('SAPRF Platform');
    }
}
