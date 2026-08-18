<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Correct the Junior tier price from the initial R150 seed to R425
     * (half the R850 adult rate). Only touches rows still sitting on the
     * default R150 so any owner edits made through the admin Fees UI are
     * preserved.
     */
    public function up(): void
    {
        DB::table('membership_fee_tiers')
            ->where('slug', 'junior')
            ->where('price', 150.00)
            ->update([
                'price' => 425.00,
                'description' => 'Discounted rate for junior members under 18 (half the adult rate).',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('membership_fee_tiers')
            ->where('slug', 'junior')
            ->where('price', 425.00)
            ->update([
                'price' => 150.00,
                'description' => 'Discounted rate for junior members under 18.',
                'updated_at' => now(),
            ]);
    }
};
