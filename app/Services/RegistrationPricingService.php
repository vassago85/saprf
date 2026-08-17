<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\User;
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
     */
    public function calculateBreakdown(MatchEvent $match, ?User $user, CarbonInterface $matchDate, ?string $divisionSlug = null, ?string $forcedCategory = null): array
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
}
