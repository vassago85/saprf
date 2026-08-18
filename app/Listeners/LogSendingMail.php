<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Persists a row in `email_logs` for every outgoing email BEFORE it
 * hits the transport, and injects an `email_log_id` into
 * X-Mailgun-Variables so Mailgun webhook events can correlate back to
 * this row.
 *
 * Runs synchronously (mail events are broadcast on the current process,
 * not queued) so the row is guaranteed to exist by the time
 * LogSentMail flips it to `sent`.
 *
 * Sensitive notifications (password reset, OTP, invitations) never
 * store their bodies — the token is inside the body and we don't want
 * that in the DB. `body_redacted` records the fact.
 */
class LogSendingMail
{
    /**
     * Notification class names whose email body must never be persisted.
     * These carry single-use secrets (reset tokens, OTPs, invitation
     * tokens); anyone with staff read access to email_logs could
     * otherwise steal an in-flight credential.
     *
     * @var array<int, string>
     */
    public const SENSITIVE_NOTIFICATIONS = [
        \App\Notifications\ResetPasswordNotification::class,
        \App\Notifications\EmailOtpNotification::class,
        \App\Notifications\AccountHandoverInvitationNotification::class,
        \App\Notifications\MemberInvitationNotification::class,
        \Illuminate\Auth\Notifications\ResetPassword::class,
        \Illuminate\Auth\Notifications\VerifyEmail::class,
    ];

    public function handle(MessageSending $event): void
    {
        try {
            $email = $event->message;
            [$toEmail, $toName] = $this->firstTo($email);
            if ($toEmail === null) {
                // No recipient? Nothing to log — the transport will error.
                return;
            }

            $notificationClass = $this->notificationClassFrom($event->data);
            $isSensitive = $notificationClass !== null
                && in_array($notificationClass, self::SENSITIVE_NOTIFICATIONS, true);

            $context = $this->contextFrom($event->data);

            $log = EmailLog::create([
                'to_email' => $toEmail,
                'to_name' => $toName,
                'from_email' => $this->firstAddress($email->getFrom()),
                'reply_to' => $this->firstAddress($email->getReplyTo()),
                'subject' => mb_substr((string) $email->getSubject(), 0, 500),
                'mailer' => (string) (config('mail.default') ?: 'unknown'),
                'notification_class' => $notificationClass,
                'user_id' => $this->userIdFrom($event->data, $toEmail),
                'context' => $context,
                'status' => EmailLog::STATUS_QUEUED,
                'body_html' => $isSensitive ? null : $email->getHtmlBody(),
                'body_preview' => $isSensitive
                    ? null
                    : mb_substr((string) ($email->getTextBody() ?? strip_tags((string) $email->getHtmlBody())), 0, 500),
                'body_redacted' => $isSensitive,
            ]);

            // Fold our log id into the existing X-Mailgun-Variables JSON
            // (or create one if the notification didn't set one). This is
            // what MailgunWebhookController uses to find this row when
            // Mailgun tells us the message delivered / failed / bounced.
            $this->injectMailgunVariable($email, 'email_log_id', $log->id);
        } catch (Throwable $e) {
            // NEVER let logging failures block outbound mail. This runs
            // in-process on every send; a broken listener would take the
            // whole app's mail down. Log and swallow.
            Log::warning('LogSendingMail failed to persist row', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function firstTo(Email $email): array
    {
        $to = $email->getTo();
        if (count($to) === 0) {
            return [null, null];
        }
        $first = $to[0];

        return [$first->getAddress(), $first->getName() !== '' ? $first->getName() : null];
    }

    /**
     * @param  array<int, Address>  $addresses
     */
    private function firstAddress(array $addresses): ?string
    {
        return count($addresses) > 0 ? $addresses[0]->getAddress() : null;
    }

    /**
     * The `data` bag on a MessageSending event is whatever
     * Notification::sendNow passed to the mailer. For notifications it
     * contains `__laravel_notification` (a CLASS NAME STRING, not the
     * object) and `__laravel_notification_id`. See
     * MailChannel::additionalMessageData() in the framework.
     *
     * @param  array<string, mixed>  $data
     */
    private function notificationClassFrom(array $data): ?string
    {
        $notification = $data['__laravel_notification'] ?? null;
        if (is_string($notification) && $notification !== '') {
            return $notification;
        }
        if (is_object($notification)) {
            return get_class($notification);
        }
        return null;
    }

    /**
     * We can't derive the recipient user from `__laravel_notifiable`
     * because Laravel doesn't pass it in `MessageSending::data`. Fall
     * back to matching by email — cheap and correct in every case
     * where the recipient is a real user account.
     *
     * @param  array<string, mixed>  $data
     */
    private function userIdFrom(array $data, string $toEmail): ?int
    {
        return User::query()->where('email', $toEmail)->value('id');
    }

    /**
     * Reserved for future use — right now we don't have anything to
     * copy out of the notification into `context` at this layer. The
     * outgoing message's X-Mailgun-Variables (announcement_id,
     * delivery_id, etc.) is already stored on the message itself and
     * comes back on the webhook, which is where correlation actually
     * happens.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function contextFrom(array $data): ?array
    {
        return null;
    }

    private function injectMailgunVariable(Email $email, string $key, mixed $value): void
    {
        $headers = $email->getHeaders();
        $existing = $headers->get('X-Mailgun-Variables');

        $vars = [];
        if ($existing !== null) {
            $decoded = json_decode($existing->getBodyAsString(), true);
            if (is_array($decoded)) {
                $vars = $decoded;
            }
            $headers->remove('X-Mailgun-Variables');
        }

        $vars[$key] = $value;

        $headers->addTextHeader(
            'X-Mailgun-Variables',
            json_encode($vars, JSON_UNESCAPED_SLASHES),
        );
    }
}
