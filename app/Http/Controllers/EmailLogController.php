<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only admin outbox — every email the app has attempted to send,
 * with per-message status driven by the Mailgun webhook.
 *
 * Access is gated at the route layer to developer / owner / exco.
 * The show view surfaces the HTML body for non-sensitive notifications;
 * for password-reset / OTP / invitation classes the body is redacted
 * at insert time (LogSendingMail) so it's not available here either.
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

        return view('email-logs.index', compact('logs', 'counts', 'notificationClasses', 'status', 'search', 'notificationClass'));
    }

    public function show(EmailLog $emailLog): View
    {
        return view('email-logs.show', ['log' => $emailLog->load('user')]);
    }
}
