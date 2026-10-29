<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class RegistrationPricingService
{
    public function __construct(
        private readonly MembershipValidationService $membershipValidationService,
        private readonly SettingsService $settingsService,
    ) {}

    /**
     * The fee brackets a registration can fall into. Anything outside
     * this list is a bug — pricing must always pick one deterministically.
     *
     * `club_entry` is a non-member entry on a club or provincial match: the
     * shooter pays a match-director-set price with no non-member surcharge,
     * and the platform takes a small percentage of the match fee only when
     * PayFast handles the money (see $collectedBy on calculateBreakdown).
     */
    public const CATEGORIES = ['active_member', 'lapsed_member', 'non_member', 'club_entry'];

    /**
     * Who collected the entry fee. Governs whether SAPRF/platform/gateway
     * are charged on a club-entry row: when the match director collected
     * the money off-platform, the fee never touched PayFast and the three
     * platform fees are forced to zero.
     *
     * Non-club-entry brackets ignore this value — their fee breakdown
     * continues to use the configured SAPRF/platform rates and a gateway
     * estimate exactly as before.
     */
    public const COLLECTED_BY_PLATFORM = 'platform';
    public const COLLECTED_BY_DIRECTOR = 'director';

    /** Club entry: SAPRF fee as a percent of the club match fee. */
    private const DEFAULT_CLUB_ENTRY_SAPRF_PERCENT = 3.0;

    /** Club entry: platform fee as a percent of the club match fee. */
    private const DEFAULT_CLUB_ENTRY_PLATFORM_PERCENT = 3.0;

    /**
     * @param  string|null  $forcedCategory  Optional admin override that
     *   bypasses the membership classifier. Used by tooling that seeds an
     *   entry at a fee bracket the user does not currently qualify for —
     *   e.g. a grace entry that waives a lapsed-member surcharge. The
     *   caller is expected to record WHY on the registration
     *   (`fee_override_reason`) so the audit trail explains the deviation.
     */
    public function determineCategoryAndFee(MatchEvent $match, ?User $user, CarbonInterface $matchDate, ?string $divisionSlug = null, ?string $forcedCategory = null): array
    {
        $classified = $forcedCategory !== null
            ? $this->assertValidCategory($forcedCategory)
            : $this->membershipValidationService->classifyRegistrationCategory($user, $matchDate);

        // Non-members on a club or provincial match are routed onto the
        // club_entry bracket: they pay the match-director-set club price
        // (or the active-member fee when no club price is set) with no
        // surcharge. The classifier itself doesn't need to know about
        // series_level — that's a per-match pricing decision, not a
        // membership-status fact.
        $category = $classified;
        if ($classified === 'non_member' && $match->supportsClubEntry()) {
            $category = 'club_entry';
        }

        // Guard against a forced club_entry on a national/final/international
        // match: no surcharge waiver is allowed outside club/provincial.
        if ($category === 'club_entry' && ! $match->supportsClubEntry()) {
            throw new \InvalidArgumentException(
                'Club entry is only available on club or provincial matches.'
            );
        }

        $baseFee = $this->baseFeeFor($match, $divisionSlug, $category);

        $fee = match ($category) {
            'active_member' => $baseFee,
            'lapsed_member' => $baseFee + (float) $this->settingsService->get('lapsed_member_surcharge', 0),
            'non_member' => $baseFee + (float) $this->settingsService->get('non_member_surcharge', 0),
            'club_entry' => $baseFee,
        };

        return [
            'category' => $category,
            'fee' => $fee,
            'base_fee' => $baseFee,
        ];
    }

    private function assertValidCategory(string $category): string
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException(
                "Invalid forced pricing category '{$category}'. Expected one of: "
                . implode(', ', self::CATEGORIES)
            );
        }

        return $category;
    }

    /**
     * Base entry fee for the entry.
     *
     * Precedence (first rule that matches wins):
     *   1. Junior division + junior_fee set   → junior_fee.
     *   2. Club-entry bracket + club_entry_fee set → club_entry_fee.
     *   3. Everyone else → active_member_fee.
     *
     * A blank club_entry_fee intentionally falls through to the member fee
     * so an MD who only wants to drop the surcharge (and keep the price the
     * same) doesn't have to type the member fee into a second field. Junior
     * still wins over club entry because a junior club entrant pays junior
     * pricing when the MD has specifically set a junior fee.
     */
    public function baseFeeFor(MatchEvent $match, ?string $divisionSlug, ?string $category = null): float
    {
        if ($divisionSlug === 'junior' && $match->junior_fee !== null) {
            return (float) $match->junior_fee;
        }

        if ($category === 'club_entry' && $match->club_entry_fee !== null) {
            return (float) $match->club_entry_fee;
        }

        return (float) $match->active_member_fee;
    }

    /**
     * Full fee breakdown for a registration showing where the money goes.
     *
     * Surcharges (non-member / lapsed) go 100% to SAPRF.
     * SAPRF fee and platform fee are each a % of the base match fee.
     * Gateway fee is the card-rate estimate (highest typical PayFast cost).
     * PayFast ITN later overwrites it with the actual deducted fee.
     * MD net = total - SAPRF fee - platform fee - surcharge - gateway fee.
     *
     * Club-entry bracket is a special case: the SAPRF and platform fees are
     * each a fixed percentage of the club match fee (3% each by default,
     * overridable via the `club_entry_saprf_percent` /
     * `club_entry_platform_percent` settings). Match-level fee overrides and
     * the billing-start-date platform waiver do not apply to club entries —
     * the whole point of the bracket is that both federation and platform
     * take a known small cut, nothing more.
     *
     * When $registeredAt (defaulting to now) is before the `billing_start_date`
     * setting, the platform fee is waived for member/lapsed/non-member rows —
     * SAPRF still takes its R50 per shooter because the fee schedule change
     * was only the platform-fee introduction, not the SAPRF levy. The waived
     * platform amount is rolled into MD net so the shooter's total is
     * unchanged.
     *
     * $collectedBy controls where the money came from. On club_entry rows,
     * `director` zeros SAPRF, platform, and gateway — the MD collected the
     * fee off-platform (cash on the range, EFT, PayFast was never
     * involved), so the platform cannot charge for a service it did not
     * provide. Non-club brackets ignore the flag and keep today's rates.
     */
    public function calculateBreakdown(MatchEvent $match, ?User $user, CarbonInterface $matchDate, ?string $divisionSlug = null, ?string $forcedCategory = null, ?CarbonInterface $registeredAt = null, string $collectedBy = self::COLLECTED_BY_PLATFORM): array
    {
        $pricing = $this->determineCategoryAndFee($match, $user, $matchDate, $divisionSlug, $forcedCategory);

        $baseFee = (float) $pricing['base_fee'];
        $totalFee = $pricing['fee'];
        $category = $pricing['category'];

        $surcharge = $totalFee - $baseFee;

        $isClubEntry = $category === 'club_entry';
        $gatewayPct = (float) $this->settingsService->get('estimated_gateway_fee_percentage', 3.5);
        $gatewayFlat = (float) $this->settingsService->get('estimated_gateway_flat_fee', 2.00);

        if ($isClubEntry) {
            // Club entries use their own flat-percent split, so the per-match
            // and global fixed-R50 overrides don't apply. That keeps the
            // bracket's promise ("small percent, no surcharge") honest even
            // when a national match on the same site has a fixed R50 cut.
            $saprfPct = (float) $this->settingsService->get(
                'club_entry_saprf_percent',
                self::DEFAULT_CLUB_ENTRY_SAPRF_PERCENT,
            );
            $platformPct = (float) $this->settingsService->get(
                'club_entry_platform_percent',
                self::DEFAULT_CLUB_ENTRY_PLATFORM_PERCENT,
            );
            $saprfType = 'percentage';
            $saprfValue = $saprfPct;
            $platformType = 'percentage';
            $platformValue = $platformPct;
        } else {
            // Per-match overrides beat the global setting. Only apply the
            // override when BOTH type and value are set — a half-set
            // override would silently pair the match's type with the
            // global value (or vice-versa) and produce numbers no one
            // asked for. Imported matches use this to book R0 platform
            // fee; exco/developer can set it manually per match.
            [$saprfType, $saprfValue] = $this->resolveRate(
                $match->saprf_fee_type,
                $match->saprf_fee_value,
                $this->settingsService->get('saprf_fee_type', 'fixed'),
                $this->settingsService->get('saprf_fee_value', 50),
            );
            [$platformType, $platformValue] = $this->resolveRate(
                $match->platform_fee_type,
                $match->platform_fee_value,
                $this->settingsService->get('platform_fee_type', 'fixed'),
                $this->settingsService->get('platform_fee_value', 0),
            );
        }

        $saprfFee = $this->resolveFee($saprfType, $saprfValue, $baseFee);
        $platformFee = $this->resolveFee($platformType, $platformValue, $baseFee);
        $gatewayFee = round($totalFee * ($gatewayPct / 100) + $gatewayFlat, 2);

        // Pre-billing grace period: platform fee is waived for the normal
        // brackets so SAPRF keeps its R50 but the platform charges nothing
        // for the first entries after the fee was introduced. Club entries
        // skip the waiver on purpose — the 3% is the whole point of the
        // bracket, and a R0 platform cut would just hand that back to the
        // MD without a clear audit story.
        $waived = $this->isFeeWaived($registeredAt);
        if ($waived && ! $isClubEntry) {
            $platformFee = 0.0;
        }

        // Director-collected club entry: the money never touched PayFast,
        // so the platform cannot bill for the payment service. SAPRF and
        // the platform both drop to zero, gateway drops to zero, and the
        // MD keeps the full club fee. Non-club brackets ignore the flag.
        $directorCollected = $isClubEntry && $collectedBy === self::COLLECTED_BY_DIRECTOR;
        if ($directorCollected) {
            $saprfFee = 0.0;
            $platformFee = 0.0;
            $gatewayFee = 0.0;
        }

        $mdNet = round($totalFee - $saprfFee - $platformFee - $surcharge - $gatewayFee, 2);

        return [
            'category' => $category,
            'base_fee' => $baseFee,
            'surcharge' => $surcharge,
            'total_fee' => $totalFee,
            'saprf_fee' => $saprfFee,
            'platform_fee' => $platformFee,
            'gateway_fee' => $gatewayFee,
            'md_net' => $mdNet,
            'fee_waived' => $waived,
            'collected_by' => $collectedBy,
            'rates' => [
                'saprf_type' => $saprfType,
                'saprf_value' => $saprfValue,
                'platform_type' => $platformType,
                'platform_value' => $platformValue,
                'gateway_pct' => $gatewayPct,
                'gateway_flat' => $gatewayFlat,
            ],
        ];
    }

    /**
     * True when registration falls inside the pre-billing grace period, in
     * which case the platform fee (but NOT the SAPRF fee) is waived.
     *
     * The cut-off is the `billing_start_date` setting (ISO date). A blank or
     * unparseable value disables the waiver so pricing never fails open into
     * "everything is free forever" if the setting is accidentally cleared.
     */
    public function isFeeWaived(?CarbonInterface $registeredAt = null): bool
    {
        $raw = trim((string) $this->settingsService->get('billing_start_date', ''));
        if ($raw === '') {
            return false;
        }

        try {
            $cutoff = Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return false;
        }

        $at = $registeredAt ? Carbon::parse($registeredAt) : now();

        return $at->lt($cutoff);
    }

    private function resolveFee(string $type, float $value, float $baseFee): float
    {
        return $type === 'fixed'
            ? round($value, 2)
            : round($baseFee * ($value / 100), 2);
    }

    /**
     * Pick either the per-match override (only when both type and value are
     * set) or fall back to the global rate. Returns [type, value].
     *
     * @return array{0: string, 1: float}
     */
    private function resolveRate(mixed $matchType, mixed $matchValue, mixed $globalType, mixed $globalValue): array
    {
        if ($matchType !== null && $matchValue !== null) {
            return [(string) $matchType, (float) $matchValue];
        }

        return [(string) $globalType, (float) $globalValue];
    }

    /**
     * Unpaid non-members on one current club or provincial match. Paid and
     * waived rows are left out so a match director can reprice only the
     * shooters who have not paid yet.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MatchRegistration>
     */
    public function unpaidNonMembersEligibleForClubEntry(MatchEvent $match)
    {
        if (! $match->supportsClubEntry() || in_array($match->status, ['completed', 'cancelled'], true)) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return MatchRegistration::query()
            ->with(['match', 'user', 'division'])
            ->where('match_id', $match->id)
            ->where('membership_fee_category', 'non_member')
            ->where('registration_status', '!=', 'cancelled')
            ->where(function ($query) {
                $query->whereNull('registration_source')
                    ->orWhere('registration_source', '!=', 'walk_in');
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (MatchRegistration $registration) => $registration->canCorrectCategory())
            ->values();
    }

    /**
     * Recalculate and persist an unpaid registration at a chosen fee bracket.
     * Cancels in-flight PayFast checkouts so the next Pay Now uses the new
     * amount. The caller must record WHY via $reason (stored on the row and
     * expected in the audit log).
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>, cancelled_payments: int}
     */
    public function applyCategory(MatchRegistration $registration, string $category, string $reason): array
    {
        $category = $this->assertValidCategory($category);
        $registration->loadMissing(['match', 'user', 'division']);

        $tracked = [
            'membership_fee_category',
            'fee_amount',
            'surcharge_amount',
            'saprf_fee',
            'platform_fee',
            'gateway_fee',
            'md_net_amount',
            'fee_override_reason',
        ];

        $old = $registration->only($tracked);
        $match = $registration->match
            ?? throw new \InvalidArgumentException('Registration has no match.');

        // This path only recategorises *unpaid* entries (the controller
        // enforces that), so the new price will still flow through PayFast
        // on the shooter's next Pay Now — i.e. platform-collected.
        $breakdown = $this->calculateBreakdown(
            $match,
            $registration->user,
            $match->match_date ?: now(),
            $registration->division?->slug,
            $category,
            $registration->registered_at,
            self::COLLECTED_BY_PLATFORM,
        );

        $registration->update([
            'membership_fee_category' => $breakdown['category'],
            'fee_amount' => $breakdown['total_fee'],
            'surcharge_amount' => $breakdown['surcharge'],
            'saprf_fee' => $breakdown['saprf_fee'],
            'platform_fee' => $breakdown['platform_fee'],
            'gateway_fee' => $breakdown['gateway_fee'],
            'md_net_amount' => $breakdown['md_net'],
            'fee_override_reason' => $reason,
        ]);

        $cancelled = $registration->payments()
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        return [
            'old' => $old,
            'new' => $registration->only($tracked),
            'cancelled_payments' => $cancelled,
        ];
    }

    /**
     * Drop an unpaid entry's base fee to $targetBase. The surcharge already
     * on the row stays. SAPRF, platform and gateway are recalculated on the
     * new total, and any in-flight PayFast checkout is cancelled so the next
     * Pay Now uses the new amount. Returns null when the entry is not an
     * unpaid charge above the target.
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>, cancelled_payments: int}|null
     */
    public function reduceUnpaidBaseFee(MatchRegistration $registration, float $targetBase, string $reason): ?array
    {
        $targetBase = round($targetBase, 2);
        $registration->loadMissing(['match', 'user']);

        if ($registration->registration_status === 'cancelled'
            || $registration->registration_source === 'walk_in'
            || in_array($registration->payment_status, ['paid', 'waived'], true)
            || $registration->match === null) {
            return null;
        }

        if (round((float) $registration->match->junior_fee, 2) !== $targetBase) {
            throw new \InvalidArgumentException(
                'Unpaid junior reprice target must match the match junior fee.'
            );
        }

        $surcharge = round((float) $registration->surcharge_amount, 2);
        $credit = round((float) $registration->fee_amount - $surcharge - $targetBase, 2);
        if ($credit <= 0) {
            return null;
        }

        $category = in_array($registration->membership_fee_category, self::CATEGORIES, true)
            ? $registration->membership_fee_category
            : 'active_member';

        $breakdown = $this->calculateBreakdown(
            $registration->match,
            $registration->user,
            $registration->match->match_date ?: now(),
            'junior',
            $category,
            $registration->registered_at,
            self::COLLECTED_BY_PLATFORM,
        );

        $newFee = round($targetBase + $surcharge, 2);
        $saprf = round((float) $breakdown['saprf_fee'], 2);
        $platform = round((float) $breakdown['platform_fee'], 2);
        $gateway = round(
            $newFee * (((float) $breakdown['rates']['gateway_pct']) / 100) + (float) $breakdown['rates']['gateway_flat'],
            2,
        );
        $mdNet = round($newFee - $saprf - $platform - $surcharge - $gateway, 2);
        $note = 'Junior fee reduced to R'.number_format($targetBase, 2);

        $tracked = [
            'fee_amount',
            'saprf_fee',
            'platform_fee',
            'gateway_fee',
            'md_net_amount',
            'fee_override_reason',
        ];
        $old = $registration->only($tracked);

        $registration->update([
            'fee_amount' => $newFee,
            'saprf_fee' => $saprf,
            'platform_fee' => $platform,
            'gateway_fee' => $gateway,
            'md_net_amount' => $mdNet,
            'fee_override_reason' => trim(
                ($registration->fee_override_reason ? $registration->fee_override_reason.' | ' : '').$note
            ),
        ]);

        $cancelled = $registration->payments()
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        return [
            'old' => $old,
            'new' => $registration->only($tracked),
            'cancelled_payments' => $cancelled,
            'reason' => $reason,
        ];
    }
}
