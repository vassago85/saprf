<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualification_rules', function (Blueprint $table) {
            // Minimum number of national matches a shooter must complete before
            // any national-pool score is earned. Below this threshold the
            // national pool contributes 0. At/above it, the best `national_pool_best_of`
            // scores are summed (no drop-one). Default 2 per the PR22 rule.
            $table->unsignedTinyInteger('national_pool_min_matches')
                ->default(2)
                ->after('national_pool_weight_pct');
        });
    }

    public function down(): void
    {
        Schema::table('qualification_rules', function (Blueprint $table) {
            $table->dropColumn('national_pool_min_matches');
        });
    }
};
