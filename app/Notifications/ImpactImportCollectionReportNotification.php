<?php

namespace App\Notifications;

use App\Models\MatchEvent;
use App\Models\ScoreImport;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the match director after an Impact live score import with the full
 * audit report: unpaid entries, walk-ins, assign-later stubs, skipped fuzzy
 * rows, and non-member stubs.
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
        private readonly ?ScoreImport $import = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Impact import audit · '.$this->match->name)
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line('Scores were imported from Impact Scoring for **'.$this->match->name.'**.')
            ->line('Here is the post-import audit for your records. Use **Send inquiry** on unpaid entries, and **Link member** on assign-later stubs when you have confirmed the identity.');

        foreach ($this->rows as $row) {
            $mail->line($this->formatRow($row));
        }

        if ($this->import) {
            $mail->action(
                'Open import audit',
                route('score-imports.show', $this->import),
            );
        }

        return $mail
            ->line('Imported by '.$this->importedBy->name.'.')
            ->salutation('SAPRF Platform');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function formatRow(array $row): string
    {
        $name = $row['name'] ?? 'Shooter';
        $reason = match ($row['reason'] ?? '') {
            'pending_unpaid' => 'Pending / unpaid entry — collect fee / send inquiry',
            'walk_in_member' => 'Member walk-in (not on entry list) — collect fee',
            'assign_later' => 'Assign later — score held until you link a member',
            'skipped_fuzzy' => 'Skipped (fuzzy name not confirmed)',
            'skipped' => 'Skipped by MD',
            'non_member_stub' => 'Non-member stub — visible, does not count for season',
            default => (string) ($row['reason'] ?? 'Follow-up'),
        };

        $feeBits = '';
        $fee = (float) ($row['fee_amount'] ?? 0);
        if ($fee > 0 || in_array($row['reason'] ?? '', ['pending_unpaid', 'walk_in_member'], true)) {
            $feeBits = sprintf(
                ' Collect R%s (SAPRF R%s + platform R%s on payout).',
                number_format($fee, 2),
                number_format((float) ($row['saprf_fee'] ?? 0), 2),
                number_format((float) ($row['platform_fee'] ?? 0), 2),
            );
        }

        return "• **{$name}** — {$reason}.{$feeBits}";
    }
}
