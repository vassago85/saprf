<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualification_rules', function (Blueprint $table) {
            // Which scoring model this series uses. 'best_of_n' is the legacy
            // model; 'weighted_pools' is the PR22 three-pool model (provincial,
            // national 2-day, SA champs) each contributing a weight to a total /100.
            $table->string('scoring_mode', 32)->default('best_of_n')->after('season');

            // Weighted-pool configuration (only used when scoring_mode='weighted_pools')
            $table->unsignedTinyInteger('provincial_pool_best_of')->nullable()->after('total_qualifying_matches');
            $table->decimal('provincial_pool_weight_pct', 5, 2)->nullable()->after('provincial_pool_best_of');

            $table->unsignedTinyInteger('national_pool_best_of')->nullable()->after('provincial_pool_weight_pct');
            $table->decimal('national_pool_weight_pct', 5, 2)->nullable()->after('national_pool_best_of');

            $table->unsignedTinyInteger('champs_pool_best_of')->nullable()->after('national_pool_weight_pct');
            $table->decimal('champs_pool_weight_pct', 5, 2)->nullable()->after('champs_pool_best_of');
        });

        Schema::table('standings', function (Blueprint $table) {
            // Per-pool contribution breakdown for display on the shooter's
            // detail page (e.g. { provincial: {avg: 78.2, weight: 30, contribution: 23.46}, ... }).
            $table->json('pool_breakdown')->nullable()->after('points');
        });
    }

    public function down(): void
    {
        Schema::table('qualification_rules', function (Blueprint $table) {
            $table->dropColumn([
                'scoring_mode',
                'provincial_pool_best_of',
                'provincial_pool_weight_pct',
                'national_pool_best_of',
                'national_pool_weight_pct',
                'champs_pool_best_of',
                'champs_pool_weight_pct',
            ]);
        });

        Schema::table('standings', function (Blueprint $table) {
            $table->dropColumn('pool_breakdown');
        });
    }
};
