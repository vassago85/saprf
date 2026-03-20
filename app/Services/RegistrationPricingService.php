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
            'lapsed_member' => $baseFee + (float) $this->settingsService->get('lapsed_member_surcharge', 150),
            default => $baseFee + (float) $this->settingsService->get('non_member_surcharge', 250),
        };

        return [
            'category' => $category,
            'fee' => $fee,
        ];
    }
}
