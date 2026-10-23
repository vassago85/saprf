<?php

namespace App\Observers;

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a shooter's score classifications in sync with their membership record
 * — but only ever in the direction that resolves a *pending* score. Historical
 * scores that have already been judged (`valid`, `lapsed`, `non_member`) are
 * NEVER auto-demoted by a later membership edit. The rule is simple:
 *
 *   "The past does not get rewritten. A shooter who was paid up when they shot
 *    a match keeps that score forever. An admin edit on their membership
 *    record today cannot retroactively knock scores off last season's log."
 *
 * The only automatic path is PROMOTION: when a membership becomes valid
 * (payment confirmed, status active, expiry in the future), 'pending' grace-
 * window scores get promoted to 'valid'. Pending means the system has not yet
 * taken a position on the score — resolving them forward is fine.
 *
 * Every DEMOTION path (admin flips to free, admin revokes, admin shrinks the
 * paid window, renewal rewrites start_date, etc.) is intentionally NOT handled
 * here. If an admin genuinely needs past scores re-classified against the new
 * membership state (e.g. a member was revoked for cheating and their results
 * should be struck), that is an explicit, audited action:
 *
 *     php artisan scores:reevaluate --user=<id>
 *
 * Keeping demotion behind that command stops renewal checkouts, data cleanups,
 * and typo fixes from silently wiping a shooter's season off the standings.
 */
class MembershipObserver
{
    public function updated(Membership $membership): void
    {
        try {
            $affectedMatchIds = $this->syncScoresForMembership($membership);

            if (! $affectedMatchIds) {
                return;
            }

            $standings = app(StandingsCalculationService::class);
            foreach (MatchEvent::whereIn('id', $affectedMatchIds)->get() as $match) {
                $standings->recalculateForMatch($match);
            }
        } catch (\Throwable $e) {
            Log::warning('MembershipObserver: retroactive score sync failed', [
                'membership_id' => $membership->id,
                'user_id' => $membership->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int, int> Match IDs whose standings need recomputing.
     */
    private function syncScoresForMembership(Membership $membership): array
    {
        if (! $this->wasJustActivated($membership)) {
            return [];
        }

        return app(ScoreValidationService::class)
            ->resolvePendingScoresForUser($membership->user_id);
    }

    private function wasJustActivated(Membership $membership): bool
    {
        $becameActive = $membership->wasChanged('status') && $membership->status === 'active';
        $becamePaid = $membership->wasChanged('payment_status')
            && in_array($membership->payment_status, ['paid', 'waived'], true);

        if (! $becameActive && ! $becamePaid) {
            return false;
        }

        $nowValid = $membership->status === 'active'
            && in_array($membership->payment_status, ['paid', 'waived'], true)
            && $membership->expiry_date
            && $membership->expiry_date->gte(now()->startOfDay());

        return $nowValid;
    }
}
