<?php

namespace App\Http\Controllers;

use App\Models\MatchAnnouncement;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use App\Notifications\MatchAnnouncementNotification;
use App\Services\AuditLogService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

class MatchAnnouncementController extends Controller
{
    /**
     * Registration statuses that receive the broadcast. Cancelled entrants
     * withdrew — mailing them would be spam. Pending entrants haven't paid
     * and may drop off; MDs can address them separately if needed.
     */
    private const RECIPIENT_STATUS_SCOPE = ['confirmed', 'waitlisted'];

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly SettingsService $settingsService,
    ) {}

    public function create(MatchEvent $match): View
    {
        $this->authorize('update', $match);

        $recipientCount = $this->recipientQuery($match)->count();
        $notificationsEnabled = (bool) $this->settingsService->get('notifications_enabled', true);
        $recentAnnouncements = $match->announcements()
            ->with('sender:id,name')
            ->latest('sent_at')
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

        $entrants = $this->recipientQuery($match)
            ->with(['user', 'registeredBy', 'user.parent'])
            ->get();

        $recipients = $this->resolveRecipients($entrants);

        if ($recipients->isEmpty()) {
            return redirect()
                ->route('matches.announcements.create', $match)
                ->with('error', 'No entrants match the recipient scope (confirmed + waitlisted). Nothing was sent.');
        }

        $announcement = DB::transaction(function () use ($match, $request, $data, $recipients) {
            $announcement = MatchAnnouncement::create([
                'match_id' => $match->id,
                'sender_user_id' => $request->user()->id,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'recipient_count' => $recipients->count(),
                'status_scope' => self::RECIPIENT_STATUS_SCOPE,
                'sent_at' => now(),
            ]);

            $this->auditLogService->log(
                $request->user(),
                'match.announcement.sent',
                'MatchAnnouncement',
                $announcement->id,
                null,
                [
                    'match_id' => $match->id,
                    'subject' => $data['subject'],
                    'recipient_count' => $recipients->count(),
                    'status_scope' => self::RECIPIENT_STATUS_SCOPE,
                ],
            );

            return $announcement;
        });

        Notification::send(
            $recipients,
            new MatchAnnouncementNotification($announcement, $request->user())
        );

        return redirect()
            ->route('matches.show', $match)
            ->with('success', 'Announcement queued for ' . $recipients->count() . ' ' . str('recipient')->plural($recipients->count()) . '.');
    }

    /**
     * Registrations still on the entry list that should receive the mail.
     */
    private function recipientQuery(MatchEvent $match)
    {
        return $match->registrations()
            ->whereIn('registration_status', self::RECIPIENT_STATUS_SCOPE);
    }

    /**
     * Turn an entrant collection into a deduplicated list of `User`s to
     * notify. Managed juniors carry a placeholder email — we route their
     * mail to the parent (via `registered_by_user_id`, falling back to
     * `parent_id`), matching how MatchRegistrationConfirmedNotification is
     * dispatched. Entries with no reachable recipient are dropped and
     * logged.
     *
     * @param  Collection<int, MatchRegistration>  $entrants
     * @return Collection<int, User>
     */
    private function resolveRecipients(Collection $entrants): Collection
    {
        return $entrants
            ->map(function (MatchRegistration $registration) {
                $entrant = $registration->user;

                if (! $entrant) {
                    Log::warning('MatchAnnouncement: registration has no user', [
                        'registration_id' => $registration->id,
                    ]);

                    return null;
                }

                if (! $entrant->isManaged()) {
                    return $entrant;
                }

                $parent = $registration->registeredBy ?: $entrant->parent;

                if (! $parent) {
                    Log::warning('MatchAnnouncement: managed junior has no reachable parent', [
                        'registration_id' => $registration->id,
                        'entrant_user_id' => $entrant->id,
                    ]);

                    return null;
                }

                return $parent;
            })
            ->filter()
            ->unique('id')
            ->values();
    }
}
