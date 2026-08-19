<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\MemberInvitationNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\AuditLogService;
use App\Support\MailgunPause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

/**
 * Admin outbox — every email the app has attempted to send, with
 * per-message status driven by the Mailgun webhook.
 *
 * Outstanding rows (queued orphans / hard fails) can be resent when we
 * can reconstruct the notification, or marked complete so they stop
 * looking like work still to do.
 *
 * Access is gated at the route layer to developer / owner / exco / admin.
 * Password-reset / OTP / invitation bodies are redacted at insert time.
 */
class EmailLogController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->input('status');
        $search = $request->input('search');
        $notificationClass = $request->input('notification_class');

        $logs = EmailLog::query()
            ->status($status)
            ->recipientLike($search)
            ->when($notificationClass, fn ($q, $cls) => $q->where('notification_class', $cls))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString();

        $counts = EmailLog::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // Available notification-class filter values (distinct classes in
        // the log). Sorted by frequency descending so the noisy senders
        // are at the top of the dropdown.
        $notificationClasses = EmailLog::query()
            ->whereNotNull('notification_class')
            ->selectRaw('notification_class, COUNT(*) as aggregate')
            ->groupBy('notification_class')
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'notification_class');

        $mailgunPausedUntil = app(MailgunPause::class)->pausedUntil();

        return view('email-logs.index', compact('logs', 'counts', 'notificationClasses', 'status', 'search', 'notificationClass', 'mailgunPausedUntil'));
    }

    public function show(EmailLog $emailLog): View
    {
        return view('email-logs.show', [
            'log' => $emailLog->load('user'),
            'mailgunPausedUntil' => app(MailgunPause::class)->pausedUntil(),
        ]);
    }

    public function dismiss(Request $request, EmailLog $emailLog, AuditLogService $audit): RedirectResponse
    {
        if (! $emailLog->isOutstanding()) {
            return back()->with('error', 'Only queued or failed emails can be marked complete.');
        }

        $emailLog->markDismissed('Marked complete by operator');

        $audit->log(
            $request->user(),
            'email_log.dismissed',
            'EmailLog',
            $emailLog->id,
            ['status' => EmailLog::STATUS_QUEUED],
            ['status' => EmailLog::STATUS_DISMISSED],
            "Dismissed {$emailLog->to_email} / {$emailLog->subject}",
        );

        return back()->with('success', "Marked complete — {$emailLog->to_email} will not be resent.");
    }

    public function dismissQueued(Request $request, AuditLogService $audit): RedirectResponse
    {
        $ids = EmailLog::query()
            ->where('status', EmailLog::STATUS_QUEUED)
            ->pluck('id');

        $count = $ids->count();

        if ($count === 0) {
            return back()->with('success', 'No queued emails to mark complete.');
        }

        EmailLog::query()
            ->whereIn('id', $ids)
            ->update([
                'status' => EmailLog::STATUS_DISMISSED,
                'error' => 'Marked complete by operator',
                'updated_at' => now(),
            ]);

        $audit->log(
            $request->user(),
            'email_log.dismissed_queued',
            'EmailLog',
            null,
            null,
            ['count' => $count],
            "Dismissed {$count} queued email log(s)",
        );

        return back()->with('success', "Marked {$count} queued email".($count === 1 ? '' : 's').' complete.');
    }

    public function resend(Request $request, EmailLog $emailLog, AuditLogService $audit, MailgunPause $mailgunPause): RedirectResponse
    {
        if (! $emailLog->canResend()) {
            return back()->with('error', 'This email cannot be resent from the log. Password resets and invitations can; announcements should be resent from the announcement itself.');
        }

        try {
            $mailgunPause->assertAvailable();

            match ($emailLog->notification_class) {
                ResetPasswordNotification::class => $this->resendPasswordReset($emailLog),
                MemberInvitationNotification::class => $this->resendInvitation($emailLog),
                default => throw new \RuntimeException('Unsupported notification class.'),
            };
        } catch (Throwable $e) {
            $mailgunPause->rememberFromError($e->getMessage());
            $this->failFreshQueuedOrphan($emailLog, $e->getMessage());

            return back()->with('error', 'Resend failed: '.$e->getMessage());
        }

        $emailLog->markDismissed('Superseded by a resend');

        $audit->log(
            $request->user(),
            'email_log.resent',
            'EmailLog',
            $emailLog->id,
            null,
            ['to' => $emailLog->to_email, 'notification' => $emailLog->notification_class],
            "Resent {$emailLog->notification_class} to {$emailLog->to_email}",
        );

        return back()->with('success', "Resent to {$emailLog->to_email}. The previous queued attempt is marked complete.");
    }

    private function resendPasswordReset(EmailLog $emailLog): void
    {
        $status = Password::sendResetLink(['email' => $emailLog->to_email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw new \RuntimeException(__($status));
        }
    }

    private function resendInvitation(EmailLog $emailLog): void
    {
        $user = $this->recipientUser($emailLog);

        if ($user->is_managed_account) {
            throw new \RuntimeException('Managed family accounts are activated by their guardian, not by invitation.');
        }

        $token = $user->generateInvitationToken();
        $user->notify(new MemberInvitationNotification($token));
    }

    private function recipientUser(EmailLog $emailLog): User
    {
        $user = $emailLog->user_id
            ? User::query()->find($emailLog->user_id)
            : User::query()->where('email', $emailLog->to_email)->first();

        if (! $user) {
            throw new \RuntimeException('No user account matches this recipient.');
        }

        if (blank($user->email)) {
            throw new \RuntimeException('Recipient has no email address on file.');
        }

        return $user;
    }

    /**
     * A failed resend still fires MessageSending, which inserts a new
     * queued row. Left alone that looks like more work and invites
     * another Resend click — which restarts the Mailgun lock.
     */
    private function failFreshQueuedOrphan(EmailLog $original, string $error): void
    {
        EmailLog::query()
            ->where('to_email', $original->to_email)
            ->where('status', EmailLog::STATUS_QUEUED)
            ->where('id', '!=', $original->id)
            ->where('created_at', '>=', now()->subMinute())
            ->update([
                'status' => EmailLog::STATUS_FAILED,
                'error' => mb_substr($error, 0, 1000),
                'failed_at' => now(),
            ]);
    }
}
