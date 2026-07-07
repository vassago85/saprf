<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merge the shooter Category concept into Division.
 *
 * Prior model: shooters had ONE division (equipment class) + ZERO-OR-MORE
 * categories (demographic — Ladies, Junior, Senior). Under the federation's
 * new rules, everything collapses into a single flat list of divisions
 * (each shooter picks exactly one).
 *
 * This migration:
 *   - Drops all pivots that referenced categories.
 *   - Drops the season_shooter_classifications table (category-tied).
 *   - Drops category_id from standings and category_* flags from matches.
 *   - Drops the categories table itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Pivot tables that reference categories
        Schema::dropIfExists('score_category');
        Schema::dropIfExists('user_category');
        Schema::dropIfExists('season_classification_categories');

        // 2) Season shooter classifications (existed to lock a shooter's
        //    demographic categories per season — no longer needed).
        Schema::dropIfExists('season_shooter_classifications');

        // 3) standings.category_id — remove the composite index first so we
        //    can drop the column, then re-add the unique without category_id.
        Schema::table('standings', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropUnique('standings_composite_unique');
            $table->dropColumn('category_id');
        });

        Schema::table('standings', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'series', 'season', 'province_id', 'division_id'],
                'standings_composite_unique',
            );
        });

        // 4) matches.category_rankings_enabled / category_awards_enabled
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['category_rankings_enabled', 'category_awards_enabled']);
        });

        // 5) Finally, drop the categories table.
        Schema::dropIfExists('categories');
    }

    public function down(): void
    {
        // This is a one-way migration. We are permanently removing the concept
        // of shooter categories from the federation platform. Restoring the
        // schema is not supported; restore from a database backup taken
        // before the migration ran if you need to roll back.
        throw new \RuntimeException('The shooter-categories refactor is not reversible. Restore from backup.');
    }
};
