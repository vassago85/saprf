<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\User;
use Carbon\Carbon;
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

        // Historical/imported members frequently arrive with
        // payment_status='unpaid' despite being real paid-up federation members
        // — their subscription simply predates the platform and the CSV they
        // were imported from never captured the payment event. Trust a real
        // (non-stub) SAPRF number as evidence of paidness in that case; the
        // expiry_date window check below still enforces whether the shooter
        // was actually paid up on the specific match date. If neither an
        // explicit paid/waived flag nor a real SAPRF number is present, the
        // person isn't a member for scoring purposes.
        //
        // This mirrors the lenient logic used by Membership::isActiveMember()
        // for the admin panel, so a shooter can no longer show as "Active"
        // there while their scores are silently demoted to Lapsed.
        $isPaid = in_array($membership->payment_status, ['paid', 'waived'], true);
        $hasRealSaprfNumber = filled($membership->saprf_number)
            && ! str_starts_with((string) $membership->saprf_number, 'SAPRF-IMPORT-');

        if (! $isPaid && ! $hasRealSaprfNumber) {
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

    /**
     * Category for a registration entry — answers "were they a full member
     * when they signed up?", not "will they still be a member on match day?".
     * Match-day membership is enforced later by ScoreValidationService when the
     * score is entered; if they let their membership expire before the match,
     * the score simply will not count toward the season log.
     *
     * The $matchDate argument is retained for call-site compatibility but is
     * intentionally unused — signup category is a today-fact.
     *
     * IMPORTANT: this MUST use the same lenient today-check that the admin
     * membership listing uses to render the "Active" pill
     * (Membership::isActiveMember()), NOT the strict historical check that
     * powers score validity. The strict check requires
     * payment_status IN ('paid','waived'), which imported/legacy records
     * often fail even when they clearly have status='active' and a valid
     * expiry — the shooter is then greeted with "Active" in the admin panel
     * and "Lapsed Member" on the registration form, with no way to
     * reconcile the two. isMembershipValidOnDate() stays strict for
     * historical questions like "was this score eligible on match day?".
     */
    public function classifyRegistrationCategory(?User $user, ?CarbonInterface $matchDate = null): string
    {
        if (! $user || ! $user->membership) {
            return 'non_member';
        }

        $membership = $user->membership;

        if ($membership->membership_type === 'free') {
            return 'non_member';
        }

        if ($membership->isActiveMember()) {
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
