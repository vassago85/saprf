<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Score;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves the recipient list for the "scores are complete" notification.
 *
 * Two reasons a user ends up on the list:
 *
 *   1. Own match — they shot it. "Shot it" is defined as having a
 *      scores.user_id row OR a non-cancelled match_registrations row.
 *      Walk-ins and manually matched score rows both pick up here.
 *      Preference: `notify_own_match_scores` (defaults to true for
 *      every user, including users with no prefs row yet).
 *
 *   2. Series subscriber — they want every finalised match in a
 *      discipline, even ones they didn't shoot. Explicit opt-in,
 *      defaults to false. Preference: `notify_all_prs_scores` or
 *      `notify_all_pr22_scores` depending on `matches.match_type`.
 *
 * We de-dupe by user_id so a shooter who both shot the match and has
 * the series feed on only gets one notification per channel.
 *
 * Users with no notification_preferences row at all still receive the
 * own-match notification (defaults mirror the migration), but do not
 * get series feeds — those are opt-in only.
 */
class ScoreNotificationAudience
{
    /**
     * @return Collection<int, User>  users eligible for notification
     */
    public function resolve(MatchEvent $match): Collection
    {
        $ownIds = $this->ownMatchUserIds($match);
        $seriesIds = $this->seriesSubscriberUserIds($match);

        $allIds = $ownIds->merge($seriesIds)->unique()->values();

        if ($allIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->with('notificationPreference')
            ->whereIn('id', $allIds)
            ->get()
            ->filter(fn (User $user) => $this->shouldReceive($user, $match, $ownIds->contains($user->id)))
            ->values();
    }

    /**
     * User ids that are directly tied to the match: either they have a
     * score row linked to their account, or they registered (and did
     * not cancel). Walk-ins register as part of score import so they
     * surface here too.
     *
     * Public so the notify job can tag each recipient with their reason
     * (own match vs series feed) in the mail copy without re-querying.
     *
     * @return Collection<int, int>
     */
    public function ownMatchUserIds(MatchEvent $match): Collection
    {
        $fromScores = Score::query()
            ->where('match_id', $match->id)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        $fromRegistrations = MatchRegistration::query()
            ->where('match_id', $match->id)
            ->whereNotNull('user_id')
            ->where('registration_status', '!=', 'cancelled')
            ->pluck('user_id');

        return $fromScores->merge($fromRegistrations)->unique()->values();
    }

    /**
     * Users who subscribed to every finalised match in the discipline.
     * Only `PRS` / `PR22` match types are supported — anything else
     * (custom matches, inter-club exhibitions, etc.) can only reach
     * shooters via the own-match path.
     *
     * @return Collection<int, int>
     */
    private function seriesSubscriberUserIds(MatchEvent $match): Collection
    {
        $prefColumn = match (strtoupper((string) $match->match_type)) {
            'PRS' => 'notify_all_prs_scores',
            'PR22' => 'notify_all_pr22_scores',
            default => null,
        };

        if ($prefColumn === null) {
            return collect();
        }

        return User::query()
            ->whereHas('notificationPreference', fn ($q) => $q->where($prefColumn, true))
            ->pluck('id');
    }

    /**
     * Final gate: does this user want *anything* at all for this match?
     * Series subscribers must have the specific toggle on; own-match
     * recipients fall back to the migration default (true) when they
     * have no prefs row.
     */
    private function shouldReceive(User $user, MatchEvent $match, bool $isOwnMatch): bool
    {
        $pref = $user->notificationPreference;

        if ($isOwnMatch) {
            // No prefs row yet = never opted anything in or out =
            // default-on, same as the migration default. We still send
            // the one-time prompt on their next sign-in so they can
            // opt out going forward.
            return $pref === null
                ? true
                : $pref->wantsOwnMatchScoreNotifications();
        }

        // Series subscriber branch. Pref row must exist AND the matching
        // series toggle must be on.
        return $pref !== null
            && $pref->wantsSeriesScoreNotifications((string) $match->match_type);
    }
}
