<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email;

/**
 * Federation-wide announcement email. Wraps the announcement body in
 * the standard Laravel MailMessage (SAPRF-branded via the published
 * mail vendor views) and routes back to the member's /communications/{id}
 * page so they can acknowledge / view attachments.
 *
 * ShouldQueue + RateLimited('mail') is what keeps a broadcast to
 * hundreds of members from tripping Mailgun's connection ceiling.
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

        $body = new HtmlString(nl2br(e($announcement->body)));

        $unsubscribeUrl = URL::signedRoute('email.unsubscribe', [
            'user' => $notifiable->id,
            'category' => $announcement->category->value,
        ]);

        $message = (new MailMessage)
            ->subject($announcement->title)
            ->greeting('Hi ' . $greetingName . ',')
            ->line('From SAPRF — ' . $announcement->category->label() . ':')
            ->line($body);

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
     * Exposed for the chunk job so it can persist a stable exception
     * signature into announcement_deliveries.error.
     */
    public function announcement(): Announcement
    {
        return $this->announcement;
    }
}
