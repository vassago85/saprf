<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonInterface;

class MembershipValidationService
{
    public function isMembershipValidOnDate(?Membership $membership, CarbonInterface $date): bool
    {
        if (! $membership) {
            return false;
        }

        $isActive = $membership->status === 'active';
        $isPaid = in_array($membership->payment_status, ['paid', 'waived'], true);
        $isNotExpired = $membership->expiry_date && $membership->expiry_date->gte($date->copy()->startOfDay());

        return $isActive && $isPaid && $isNotExpired;
    }

    public function isUserValidForOfficialPurposes(?User $user, CarbonInterface $date): bool
    {
        if (! $user) {
            return false;
        }

        return $this->isMembershipValidOnDate($user->membership, $date);
    }

    public function classifyRegistrationCategory(?User $user, CarbonInterface $matchDate): string
    {
        if (! $user || ! $user->membership) {
            return 'non_member';
        }

        if ($user->membership->membership_type === 'free') {
            return 'non_member';
        }

        if ($this->isMembershipValidOnDate($user->membership, $matchDate)) {
            return 'active_member';
        }

        return 'lapsed_member';
    }

    public function isEligibleForStandings(?User $user): bool
    {
        if (! $user || ! $user->membership) {
            return false;
        }

        return $user->membership->membership_type === 'paid'
            && $user->membership->status === 'active';
    }
}
