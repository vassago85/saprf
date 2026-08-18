<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After the transport has accepted a message, flip the matching
 * `email_logs` row from `queued` to `sent` and capture the transport's
 * message-id if it returned one (Mailgun does; the `log` mailer does
 * not). Correlation is by the `email_log_id` we injected into
 * X-Mailgun-Variables in LogSendingMail, which comes back on the
 * message headers of the sent event.
 */
class LogSentMail
{
    public function handle(MessageSent $event): void
    {
        try {
            $email = $event->message;
            $logId = $this->extractLogId($email);
            if ($logId === null) {
                return;
            }

            $log = EmailLog::query()->find($logId);
            if ($log === null) {
                return;
            }

            $messageId = $email->getHeaders()->get('Message-Id')?->getBodyAsString();
            if ($messageId !== null) {
                $messageId = trim($messageId, ' <>');
            }

            $log->forceFill([
                'status' => EmailLog::STATUS_SENT,
                'sent_at' => now(),
                'message_id' => $messageId,
            ])->save();
        } catch (Throwable $e) {
            Log::warning('LogSentMail failed to update row', ['error' => $e->getMessage()]);
        }
    }

    private function extractLogId(\Symfony\Component\Mime\Email $email): ?int
    {
        $header = $email->getHeaders()->get('X-Mailgun-Variables');
        if ($header === null) {
            return null;
        }

        $decoded = json_decode($header->getBodyAsString(), true);
        if (! is_array($decoded)) {
            return null;
        }

        $id = $decoded['email_log_id'] ?? null;
        return $id === null ? null : (int) $id;
    }
}
