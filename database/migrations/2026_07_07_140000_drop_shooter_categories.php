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
 *
 * IMPORTANT: every step is guarded so the migration is idempotent. MySQL
 * auto-commits DDL, so a crash-looping container that re-runs `migrate` can
 * leave this half-applied; the guards let a re-run reconcile whatever state
 * the schema is in without blowing up on already-dropped objects.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Pivot tables that reference categories (idempotent by nature).
        Schema::dropIfExists('score_category');
        Schema::dropIfExists('user_category');
        Schema::dropIfExists('season_classification_categories');

        // 2) Season shooter classifications (locked demographic categories
        //    per season — no longer needed).
        Schema::dropIfExists('season_shooter_classifications');

        // 3) standings.category_id + its composite unique index.
        if (Schema::hasColumn('standings', 'category_id')) {
            // Drop the FK first (name may not exist if a prior partial run
            // already removed it — swallow that case).
            $this->tryStandings(fn (Blueprint $table) => $table->dropForeign(['category_id']));

            // Drop the old composite unique that included category_id.
            $this->tryStandings(fn (Blueprint $table) => $table->dropUnique('standings_composite_unique'));

            Schema::table('standings', function (Blueprint $table) {
                $table->dropColumn('category_id');
            });
        }

        // Ensure the category-free composite unique exists. If a prior run
        // already created it, MySQL/SQLite throws "duplicate index" — ignore.
        $this->tryStandings(function (Blueprint $table) {
            $table->unique(
                ['user_id', 'series', 'season', 'province_id', 'division_id'],
                'standings_composite_unique',
            );
        });

        // 4) matches.category_rankings_enabled / category_awards_enabled —
        //    drop each only if it's still present.
        $matchColumns = array_values(array_filter(
            ['category_rankings_enabled', 'category_awards_enabled'],
            fn (string $col) => Schema::hasColumn('matches', $col),
        ));

        if ($matchColumns !== []) {
            Schema::table('matches', function (Blueprint $table) use ($matchColumns) {
                $table->dropColumn($matchColumns);
            });
        }

        // 5) Finally, drop the categories table.
        Schema::dropIfExists('categories');
    }

    /**
     * Run a single fragile schema operation against `standings`, swallowing
     * failures caused by the object already being absent/present. Keeping each
     * op in its own statement means one already-applied step can't abort the
     * others.
     */
    private function tryStandings(\Closure $callback): void
    {
        try {
            Schema::table('standings', $callback);
        } catch (\Throwable) {
            // Object already in the desired state — safe to ignore.
        }
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
