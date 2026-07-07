<?php

namespace App\Observers;

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use Illuminate\Support\Facades\Log;

/**
 * When a membership becomes valid (status → active AND payment_status → paid/waived
 * AND expiry_date in the future), retroactively promote any of that shooter's
 * 'pending' scores that fall within their new membership window.
 *
 * This covers both the PayFast webhook (PaymentController) and admin manual
 * updates — both go through Membership::save().
 */
class MembershipObserver
{
    public function updated(Membership $membership): void
    {
        if (! $this->wasJustActivated($membership)) {
            return;
        }

        try {
            $affectedMatchIds = app(ScoreValidationService::class)
                ->resolvePendingScoresForUser($membership->user_id);

            if (! $affectedMatchIds) {
                return;
            }

            $standings = app(StandingsCalculationService::class);
            foreach (MatchEvent::whereIn('id', $affectedMatchIds)->get() as $match) {
                $standings->recalculateForMatch($match);
            }
        } catch (\Throwable $e) {
            Log::warning('MembershipObserver: retroactive score promotion failed', [
                'membership_id' => $membership->id,
                'user_id' => $membership->user_id,
                'error' => $e->getMessage(),
            ]);
        }
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
