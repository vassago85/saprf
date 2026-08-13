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

        // "free" registrants are people who were forced to register just to shoot
        // a single provincial — they are NOT paid-up federation members and must
        // never count for official/standings purposes, regardless of the active/
        // paid flags the legacy import stamped on them.
        if ($membership->membership_type === 'free') {
            return false;
        }

        // Revoked memberships never count, even for dates inside their old window.
        if ($membership->status === 'revoked') {
            return false;
        }

        $isPaid = in_array($membership->payment_status, ['paid', 'waived'], true);
        if (! $isPaid) {
            return false;
        }

        // Validity is a HISTORICAL fact tied to the date being checked, not the
        // membership's current lifecycle label. A membership that has since
        // expired (status = expired/lapsed today) was still valid for a match
        // that fell inside its paid window, so we intentionally do NOT require
        // status === 'active' here — we check the window itself. This keeps a
        // shooter's earlier-season scores intact after their membership expires.
        $day = $date->copy()->startOfDay();
        $startedInTime = ! $membership->start_date || $membership->start_date->lte($day);
        $notYetExpired = $membership->expiry_date && $membership->expiry_date->gte($day);

        return $startedInTime && $notYetExpired;
    }

    /**
     * Fallback inference for "was this person a member at $date" when no
     * historical membership snapshot exists — used ONLY by selection
     * eligibility (ELG-01 / PART-06), never by score/standings classification.
     * isMembershipValidOnDate() stays the strict, historical source of truth;
     * this is a softer read for the case where we simply lack the old records.
     *
     * Returns the reason the inference succeeded ('participation' or
     * 'expiry_workback'), or null if we can't reasonably infer membership.
     *
     * @param  bool  $hasValidParticipation  the athlete has ≥1 VALID score in
     *   the selection period — a valid score already means the system
     *   confirmed paid membership at match time, so it proves membership.
     */
    public function inferredMemberAtDate(?Membership $membership, CarbonInterface $date, bool $hasValidParticipation): ?string
    {
        if (! $membership) {
            return null;
        }

        // Never infer good standing for free registrants or revoked members.
        if ($membership->membership_type === 'free' || $membership->status === 'revoked') {
            return null;
        }

        if ($hasValidParticipation) {
            return 'participation';
        }

        // Memberships run a year. If the annual term implied by the current
        // expiry_date (expiry − 1 year) began on/before $date and still
        // covered $date, they held a membership across the period start.
        $isPaid = in_array($membership->payment_status, ['paid', 'waived'], true);
        $day = $date->copy()->startOfDay();
        if ($isPaid
            && $membership->expiry_date
            && $membership->expiry_date->copy()->subYear()->lte($day)
            && $membership->expiry_date->gte($day)) {
            return 'expiry_workback';
        }

        return null;
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
