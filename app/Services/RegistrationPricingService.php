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

    public function determineCategoryAndFee(MatchEvent $match, ?User $user, CarbonInterface $matchDate): array
    {
        $category = $this->membershipValidationService->classifyRegistrationCategory($user, $matchDate);

        $baseFee = (float) $match->active_member_fee;

        $fee = match ($category) {
            'active_member' => $baseFee,
            'lapsed_member' => $baseFee + (float) $this->settingsService->get('lapsed_member_surcharge', 0),
            default => $baseFee + (float) $this->settingsService->get('non_member_surcharge', 0),
        };

        return [
            'category' => $category,
            'fee' => $fee,
        ];
    }

    /**
     * Full fee breakdown for a registration showing where the money goes.
     *
     * Surcharges (non-member / lapsed) go 100% to SAPRF.
     * SAPRF fee and platform fee are each a % of the base match fee.
     * Gateway fee is estimated from the total amount charged to the shooter.
     * MD net = total - SAPRF fee - platform fee - surcharge - estimated gateway fee.
     */
    public function calculateBreakdown(MatchEvent $match, ?User $user, CarbonInterface $matchDate): array
    {
        $pricing = $this->determineCategoryAndFee($match, $user, $matchDate);

        $baseFee = (float) $match->active_member_fee;
        $totalFee = $pricing['fee'];
        $category = $pricing['category'];

        $surcharge = $totalFee - $baseFee;

        $saprfType = (string) $this->settingsService->get('saprf_fee_type', 'fixed');
        $saprfValue = (float) $this->settingsService->get('saprf_fee_value', 50);
        $platformType = (string) $this->settingsService->get('platform_fee_type', 'fixed');
        $platformValue = (float) $this->settingsService->get('platform_fee_value', 0);
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
}
