<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Navigation\SidebarMenu;
use App\Notifications\ContactMessageReceivedNotification;
use App\Notifications\ContactMessageReplyNotification;
use App\Services\StaffInboxService;
use App\Services\TurnstileService;
use App\Support\MailgunPause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

    /**
     * Two or more URLs in a single contact-form message is virtually never
     * a legitimate enquiry — real members do not drop link-fountains on
     * the SAPRF secretary. Lower threshold = too eager (members do
     * occasionally paste one supporting link); 2 is the sweet spot.
     */
    private const URL_FLOOD_THRESHOLD = 2;

    /**
     * Email-domain blocklist for disposable / throwaway inboxes that
     * virtually no legitimate shooter would use to contact the federation.
     * We match the final two-or-more labels so `foo.bar.fastemail.digital`
     * is caught the same way as `bar@fastemail.digital`.
     *
     * Keep the list short and focused on domains we've actually seen abuse
     * from — a bloated blocklist starts catching real members on quirky
     * TLDs. Add new entries when a new wave of spam picks a fresh host.
     *
     * @var list<string>
     */
    private const DISPOSABLE_EMAIL_DOMAINS = [
        'fastemail.digital',
        'mailinator.com',
        'mailinator.net',
        'tempmail.com',
        'tempmail.net',
        'guerrillamail.com',
        'guerrillamail.net',
        'guerrillamail.org',
        'guerrillamail.biz',
        '10minutemail.com',
        'yopmail.com',
        'trashmail.com',
        'getnada.com',
        'maildrop.cc',
        'sharklasers.com',
        'throwawaymail.com',
    ];

    /**
     * Case-insensitive substring triggers. Any single match flags the
     * message as `SPAM_KEYWORD`. Keep the list conservative — a single
     * false positive from a chatty member asking about women's shooting
     * grades (e.g. "women/female") would be worse than letting through
     * one more porn pitch.
     *
     * The current list is tuned to the SEO-ring porn / pharma / crypto
     * spam we've actually seen hit the inbox since launch.
     *
     * @var list<string>
     */
    private const SPAM_KEYWORDS = [
        // SEO porn ring
        'porn', 'xxx ', 'jav uncensored', 'anal ', 'adult cam',
        'nude pics', 'fuck ', 'milf', 'escorts in',
        // Pharma spam
        'viagra', 'cialis', 'canadian pharmacy',
        // Classic SEO shills
        'buy backlinks', 'guest post opportunity', 'niche blog comment',
        'crypto airdrop', 'bitcoin doubler',
    ];

    public function create(): View
    {
        return view('contact.create', [
            'started_at' => (string) now()->getTimestamp(),
        ]);
    }

    public function store(Request $request, TurnstileService $turnstile): RedirectResponse
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
            // Cloudflare auto-injects the token into a form field named
            // `cf-turnstile-response`. Nullable here for the same reason
            // as the honeypot fields — we classify silently rather than
            // show a required-field error to a real user whose Turnstile
            // widget failed to load.
            'cf-turnstile-response' => ['nullable', 'string', 'max:4096'],
        ]);

        $spamStatus = $this->classifyForSpam($request, $turnstile);

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

            // A fresh clean enquiry bumps the unhandled count by one.
            // Drop the shared sidebar badge cache so every admin's next
            // sidebar render shows the new total instead of waiting out
            // the 60-second TTL. See SidebarMenu::GLOBAL_BADGES.
            SidebarMenu::clearBadge('unhandled_contacts');
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

        // Self-heal the sidebar badge: whatever an admin lands on this page
        // with, we recompute the authoritative count below. Dropping the
        // cache now guarantees the next sidebar render reflects reality
        // instead of a stale 60s-TTL copy. Mirrors ApprovalController::index.
        SidebarMenu::clearBadge('unhandled_contacts');

        // query('key', $default) uses ?? internally, so ConvertEmptyStringsToNull
        // turns the form's "All" (value="") into null and then into the default —
        // making All impossible. Only apply defaults when the key is absent.
        $status = $request->query->has('status')
            ? ($request->query->get('status') ?? '')
            : 'clean';
        $handled = $request->query->has('handled')
            ? ($request->query->get('handled') ?? '')
            : 'unhandled';

        $validStatuses = array_merge(['clean'], array_keys(ContactMessage::spamStatusOptions()));

        $messages = ContactMessage::query()
            ->when(in_array($status, $validStatuses, true), fn ($q) => $q->where('spam_status', $status))
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

    public function reply(Request $request, ContactMessage $contactMessage, MailgunPause $mailgunPause): RedirectResponse
    {
        Gate::authorize('update', $contactMessage);

        if ($contactMessage->spam_status !== ContactMessage::SPAM_CLEAN) {
            return back()->with('error', 'Replies can only be sent for clean enquiries.');
        }

        $data = $request->validate([
            'reply' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        $mailgunPause->assertAvailable();

        try {
            Notification::route('mail', $contactMessage->email)
                ->notify(new ContactMessageReplyNotification(
                    $contactMessage,
                    $data['reply'],
                    $request->user(),
                ));
        } catch (\Throwable $e) {
            $mailgunPause->rememberFromError($e->getMessage());

            return back()
                ->withInput()
                ->with('error', 'Reply failed to send: '.$e->getMessage());
        }

        return redirect()->route('contact-messages.show', $contactMessage)
            ->with('success', 'Reply sent to '.$contactMessage->email.'.');
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

        // Marking as handled drops the unhandled count by one — flush the
        // shared sidebar badge cache so the "2" (or whatever) in the sidebar
        // snaps down on the next render instead of lingering for up to 60s.
        SidebarMenu::clearBadge('unhandled_contacts');

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

        // Reopening pushes the unhandled count back up — same cache flush as
        // markHandled() so the sidebar badge immediately reflects the change.
        SidebarMenu::clearBadge('unhandled_contacts');

        return redirect()->route('contact-messages.show', $contactMessage)
            ->with('success', 'Reopened.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Classify the submission for spam heuristics. Order matters: cheapest /
     * strongest signals first so a honeypot hit short-circuits before we
     * burn a round-trip to Cloudflare for Turnstile verification.
     *
     * All signals return a `SPAM_*` constant that gets persisted on the row.
     * The caller treats anything other than `SPAM_CLEAN` as "store but don't
     * notify" — so false positives sit in the admin inbox under a filter
     * and can be reviewed, never silently dropped.
     */
    private function classifyForSpam(Request $request, TurnstileService $turnstile): string
    {
        // 1. Honeypot — a real human never populates the hidden input.
        $hp = (string) $request->input('hp_field', '');
        if ($hp !== '') {
            return ContactMessage::SPAM_HONEYPOT;
        }

        // 2. Min fill time — bots that paste the whole form fire inside a
        //    second or two. Humans take at least three.
        $startedAt = (int) $request->input('hp_started_at', 0);
        if ($startedAt > 0 && (now()->getTimestamp() - $startedAt) < self::MIN_FILL_SECONDS) {
            return ContactMessage::SPAM_TOO_FAST;
        }

        // 3. Turnstile — strongest signal when configured. Checked before
        //    content heuristics so a bot that passes everything else still
        //    gets caught, and skipped entirely when Turnstile isn't
        //    configured (verify() returns true in that case).
        if (! $turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            return ContactMessage::SPAM_TURNSTILE_FAILED;
        }

        // 4. Disposable-email domain — fastemail.digital, mailinator, etc.
        $email = strtolower((string) $request->input('email', ''));
        if ($email !== '' && $this->isDisposableEmail($email)) {
            return ContactMessage::SPAM_DISPOSABLE_EMAIL;
        }

        // 5. URL flood — contact-form porn/SEO spam always carries at
        //    least one link, usually two. Combine subject + body so a
        //    bot that splits the payload across both fields is caught.
        $body = (string) $request->input('message', '');
        $subject = (string) $request->input('subject', '');
        if ($this->countUrls($subject . "\n" . $body) >= self::URL_FLOOD_THRESHOLD) {
            return ContactMessage::SPAM_URL_FLOOD;
        }

        // 6. Keyword list — brittle by design, kept narrow to porn / pharma
        //    / SEO shill wording we've actually seen.
        if ($this->matchesSpamKeyword($subject . ' ' . $body)) {
            return ContactMessage::SPAM_KEYWORD;
        }

        return ContactMessage::SPAM_CLEAN;
    }

    /**
     * True when the email address uses one of the known disposable-mail
     * hosts. Matches on the right-most two labels so a tenant-style
     * subdomain (foo.bar.fastemail.digital) catches the same way as the
     * apex (bar@fastemail.digital).
     */
    private function isDisposableEmail(string $email): bool
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return false;
        }
        $domain = strtolower(trim($parts[1]));

        foreach (self::DISPOSABLE_EMAIL_DOMAINS as $blocked) {
            if ($domain === $blocked || Str::endsWith($domain, '.' . $blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count distinct URL-like substrings in a block of text. Deliberately
     * loose: anything starting with http(s)://, www., or anything that
     * looks like `domain.tld/path` counts. False positives on technical
     * questions ("see page 2.5/foo of the handbook") are tolerated — the
     * row is still stored, just not notified about, so an admin can
     * reclassify from the inbox if a real member happens to trip it.
     */
    private function countUrls(string $text): int
    {
        $patterns = [
            '~\bhttps?://\S+~i',
            '~\bwww\.[a-z0-9.-]+\.[a-z]{2,}\b~i',
            // bare-domain fallback — catches `fastemail.digital/kirsten-...`
            // style patterns that drop the scheme to dodge trivial filters.
            '~\b[a-z0-9][a-z0-9-]*\.(?:com|net|org|digital|top|online|click|xyz|info|biz|rest|site|shop|store|live)\b/\S*~i',
        ];

        $count = 0;
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                $count += count($matches[0]);
            }
        }

        return $count;
    }

    /**
     * True when `$text` contains any of the SPAM_KEYWORDS as a
     * case-insensitive substring. Keep the keyword list in a single
     * place (this class) and tighten it only when a real false positive
     * is reported — never pre-emptively.
     */
    private function matchesSpamKeyword(string $text): bool
    {
        $haystack = strtolower($text);
        foreach (self::SPAM_KEYWORDS as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Notify the configured secretary inbox (and developers). Falls
     * back to every developer / owner / admin / exco user when that
     * Site Settings address has not been set yet.
     */
    private function notifyAdmins(ContactMessage $message): void
    {
        try {
            app(StaffInboxService::class)->notify(
                new ContactMessageReceivedNotification($message),
                ['developer', 'owner', 'admin', 'exco'],
                includeSecretaryInbox: true,
            );
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
