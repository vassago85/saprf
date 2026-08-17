<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Introduce the Junior fee tier (R150, under-18) and pin every tier to
     * an explicit age band so a 14-year-old only sees Junior in the picker
     * (not Adult R850, which would otherwise silently apply as unrestricted):
     *
     *   junior: 0–17    (R150)
     *   adult:  18+     (R850)
     *   mil-leo: 18+    (R425, serving military/LEO)
     *   senior: 65+     (R425, retirement discount)
     *
     * Idempotent so production and dev-with-existing-data land at the same
     * shape: `junior` is inserted only if missing; every other slug's age
     * band is set unconditionally.
     */
    public function up(): void
    {
        $now = now();

        $juniorExists = DB::table('membership_fee_tiers')->where('slug', 'junior')->exists();

        if (! $juniorExists) {
            DB::table('membership_fee_tiers')->insert([
                'slug' => 'junior',
                'name' => 'Junior',
                'description' => 'Discounted rate for junior members under 18.',
                'price' => 150.00,
                'duration_months' => 12,
                'display_order' => 4,
                'is_active' => true,
                'is_default' => false,
                'min_age' => null,
                'max_age' => 17,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('membership_fee_tiers')
                ->where('slug', 'junior')
                ->update(['max_age' => 17, 'min_age' => null, 'updated_at' => $now]);
        }

        DB::table('membership_fee_tiers')
            ->where('slug', 'senior')
            ->update(['min_age' => 65, 'max_age' => null, 'updated_at' => $now]);

        // Adult + Mil/LEO are 18+. Without this floor a 14-year-old sees
        // Junior AND Adult AND Mil/LEO in the picker and can accidentally
        // sign up at R850 instead of R150.
        DB::table('membership_fee_tiers')
            ->whereIn('slug', ['adult', 'military-law-enforcement'])
            ->update(['min_age' => 18, 'max_age' => null, 'updated_at' => $now]);
    }

    public function down(): void
    {
        DB::table('membership_fee_tiers')->where('slug', 'junior')->delete();

        DB::table('membership_fee_tiers')
            ->whereIn('slug', ['adult', 'military-law-enforcement', 'senior'])
            ->update(['min_age' => null, 'max_age' => null]);
    }
};
