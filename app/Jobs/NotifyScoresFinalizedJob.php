<?php

namespace App\Jobs;

use App\Models\MatchEvent;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\ScoresFinalizedNotification;
use App\Services\ScoreNotificationAudience;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fan out the "scores are complete" notification for a single match.
 *
 * Guarantees:
 *   - Idempotent: if `scores_finalized_notified_at` is already set we
 *     log a warning and exit without re-sending. The MD workspace
 *     clears the "finalised" flag on unmark but does NOT clear the
 *     notified timestamp — so re-marking a match (typo fix, etc.)
 *     does not re-blast everyone.
 *   - Preference-respecting: delegates audience resolution to
 *     `ScoreNotificationAudience`, which applies the three toggles
 *     (`notify_own_match_scores`, `notify_all_prs_scores`,
 *     `notify_all_pr22_scores`) and the account-level `push_enabled`
 *     switch.
 *   - Fault tolerant: a failure sending to one user is logged and the
 *     next recipient still gets their notification. We only stamp
 *     `scores_finalized_notified_at` after processing completes so a
 *     catastrophic failure (job crash) can be retried.
 */
class NotifyScoresFinalizedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $matchId) {}

    public function handle(ScoreNotificationAudience $audience, WebPushChannel $push): void
    {
        $match = MatchEvent::query()->find($this->matchId);

        if (! $match) {
            Log::warning('NotifyScoresFinalizedJob: match gone', ['match_id' => $this->matchId]);
            return;
        }

        if (! $match->scoresAreFinalized()) {
            Log::info('NotifyScoresFinalizedJob: match no longer finalized, skipping', [
                'match_id' => $match->id,
            ]);
            return;
        }

        if ($match->scores_finalized_notified_at !== null) {
            Log::info('NotifyScoresFinalizedJob: already notified, skipping', [
                'match_id' => $match->id,
                'notified_at' => $match->scores_finalized_notified_at,
            ]);
            return;
        }

        $recipients = $audience->resolve($match);
        // Resolver already queried these once; reuse the same lookup
        // so we don't do a per-user DB round trip just to label mail
        // copy ("you shot this" vs "you follow all PRS").
        $ownUserIds = $audience->ownMatchUserIds($match)->flip();

        $mailSent = 0;
        $pushSent = 0;

        foreach ($recipients as $user) {
            $reason = $ownUserIds->has($user->id) ? 'own' : 'series';

            try {
                if ($this->canReceiveMail($user)) {
                    $user->notify(new ScoresFinalizedNotification($match, $reason));
                    $mailSent++;
                }
            } catch (Throwable $e) {
                Log::warning('NotifyScoresFinalizedJob: mail failed', [
                    'match_id' => $match->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                if ($this->canReceivePush($user)) {
                    $result = $push->sendScoresFinalized($user, $match);
                    $pushSent += $result['sent'] ?? 0;
                }
            } catch (Throwable $e) {
                Log::warning('NotifyScoresFinalizedJob: push failed', [
                    'match_id' => $match->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $match->forceFill(['scores_finalized_notified_at' => now()])->save();

        Log::info('NotifyScoresFinalizedJob: fan-out complete', [
            'match_id' => $match->id,
            'recipients' => $recipients->count(),
            'mail_sent' => $mailSent,
            'push_sent' => $pushSent,
        ]);
    }

    /**
     * We skip addresses Mailgun has previously told us are undeliverable.
     * Mirrors FederationAnnouncementNotification's non-mandatory suppression
     * so we don't grind sender reputation into the ground resending to
     * hard-bounced inboxes.
     */
    private function canReceiveMail(User $user): bool
    {
        if (empty($user->email)) {
            return false;
        }

        if ($user->email_bounced_at !== null || $user->email_complained_at !== null) {
            return false;
        }

        return true;
    }

    private function canReceivePush(User $user): bool
    {
        $pref = $user->notificationPreference;

        // Users with no prefs row default to push_enabled = true (migration
        // default). Only an explicit opt-out silences the channel.
        if ($pref === null) {
            return true;
        }

        return (bool) $pref->push_enabled;
    }
}
