<?php

namespace App\Notifications;

use App\Models\MatchEvent;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the match director after an Impact live score import when one or
 * more shooters need fee follow-up (pending/unpaid entries, or walk-in members
 * whose SAPRF + platform fees were deducted from the MD payout).
 */
class ImpactImportCollectionReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{
     *   name:string,
     *   reason:string,
     *   registration_status?:?string,
     *   payment_status?:?string,
     *   fee_amount?:float,
     *   saprf_fee?:float,
     *   platform_fee?:float
     * }>  $rows
     */
    public function __construct(
        private readonly MatchEvent $match,
        private readonly array $rows,
        private readonly User $importedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Impact import — fees to collect · '.$this->match->name)
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line('Scores were imported from Impact Scoring for **'.$this->match->name.'**.')
            ->line('The following shooters need your attention. SAPRF and platform fees have been applied against the match payout; please collect any outstanding entry fees from the shooters listed.');

        foreach ($this->rows as $row) {
            $collect = number_format((float) ($row['fee_amount'] ?? 0), 2);
            $saprf = number_format((float) ($row['saprf_fee'] ?? 0), 2);
            $platform = number_format((float) ($row['platform_fee'] ?? 0), 2);
            $reason = match ($row['reason'] ?? '') {
                'pending_unpaid' => 'Pending / unpaid entry',
                'walk_in_member' => 'Member walk-in (not on entry list)',
                default => (string) ($row['reason'] ?? 'Follow-up'),
            };
            $mail->line(sprintf(
                '• **%s** — %s. Collect R%s from shooter. (SAPRF R%s + platform R%s on payout.)',
                $row['name'] ?? 'Shooter',
                $reason,
                $collect,
                $saprf,
                $platform,
            ));
        }

        return $mail
            ->line('Imported by '.$this->importedBy->name.'.')
            ->salutation('SAPRF Platform');
    }
}
