<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\User;
use App\Support\AnnouncementBodyRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Federation-wide announcement email. Wraps the announcement body in
 * the standard Laravel MailMessage (SAPRF-branded via the published
 * mail vendor views) and routes back to the member's /communications/{id}
 * page so they can acknowledge / view attachments.
 *
 * Files uploaded on the announcement are attached to this email when
 * they fit the mail size budget. Larger sets stay download-only in
 * the portal so a 100 MB notice cannot fail the whole send.
 *
 * ShouldQueue + RateLimited('mail') (50/hour) keeps a broadcast from
 * tripping Mailgun's probation cap. Auth mail skips this limiter.
 *
 * Gmail 2024 bulk-sender headers:
 *   List-Unsubscribe: <mailto:...>, <https://.../email/unsubscribe/{user}?...&signature=...>
 *   List-Unsubscribe-Post: List-Unsubscribe=One-Click
 * Both are required for RFC 8058 one-click unsubscribe; Gmail sends a
 * POST to the URL when the user hits the built-in "Unsubscribe" button.
 *
 * Mailgun correlation:
 *   X-Mailgun-Variables: {"delivery_id": 123, "announcement_id": 45}
 * Mailgun stores these as user-variables and echoes them back on every
 * webhook event so `MailgunWebhookController` can find the matching
 * `announcement_deliveries` row and update its status.
 */
class FederationAnnouncementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Mailgun rejects a message once the whole MIME payload exceeds
     * 25 MB. Base64 encoding adds about a third, and the HTML body
     * needs room too, so raw attachment bytes stay under 18 MB.
     * Override with `mail.announcement_attachment_budget` when a
     * transport has a tighter cap.
     */
    private const ATTACHMENT_BUDGET_BYTES = 18 * 1024 * 1024;

    /**
     * Give the queued send a comfortable retry budget. Every
     * `RateLimited::release()` counts against this — with the previous
     * `tries = 3` default any broadcast bigger than the per-minute
     * cap would inevitably burn the budget on rate-limit sleeps and
     * dump the tail of the recipient list into `failed_jobs` with
     * `MaxAttemptsExceededException`. Ten is a wide margin: even if
     * every attempt got released by the limiter, the job would live
     * long enough for the hourly slot to open.
     */
    public int $tries = 10;

    /**
     * Cap the total time we're willing to keep re-releasing this job.
     * If a send is still stuck an hour after enqueue there's a real
     * problem — better to surface it as a failed_jobs row (which then
     * calls `failed()` below to mark the delivery honestly) than
     * silently keep trying forever.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHour()->toDateTime();
    }

    public function __construct(
        private readonly Announcement $announcement,
        private readonly ?AnnouncementDelivery $delivery = null,
    ) {}

    /**
     * We only ever call this notification from SendAnnouncementChunkJob,
     * and always for the mail channel. Keeping database + webpush out
     * of via() means the Laravel notification event listeners don't
     * try to hydrate them.
     */
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
        /** @var User $notifiable */
        $announcement = $this->announcement->fresh() ?? $this->announcement;

        $greetingName = $notifiable->name ?? 'shooter';

        $body = AnnouncementBodyRenderer::toHtml((string) $announcement->body);
        $message = new MailMessage;
        $attachmentSummary = $this->attachAnnouncementFiles($message, $announcement);

        $unsubscribeUrl = URL::signedRoute('email.unsubscribe', [
            'user' => $notifiable->id,
            'category' => $announcement->category->value,
        ]);

        $message
            ->subject($announcement->display_title)
            ->greeting('Hi ' . $greetingName . ',')
            ->line('From SAPRF — ' . $announcement->category->label() . ':')
            ->line($body);

        if ($attachmentSummary['attached'] === 1) {
            $message->line('The file for this notice is attached to this email.');
        } elseif ($attachmentSummary['attached'] > 1) {
            $message->line('The files for this notice are attached to this email.');
        }

        if ($attachmentSummary['skipped_for_size'] > 0) {
            $message->line('One or more files were too large to attach. Open the notice in the portal to download them.');
        }

        if ($announcement->requires_acknowledgement) {
            $message->line('This is a policy-critical notice. Please open it in the portal and click "I acknowledge" so we have your receipt on file.');
        }

        $message->action('View in your archive', route('communications.show', $announcement));

        // Every recipient gets a link at the bottom of the branded footer,
        // which is what most humans actually click even if their client
        // supports the header. Duplicating it is fine — both routes go
        // to the same signed endpoint.
        $message->line(new HtmlString(sprintf(
            '<span style="font-size:11px; color:#888;">Not interested in %s emails? <a href="%s" style="color:#3d4b2e;">Unsubscribe from this category</a>.</span>',
            strtolower(e($announcement->category->label())),
            e($unsubscribeUrl),
        )));

        $message->withSymfonyMessage(function (Email $email) use ($notifiable, $announcement, $unsubscribeUrl) {
            $headers = $email->getHeaders();

            // RFC 8058 one-click unsubscribe. Two entries: mailto for
            // legacy clients, https URL for Gmail's one-click POST.
            $mailto = config('mail.from.address') ?: 'admin@saprf.co.za';
            $headers->addTextHeader(
                'List-Unsubscribe',
                sprintf('<mailto:%s?subject=unsubscribe>, <%s>', $mailto, $unsubscribeUrl),
            );
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            // Mark this as bulk transactional. Gmail uses this signal
            // when deciding to show the built-in Unsubscribe button.
            $headers->addTextHeader('Precedence', 'bulk');

            // Mailgun user-variables for webhook correlation. The
            // MailgunHeadersPreparation middleware in Symfony's Mailgun
            // transport reads this specific header and forwards it to
            // Mailgun's API as v: variables.
            $variables = [
                'delivery_id' => $this->delivery?->id,
                'announcement_id' => $announcement->id,
                'user_id' => $notifiable->id,
                'category' => $announcement->category->value,
            ];
            $headers->addTextHeader(
                'X-Mailgun-Variables',
                json_encode(array_filter($variables, fn ($v) => $v !== null), JSON_UNESCAPED_SLASHES),
            );
        });

        return $message;
    }

    /**
     * Attach announcement files that fit the mail budget. Missing files
     * are skipped so one bad path cannot fail the send. Files that would
     * push the message over the budget stay in the portal.
     *
     * @return array{attached: int, skipped_for_size: int}
     */
    private function attachAnnouncementFiles(MailMessage $message, Announcement $announcement): array
    {
        $announcement->loadMissing('attachments');

        $attached = 0;
        $skippedForSize = 0;
        $used = 0;
        $budget = $this->attachmentBudget();
        $disk = Storage::disk('announcements');

        foreach ($announcement->attachments as $attachment) {
            try {
                if (! $disk->exists($attachment->path)) {
                    Log::warning('Announcement attachment missing at send time', [
                        'announcement_id' => $announcement->id,
                        'attachment_id' => $attachment->id,
                        'path' => $attachment->path,
                    ]);

                    continue;
                }

                $bytes = $disk->size($attachment->path);
            } catch (Throwable $e) {
                Log::warning('Announcement attachment could not be read at send time', [
                    'announcement_id' => $announcement->id,
                    'attachment_id' => $attachment->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! is_int($bytes) || $bytes < 0 || $used + $bytes > $budget) {
                $skippedForSize++;

                continue;
            }

            $message->attach($disk->path($attachment->path), [
                'as' => $this->safeAttachmentName($attachment->filename),
                'mime' => $attachment->mime ?: 'application/octet-stream',
            ]);

            $used += $bytes;
            $attached++;
        }

        return [
            'attached' => $attached,
            'skipped_for_size' => $skippedForSize,
        ];
    }

    private function attachmentBudget(): int
    {
        $configured = config('mail.announcement_attachment_budget');

        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        return self::ATTACHMENT_BUDGET_BYTES;
    }

    private function safeAttachmentName(?string $filename): string
    {
        $firstLine = preg_split('/[\r\n]+/', (string) $filename)[0] ?? '';
        $name = str_replace("\0", '', $firstLine);
        $name = basename(str_replace('\\', '/', $name));
        $name = trim($name);

        return $name !== '' ? $name : 'attachment';
    }

    /**
     * Exposed for the chunk job so it can persist a stable exception
     * signature into announcement_deliveries.error.
     */
    public function announcement(): Announcement
    {
        return $this->announcement;
    }

    /**
     * Queue-worker failure hook: Laravel calls this when the queued
     * send exhausts `tries` / `retryUntil` or throws an unrecoverable
     * exception. Without this, a rate-limited or transport-failed
     * send would leave the paired `announcement_deliveries` row on
     * `sent` forever — because `SendAnnouncementChunkJob::sendMail`
     * calls `markSent()` optimistically the moment `$user->notify()`
     * enqueues the job.
     *
     * This handler runs in a fresh worker process with the notification
     * hydrated from its serialized payload — `$this->delivery` is a
     * live Eloquent model at this point thanks to `SerializesModels`,
     * so `markFailed()` writes straight through.
     */
    public function failed(Throwable $e): void
    {
        if ($this->delivery === null) {
            Log::warning('FederationAnnouncementNotification failed but delivery ref is null; recipient bookkeeping not updated.', [
                'announcement_id' => $this->announcement->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Refresh from the DB in case another process already updated
        // the row (e.g. Mailgun bounce webhook fired first). If the
        // status has already moved to a terminal state we leave it —
        // don't downgrade `bounced` back to `failed`.
        $delivery = $this->delivery->fresh() ?? $this->delivery;
        $terminal = ['bounced', 'complained', 'delivered'];
        $currentStatus = $delivery->status instanceof \App\Enums\DeliveryStatus
            ? $delivery->status->value
            : (string) $delivery->status;

        if (in_array($currentStatus, $terminal, true)) {
            return;
        }

        $delivery->markFailed('Queued send failed: ' . $e->getMessage());

        Log::warning('FederationAnnouncementNotification: queued send exhausted retries', [
            'announcement_id' => $this->announcement->id,
            'delivery_id' => $delivery->id,
            'error' => $e->getMessage(),
        ]);
    }
}
