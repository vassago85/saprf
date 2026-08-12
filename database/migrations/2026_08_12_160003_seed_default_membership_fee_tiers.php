<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed the starting membership fee tiers so production (which runs
     * migrations, not seeders, on deploy) has sensible defaults. Idempotent:
     * only seeds when the table is empty so it never clobbers owner edits.
     */
    public function up(): void
    {
        if (DB::table('membership_fee_tiers')->exists()) {
            return;
        }

        $now = now();

        DB::table('membership_fee_tiers')->insert([
            [
                'slug' => 'adult',
                'name' => 'Adult',
                'description' => 'Standard adult membership.',
                'price' => 850.00,
                'duration_months' => 12,
                'display_order' => 1,
                'is_active' => true,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'military-law-enforcement',
                'name' => 'Military / Law Enforcement Officer',
                'description' => 'Discounted rate for serving military and law enforcement officers.',
                'price' => 425.00,
                'duration_months' => 12,
                'display_order' => 2,
                'is_active' => true,
                'is_default' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'senior',
                'name' => 'Senior',
                'description' => 'Discounted rate for senior members.',
                'price' => 425.00,
                'duration_months' => 12,
                'display_order' => 3,
                'is_active' => true,
                'is_default' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('membership_fee_tiers')->whereIn('slug', [
            'adult',
            'military-law-enforcement',
            'senior',
        ])->delete();
    }
};
