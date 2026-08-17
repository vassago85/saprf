<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Age eligibility for a fee tier, stored as an inclusive range and both
     * nullable = unrestricted. A tier is available to a user of age `n` iff
     *   (min_age IS NULL OR n >= min_age)
     *   AND (max_age IS NULL OR n <= max_age)
     * so an Adult tier stays `NULL, NULL`, Junior is `NULL, 17`, and Senior
     * is `65, NULL`. Stored on the tier (not derived at picker time) so
     * admins can retune the thresholds from the Fees UI without another
     * migration.
     */
    public function up(): void
    {
        Schema::table('membership_fee_tiers', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_age')->nullable()->after('duration_months');
            $table->unsignedTinyInteger('max_age')->nullable()->after('min_age');
        });
    }

    public function down(): void
    {
        Schema::table('membership_fee_tiers', function (Blueprint $table) {
            $table->dropColumn(['min_age', 'max_age']);
        });
    }
};
