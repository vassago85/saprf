<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\ContactMessageReceivedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Public contact form. Every submission is persisted to `contact_messages`
 * so nothing is lost if outbound mail is misconfigured. Spam protection is
 * threefold:
 *   1. `hp_field`  — honeypot input hidden with CSS. Real users never see or
 *      fill it; bots that parse the form and fill every input trip it.
 *   2. `hp_started_at` — a hidden timestamp captured when the form is
 *      rendered. Submissions faster than MIN_FILL_SECONDS look bot-like.
 *   3. `RateLimiter` — 5 submissions per IP per hour to blunt volumetric
 *      abuse.
 *
 * Spam hits are stored (with `spam_status`) but never trigger a
 * notification — they let us audit false positives without leaking the
 * signal that our filter caught them.
 */
class ContactController extends Controller
{
    private const MIN_FILL_SECONDS = 3;
    private const RATE_LIMIT_PER_HOUR = 5;

    public function create(): View
    {
        return view('contact.create', [
            'started_at' => (string) now()->getTimestamp(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'surname' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'confirmed'],
            'email_confirmation' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            // Both hidden anti-bot fields tolerate absence in validation
            // so we can decide their fate ourselves below (and record a
            // spam_status) instead of showing "field required" errors on
            // legit users if JavaScript strips them.
            'hp_field' => ['nullable', 'string', 'max:255'],
            'hp_started_at' => ['nullable', 'string', 'max:20'],
        ]);

        $spamStatus = $this->classifyForSpam($request);

        $limitKey = 'contact-form:'.$request->ip();
        if (RateLimiter::tooManyAttempts($limitKey, self::RATE_LIMIT_PER_HOUR)) {
            return back()
                ->withInput()
                ->with('error', 'You have submitted too many messages in the last hour. Please try again later.');
        }
        RateLimiter::hit($limitKey, 3600);

        $message = ContactMessage::create([
            'first_name' => $validated['first_name'],
            'surname' => $validated['surname'],
            'email' => $validated['email'],
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'spam_status' => $spamStatus,
        ]);

        if ($spamStatus === ContactMessage::SPAM_CLEAN) {
            $this->notifyAdmins($message);
        }

        // Show the same "thanks" flash whether the submission was flagged
        // as spam or not — never confirm to a spambot that its trick
        // worked or didn't.
        return redirect()->route('contact.thanks');
    }

    public function thanks(): View
    {
        return view('contact.thanks');
    }

    // ── Admin views ─────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ContactMessage::class);

        $status = $request->query('status', 'clean');
        $handled = $request->query('handled', 'unhandled');

        $messages = ContactMessage::query()
            ->when(in_array($status, ['clean', 'honeypot', 'too_fast'], true), fn ($q) => $q->where('spam_status', $status))
            ->when($handled === 'unhandled', fn ($q) => $q->whereNull('handled_at'))
            ->when($handled === 'handled', fn ($q) => $q->whereNotNull('handled_at'))
            ->with('handler:id,name')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('contact-messages.index', [
            'messages' => $messages,
            'filters' => compact('status', 'handled'),
            'counts' => [
                'clean_unhandled' => ContactMessage::query()->clean()->unhandled()->count(),
                'spam' => ContactMessage::query()->where('spam_status', '!=', ContactMessage::SPAM_CLEAN)->count(),
            ],
        ]);
    }

    public function show(ContactMessage $contactMessage): View
    {
        Gate::authorize('view', $contactMessage);

        return view('contact-messages.show', ['message' => $contactMessage->load('handler:id,name')]);
    }

    public function markHandled(Request $request, ContactMessage $contactMessage): RedirectResponse
    {
        Gate::authorize('update', $contactMessage);

        $data = $request->validate([
            'handled_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $contactMessage->update([
            'handled_at' => now(),
            'handled_by' => $request->user()->id,
            'handled_notes' => $data['handled_notes'] ?? null,
        ]);

        return redirect()->route('contact-messages.show', $contactMessage)
            ->with('success', 'Marked as handled.');
    }

    public function reopen(Request $request, ContactMessage $contactMessage): RedirectResponse
    {
        Gate::authorize('update', $contactMessage);

        $contactMessage->update([
            'handled_at' => null,
            'handled_by' => null,
        ]);

        return redirect()->route('contact-messages.show', $contactMessage)
            ->with('success', 'Reopened.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Classify the submission for spam heuristics. We check the honeypot
     * first because a positive there is the strongest signal — a real
     * human never populates the hidden input.
     */
    private function classifyForSpam(Request $request): string
    {
        $hp = (string) $request->input('hp_field', '');
        if ($hp !== '') {
            return ContactMessage::SPAM_HONEYPOT;
        }

        $startedAt = (int) $request->input('hp_started_at', 0);
        if ($startedAt > 0 && (now()->getTimestamp() - $startedAt) < self::MIN_FILL_SECONDS) {
            return ContactMessage::SPAM_TOO_FAST;
        }

        return ContactMessage::SPAM_CLEAN;
    }

    /**
     * Notify every developer / owner / admin / exco user via mail. Uses
     * Notification::send() rather than a shared inbox so if you rotate
     * staff nobody has to remember to update a mailing list.
     */
    private function notifyAdmins(ContactMessage $message): void
    {
        $recipients = User::role(['developer', 'owner', 'admin', 'exco'])
            ->whereNotNull('email')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, new ContactMessageReceivedNotification($message));
        } catch (\Throwable $e) {
            // Notification failure must not swallow the user's message —
            // the row is already persisted so admins can still see it in
            // the admin index.
            logger()->warning('Contact form notification failed: '.$e->getMessage(), [
                'contact_message_id' => $message->id,
            ]);
        }
    }
}
