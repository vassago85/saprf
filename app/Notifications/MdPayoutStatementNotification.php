<?php

namespace App\Notifications;

use App\Models\Payout;
use App\Services\PayoutStatementRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

/**
 * MD-facing email that ships the frozen payout statement as a PDF.
 *
 * Fires on two moments only (per spec):
 *   - `event = 'raised'` when {@see FinancialService::createMdPayout()} runs
 *   - `event = 'paid'`   when the payout is marked fully paid
 *
 * The PDF is read from the renderer (which uses the frozen `payout_items`
 * + `statement_snapshot`), so once the payout is created the attachment
 * reflects the approved numbers even if other registrations later change.
 */
class MdPayoutStatementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Payout $payout,
        private readonly string $event = 'raised',
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
        $payout = $this->payout->fresh(['match']);
        $match = $payout->match;
        $isPaid = $this->event === 'paid';

        $subject = $isPaid
            ? "Payout {$payout->reference} marked paid"
            : "Payout statement issued: {$payout->reference}";

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Hi '.$notifiable->name.',');

        if ($isPaid) {
            $message->line("Your payout for **{$match?->name}** has been marked paid in full.")
                ->line('**Amount paid:** R'.number_format((float) $payout->paid_amount, 2))
                ->line('**Bank reference:** '.($payout->payment_reference ?: '—'));
        } else {
            $message->line("A payout statement has been raised for **{$match?->name}**.")
                ->line('**Net due:** R'.number_format((float) $payout->net_amount, 2))
                ->line('The attached PDF breaks down every paid entry and shows which entries were excluded.');
        }

        $message->line('**Payout reference:** '.$payout->reference)
            ->action('Open my payouts', route('my-payouts.show', $payout))
            ->line('Statement is frozen at v'.$payout->statement_version.' — regenerating it is an explicit admin action and will show up in the audit log.');

        // Attach PDF. DomPDF render failures must not swallow the email —
        // worst case the MD still gets the summary with the download link.
        try {
            $pdfPath = app(PayoutStatementRenderer::class)->pdfToTempFile($payout);
            $message->attach($pdfPath, [
                'as' => "{$payout->reference}.pdf",
                'mime' => 'application/pdf',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to attach payout statement PDF', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $message;
    }
}
