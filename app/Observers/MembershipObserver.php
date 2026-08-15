<?php

namespace App\Observers;

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a shooter's score classifications in sync with their membership record
 * so nobody sees a stale "LAPSED" pill on a scoreboard next to someone the
 * admin has since flagged as a free registrant, or vice versa.
 *
 * Two distinct paths, deliberately asymmetric:
 *
 * 1. PROMOTION (membership becomes valid): only 'pending' scores get promoted
 *    to 'valid'. We deliberately do NOT touch 'non_member' scores — joining or
 *    paying today must not retroactively backdate credit for a match a shooter
 *    shot as a genuine non-member.
 *
 * 2. DEMOTION (membership is downgraded — type flipped to 'free', status set
 *    to 'revoked', or the paid window shrinks past scored matches): re-evaluate
 *    ALL of that shooter's scores. This closes the gap where admin cleanups
 *    (e.g. correcting a mis-imported "paid" member to "free") left old scores
 *    stranded with a lapsed/valid label that no longer matches their record.
 *
 * For a wider admin correction that legitimately widens the window (backdating
 * start_date, extending expiry_date to cover earlier matches), use the
 * explicit audited path: `php artisan scores:reevaluate --user=<id>`. That
 * expansion case stays manual on purpose so someone has to sign off on
 * backdating.
 *
 * Both paths cover the PayFast webhook and admin manual updates — both go
 * through Membership::save().
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
        $service = app(ScoreValidationService::class);

        if ($this->wasJustActivated($membership)) {
            return $service->resolvePendingScoresForUser($membership->user_id);
        }

        if ($this->wasJustDemoted($membership)) {
            return $service->reevaluateScoresForUser($membership->user_id);
        }

        return [];
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

    /**
     * Any change that makes past scores less valid than they were classified.
     * Covers the three real-world cases:
     *   - Admin fixes a mis-imported paying member to "Free"
     *     (membership_type changed to 'free')
     *   - Membership gets revoked (status changed to 'revoked')
     *   - Paid window is shortened (expiry_date pulled earlier, start_date
     *     pushed later), potentially stranding scores outside the new window.
     */
    private function wasJustDemoted(Membership $membership): bool
    {
        if ($membership->wasChanged('membership_type') && $membership->membership_type === 'free') {
            return true;
        }

        if ($membership->wasChanged('status') && $membership->status === 'revoked') {
            return true;
        }

        // Window shrunk on either side. We only care about SHRINKAGE — expansion
        // (backdate/extend) is intentionally left to the explicit
        // `scores:reevaluate --user=` command so someone signs off on giving a
        // shooter retroactive credit for older matches.
        if ($membership->wasChanged('expiry_date')) {
            $prev = $membership->getOriginal('expiry_date');
            if ($prev && $membership->expiry_date && $membership->expiry_date->lt($prev)) {
                return true;
            }
        }

        if ($membership->wasChanged('start_date')) {
            $prev = $membership->getOriginal('start_date');
            if ($prev && $membership->start_date && $membership->start_date->gt($prev)) {
                return true;
            }
        }

        return false;
    }
}
