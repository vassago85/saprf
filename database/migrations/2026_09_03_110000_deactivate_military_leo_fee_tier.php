<?php

use App\Models\MembershipFeeTier;
use Illuminate\Database\Migrations\Migration;

/**
 * Mil/LEO isn't used in practice for SAPRF (South Africa), and it was
 * cheaper than Adult so cheapestForUser pre-selected it on the join/renew
 * picker. Hide the tier from self-service; admins can re-activate later
 * from Fee Tiers if needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        MembershipFeeTier::query()
            ->where('slug', 'military-law-enforcement')
            ->update(['is_active' => false]);

        // Adult is the self-service default for age-eligible adults/seniors.
        MembershipFeeTier::query()
            ->where('slug', 'adult')
            ->update(['is_default' => true]);

        MembershipFeeTier::query()
            ->where('slug', '!=', 'adult')
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    public function down(): void
    {
        MembershipFeeTier::query()
            ->where('slug', 'military-law-enforcement')
            ->update(['is_active' => true]);
    }
};
