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
     * The three fee brackets a registration can fall into. Anything outside
     * this list is a bug — pricing must always pick one deterministically.
     */
    public const CATEGORIES = ['active_member', 'lapsed_member', 'non_member'];

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
        $category = $forcedCategory !== null
            ? $this->assertValidCategory($forcedCategory)
            : $this->membershipValidationService->classifyRegistrationCategory($user, $matchDate);

        $baseFee = $this->baseFeeFor($match, $divisionSlug);

        $fee = match ($category) {
            'active_member' => $baseFee,
            'lapsed_member' => $baseFee + (float) $this->settingsService->get('lapsed_member_surcharge', 0),
            'non_member' => $baseFee + (float) $this->settingsService->get('non_member_surcharge', 0),
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
     * Base entry fee for the entry: Junior-division entries use the match's
     * junior_fee when one is set; everyone else pays the normal entry fee.
     */
    public function baseFeeFor(MatchEvent $match, ?string $divisionSlug): float
    {
        if ($divisionSlug === 'junior' && $match->junior_fee !== null) {
            return (float) $match->junior_fee;
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
     * When $registeredAt (defaulting to now) is before the `billing_start_date`
     * setting, SAPRF and platform fees are waived — both are zeroed and the
     * amount is rolled into MD net so the shooter still pays the same total
     * but 100% of the fee (less gateway + any surcharge) flows to the MD.
     */
    public function calculateBreakdown(MatchEvent $match, ?User $user, CarbonInterface $matchDate, ?string $divisionSlug = null, ?string $forcedCategory = null, ?CarbonInterface $registeredAt = null): array
    {
        $pricing = $this->determineCategoryAndFee($match, $user, $matchDate, $divisionSlug, $forcedCategory);

        $baseFee = (float) $pricing['base_fee'];
        $totalFee = $pricing['fee'];
        $category = $pricing['category'];

        $surcharge = $totalFee - $baseFee;

        // Per-match overrides beat the global setting. Only apply the override
        // when BOTH type and value are set — a half-set override would silently
        // pair the match's type with the global value (or vice-versa) and
        // produce numbers no one asked for. Imported matches use this to book
        // R0 platform fee; exco/developer can set it manually per match.
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
        $gatewayPct = (float) $this->settingsService->get('estimated_gateway_fee_percentage', 3.5);
        $gatewayFlat = (float) $this->settingsService->get('estimated_gateway_flat_fee', 2.00);

        $saprfFee = $this->resolveFee($saprfType, $saprfValue, $baseFee);
        $platformFee = $this->resolveFee($platformType, $platformValue, $baseFee);
        $gatewayFee = round($totalFee * ($gatewayPct / 100) + $gatewayFlat, 2);

        $waived = $this->isFeeWaived($registeredAt);
        if ($waived) {
            $saprfFee = 0.0;
            $platformFee = 0.0;
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
     * True when registration falls inside the pre-billing grace period.
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

        $breakdown = $this->calculateBreakdown(
            $match,
            $registration->user,
            $match->match_date ?: now(),
            $registration->division?->slug,
            $category,
            $registration->registered_at,
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
}
