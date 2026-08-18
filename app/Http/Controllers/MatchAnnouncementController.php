<?php

namespace App\Http\Controllers;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementRetention;
use App\Enums\AnnouncementStatus;
use App\Enums\AudienceMode;
use App\Enums\AudienceType;
use App\Models\Announcement;
use App\Models\MatchEvent;
use App\Services\Announcements\AnnouncementPublisher;
use App\Services\Announcements\AudienceResolver;
use App\Services\AuditLogService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Match Director → Entrants bulletin channel.
 *
 * Historically this wrote to its own `match_announcements` table and
 * sent an email-only notification. It now composes a normal
 * `Announcement` row (category = match_bulletin, retention =
 * match_scoped, match_id pinned) and hands off to `AnnouncementPublisher`
 * so MDs get the full federation pipeline for free: push notifications,
 * the Communications inbox, attachments (later), acknowledgement, and
 * retract. When the linked match transitions to `completed` or
 * `cancelled`, the bulletin auto-vanishes from every member view thanks
 * to the retention filter on `Announcement::scopeInbox` /
 * `scopeArchive`.
 *
 * The route + URL is unchanged (`/matches/{match}/announcements/create`
 * → `matches.announcements.create`) so every existing link and audit
 * log entry keeps working. The view is also unchanged; only the
 * controller's write path was rewired.
 */
class MatchAnnouncementController extends Controller
{
    /**
     * Registration statuses that receive the bulletin. Kept identical
     * to the legacy behaviour so unifying doesn't change who is on the
     * recipient list.
     */
    private const RECIPIENT_STATUS_SCOPE = ['confirmed', 'waitlisted'];

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly SettingsService $settingsService,
        private readonly AnnouncementPublisher $publisher,
        private readonly AudienceResolver $resolver,
    ) {}

    public function create(MatchEvent $match): View
    {
        $this->authorize('update', $match);

        // Recipient count comes from the unified audience resolver now,
        // so the pre-send preview here matches exactly what
        // freezeRecipients will produce at send time.
        $recipientCount = $this->resolver
            ->preview([[
                'type' => AudienceType::MatchEntrants,
                'mode' => AudienceMode::Include,
                'value' => [
                    'match_id' => $match->id,
                    'status_scope' => self::RECIPIENT_STATUS_SCOPE,
                ],
            ]])
            ->count;

        $notificationsEnabled = (bool) $this->settingsService->get('notifications_enabled', true);

        // Show the last 10 bulletins the MD sent on this match — pulled
        // from the unified announcements table (where new bulletins land)
        // plus any legacy match_announcements rows the backfill migration
        // populated. Ordered by sent_at so the most recent is on top.
        $recentAnnouncements = Announcement::query()
            ->with('creator:id,name')
            ->where('match_id', $match->id)
            ->where('category', AnnouncementCategory::MatchBulletin->value)
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->limit(10)
            ->get();

        return view('matches.announcements.create', [
            'match' => $match,
            'recipientCount' => $recipientCount,
            'notificationsEnabled' => $notificationsEnabled,
            'recentAnnouncements' => $recentAnnouncements,
        ]);
    }

    public function store(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorize('update', $match);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        // Pre-flight: refuse to send when the entry list is empty. The
        // publisher would silently freeze 0 recipients otherwise, which
        // looks like a success but delivers nothing.
        $preview = $this->resolver->preview([[
            'type' => AudienceType::MatchEntrants,
            'mode' => AudienceMode::Include,
            'value' => [
                'match_id' => $match->id,
                'status_scope' => self::RECIPIENT_STATUS_SCOPE,
            ],
        ]]);

        if ($preview->count === 0) {
            return redirect()
                ->route('matches.announcements.create', $match)
                ->with('error', 'No entrants match the recipient scope (confirmed + waitlisted). Nothing was sent.');
        }

        $recipientCount = $preview->count;

        $announcement = DB::transaction(function () use ($request, $match, $data, $recipientCount) {
            $announcement = Announcement::create([
                'title' => $data['subject'],
                'body' => $data['body'],
                'category' => AnnouncementCategory::MatchBulletin->value,
                'retention' => AnnouncementRetention::MatchScoped->value,
                'match_id' => $match->id,
                'priority' => 'normal',
                'requires_acknowledgement' => false,
                // Leave `deliver_via` null → publisher fans out to every
                // channel (mail + push + in-app). MDs typically want all
                // three; a future improvement can expose channel
                // checkboxes on the MD compose form.
                'deliver_via' => null,
                'status' => AnnouncementStatus::Draft->value,
                'created_by' => $request->user()->id,
            ]);

            $announcement->audiences()->create([
                'type' => AudienceType::MatchEntrants->value,
                'mode' => AudienceMode::Include->value,
                'value' => [
                    'match_id' => $match->id,
                    'status_scope' => self::RECIPIENT_STATUS_SCOPE,
                ],
            ]);

            $this->auditLogService->log(
                $request->user(),
                'match.announcement.sent',
                'Announcement',
                $announcement->id,
                null,
                [
                    'match_id' => $match->id,
                    'subject' => $data['subject'],
                    'recipient_count' => $recipientCount,
                    'status_scope' => self::RECIPIENT_STATUS_SCOPE,
                    'category' => AnnouncementCategory::MatchBulletin->value,
                    'retention' => AnnouncementRetention::MatchScoped->value,
                ],
            );

            return $announcement;
        });

        // Hand off to the standard pipeline. sendNow flips status →
        // sending and dispatches ResolveAudienceJob, which handles the
        // recipient freeze + per-channel fan-out asynchronously.
        $this->publisher->sendNow($announcement);

        return redirect()
            ->route('matches.show', $match)
            ->with(
                'success',
                'Bulletin queued for ' . $preview->count . ' ' . str('entrant')->plural($preview->count) . '. It will land in the Communications inbox and via push/email once the queue runs.'
            );
    }
}
