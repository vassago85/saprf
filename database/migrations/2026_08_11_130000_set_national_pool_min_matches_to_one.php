<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A single national should count toward the national standing (as the
     * shooter's best-1, scored out of the pool's best_of) rather than being
     * dropped. Lower the weighted-pool national minimum from 2 to 1.
     */
    public function up(): void
    {
        DB::table('qualification_rules')
            ->where('scoring_mode', 'weighted_pools')
            ->update(['national_pool_min_matches' => 1]);
    }

    public function down(): void
    {
        DB::table('qualification_rules')
            ->where('scoring_mode', 'weighted_pools')
            ->update(['national_pool_min_matches' => 2]);
    }
};
