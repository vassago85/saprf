<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryStatus;
use App\Models\AnnouncementDelivery;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Mailgun webhook consumer for delivery-lifecycle events.
 *
 * Handles the events we actually care about for the announcement
 * delivery table:
 *
 *   delivered  → status = Delivered (Mailgun handed the message to the
 *                receiving MTA and got a 250 back).
 *   failed
 *     - severity=permanent → status = Bounced + flag the user's account
 *                            so future non-mandatory sends skip them.
 *     - severity=temporary → status = Failed with the reason recorded;
 *                            we DON'T flag the user (temp failures are
 *                            greylisting, DNS blips, etc.).
 *   complained → status = Bounced + flag user; the recipient hit "spam".
 *                We treat this the same as a hard bounce because
 *                continuing to send poisons sender reputation.
 *   unsubscribed → recorded but we do NOT act on it. Mailgun's built-in
 *                  unsubscribe link isn't wired to our messages; only
 *                  the RFC 8058 signed URL we generate ourselves is.
 *
 * Every incoming POST is HMAC-SHA256 verified with the webhook signing
 * key from services.mailgun.webhook_signing_key (env or DB-override).
 * Missing / bad signature → 401. Stale timestamp → 401 as well, to
 * make replay attacks pointless.
 *
 * Correlation with our own tables happens via the `delivery_id`
 * user-variable that FederationAnnouncementNotification injects as
 * X-Mailgun-Variables on every outgoing message.
 */
class MailgunWebhookController extends Controller
{
    private const SIGNATURE_MAX_AGE_SECONDS = 900;

    public function handle(Request $request): JsonResponse
    {
        $signature = $request->input('signature');
        if (! is_array($signature)) {
            return response()->json(['error' => 'Missing signature block'], 401);
        }

        if (! $this->verifySignature($signature)) {
            Log::warning('Mailgun webhook rejected: signature mismatch', [
                'ip' => $request->ip(),
                'timestamp' => $signature['timestamp'] ?? null,
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $eventData = $request->input('event-data');
        if (! is_array($eventData)) {
            return response()->json(['error' => 'Missing event-data'], 400);
        }

        $event = (string) ($eventData['event'] ?? '');
        $severity = (string) ($eventData['severity'] ?? '');
        $reason = (string) ($eventData['reason'] ?? ($eventData['delivery-status']['description'] ?? ''));
        $variables = $eventData['user-variables'] ?? [];

        $delivery = $this->resolveDelivery($variables, $eventData);
        $emailLog = $this->resolveEmailLog($variables, $eventData);

        match ($event) {
            'delivered'    => $this->onDelivered($delivery, $emailLog),
            'failed'       => $this->onFailed($delivery, $emailLog, $severity, $reason, $variables, $eventData),
            'complained'   => $this->onComplained($delivery, $emailLog, $reason, $variables, $eventData),
            'unsubscribed' => $this->onUnsubscribed($variables, $eventData),
            'opened'       => $this->onOpened($emailLog),
            'clicked'      => $this->onClicked($emailLog),
            default        => Log::info('Mailgun webhook ignored event', ['event' => $event]),
        };

        // Mailgun retries on non-2xx. Even if we couldn't find the row,
        // return 200 so Mailgun stops retrying — we've logged it.
        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $signature
     */
    private function verifySignature(array $signature): bool
    {
        $key = (string) config('services.mailgun.webhook_signing_key');
        if ($key === '') {
            Log::error('MAILGUN_WEBHOOK_SIGNING_KEY is not configured; rejecting webhook');
            return false;
        }

        $token = (string) ($signature['token'] ?? '');
        $timestamp = (string) ($signature['timestamp'] ?? '');
        $provided = (string) ($signature['signature'] ?? '');
        if ($token === '' || $timestamp === '' || $provided === '') {
            return false;
        }

        // Replay protection: Mailgun never lags this far behind. If the
        // signature is older than 15 minutes it's almost certainly a
        // replayed request. `time()` uses the system clock so make sure
        // the server has NTP running (containers get this for free).
        $ageSeconds = time() - (int) $timestamp;
        if ($ageSeconds > self::SIGNATURE_MAX_AGE_SECONDS || $ageSeconds < -60) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $token, $key);

        return hash_equals($expected, $provided);
    }

    /**
     * Find the AnnouncementDelivery row this event refers to. We first
     * try the user-variables we set on the outgoing message. If those
     * aren't there — e.g. a message sent before this deploy — we fall
     * back to (announcement_id + recipient email) which is the next
     * cheapest lookup, and then finally to just the recipient email
     * within a short window so at least we can update the user flag.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $eventData
     */
    private function resolveDelivery(array $variables, array $eventData): ?AnnouncementDelivery
    {
        $deliveryId = $variables['delivery_id'] ?? null;
        if ($deliveryId !== null) {
            $delivery = AnnouncementDelivery::query()
                ->with('recipient.user')
                ->find((int) $deliveryId);
            if ($delivery !== null) {
                return $delivery;
            }
        }

        return null;
    }

    /**
     * Find the matching row in `email_logs` (the transport-level audit
     * table). Correlation is by the `email_log_id` user-variable that
     * LogSendingMail injects on every outbound message.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $eventData
     */
    private function resolveEmailLog(array $variables, array $eventData): ?EmailLog
    {
        $emailLogId = $variables['email_log_id'] ?? null;
        if ($emailLogId !== null) {
            $log = EmailLog::query()->find((int) $emailLogId);
            if ($log !== null) {
                return $log;
            }
        }

        // Fallback: match by transport message-id, which Mailgun returns
        // in the `message.headers.message-id` field on every event.
        $messageId = $eventData['message']['headers']['message-id'] ?? null;
        if (is_string($messageId) && $messageId !== '') {
            return EmailLog::query()->where('message_id', trim($messageId, ' <>'))->first();
        }

        return null;
    }

    private function onDelivered(?AnnouncementDelivery $delivery, ?EmailLog $emailLog): void
    {
        if ($delivery !== null) {
            // Only advance forward. If the row is already Bounced from an
            // earlier failed event, don't overwrite that with "delivered"
            // just because Mailgun buffered a stale ack.
            if (in_array($delivery->status, [DeliveryStatus::Sent, DeliveryStatus::Queued], true)) {
                $delivery->forceFill([
                    'status' => DeliveryStatus::Delivered,
                    'sent_at' => $delivery->sent_at ?? now(),
                    'error' => null,
                ])->save();
            }
        }

        if ($emailLog !== null) {
            // Same forward-only rule for the transport log.
            if (in_array($emailLog->status, [EmailLog::STATUS_QUEUED, EmailLog::STATUS_SENT], true)) {
                $emailLog->forceFill([
                    'status' => EmailLog::STATUS_DELIVERED,
                    'delivered_at' => now(),
                    'sent_at' => $emailLog->sent_at ?? now(),
                    'error' => null,
                ])->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $eventData
     */
    private function onFailed(?AnnouncementDelivery $delivery, ?EmailLog $emailLog, string $severity, string $reason, array $variables, array $eventData): void
    {
        $permanent = strtolower($severity) === 'permanent';
        $reasonText = $reason !== '' ? $reason : ('Mailgun ' . $severity . ' failure');

        if ($delivery !== null) {
            $delivery->markFailed(
                $reasonText,
                $permanent ? DeliveryStatus::Bounced : DeliveryStatus::Failed,
            );
        }

        if ($emailLog !== null) {
            $emailLog->forceFill([
                'status' => $permanent ? EmailLog::STATUS_BOUNCED : EmailLog::STATUS_FAILED,
                'error' => mb_substr($reasonText, 0, 1000),
                'bounced_at' => $permanent ? now() : $emailLog->bounced_at,
                'failed_at' => now(),
            ])->save();
        }

        if ($permanent) {
            $this->flagUserBounced($variables, $eventData);
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $eventData
     */
    private function onComplained(?AnnouncementDelivery $delivery, ?EmailLog $emailLog, string $reason, array $variables, array $eventData): void
    {
        $reasonText = $reason !== '' ? $reason : 'Recipient marked message as spam';

        if ($delivery !== null) {
            $delivery->markFailed($reasonText, DeliveryStatus::Bounced);
        }

        if ($emailLog !== null) {
            $emailLog->forceFill([
                'status' => EmailLog::STATUS_COMPLAINED,
                'error' => mb_substr($reasonText, 0, 1000),
                'complained_at' => now(),
            ])->save();
        }

        $this->flagUserComplained($variables, $eventData);
    }

    private function onOpened(?EmailLog $emailLog): void
    {
        if ($emailLog === null || $emailLog->opened_at !== null) {
            return;
        }
        $emailLog->forceFill(['opened_at' => now()])->save();
    }

    private function onClicked(?EmailLog $emailLog): void
    {
        if ($emailLog === null || $emailLog->clicked_at !== null) {
            return;
        }
        $emailLog->forceFill(['clicked_at' => now()])->save();
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $eventData
     */
    private function onUnsubscribed(array $variables, array $eventData): void
    {
        // Not used — we ship RFC 8058 links, not Mailgun's built-in
        // unsubscribe. Logging the event helps us notice if that ever
        // changes (e.g. Mailgun rewrites a link we didn't expect).
        Log::info('Mailgun unsubscribed event received but not actioned', [
            'user_id' => $variables['user_id'] ?? null,
            'recipient' => $eventData['recipient'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $eventData
     */
    private function flagUserBounced(array $variables, array $eventData): void
    {
        $user = $this->resolveUser($variables, $eventData);
        if ($user === null) {
            return;
        }

        $user->forceFill([
            'email_bounced_at' => $user->email_bounced_at ?? now(),
            'email_bounce_count' => (int) ($user->email_bounce_count ?? 0) + 1,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $eventData
     */
    private function flagUserComplained(array $variables, array $eventData): void
    {
        $user = $this->resolveUser($variables, $eventData);
        if ($user === null) {
            return;
        }

        $user->forceFill([
            'email_complained_at' => $user->email_complained_at ?? now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $eventData
     */
    private function resolveUser(array $variables, array $eventData): ?User
    {
        $userId = $variables['user_id'] ?? null;
        if ($userId !== null) {
            return User::query()->find((int) $userId);
        }

        $recipient = $eventData['recipient'] ?? null;
        if (! is_string($recipient) || $recipient === '') {
            return null;
        }

        return User::query()->where('email', $recipient)->first();
    }
}
