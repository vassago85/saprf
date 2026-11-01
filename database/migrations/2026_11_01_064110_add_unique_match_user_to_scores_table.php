<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prevent a second score row from being created for the same shooter in
 * the same match. On MariaDB a plain UNIQUE(match_id, user_id) tolerates
 * multiple rows where user_id IS NULL (one NULL does not equal another),
 * which is exactly what we want: an orphan Impact import that could not
 * be linked to a member keeps producing scoreboard rows, but a confirmed
 * linked shooter can only have one row.
 *
 * The companion `php artisan scores:find-duplicates` command must be run
 * BEFORE this migration — if any duplicates remain, adding the unique
 * index will fail and no rows will be touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Belt + braces: fail loudly if the operator forgot to run the
        // find-duplicates command first. Avoids a half-applied migration
        // on a bare `php artisan migrate` from the entrypoint.
        $duplicates = DB::table('scores')
            ->selectRaw('match_id, user_id, COUNT(*) AS n')
            ->whereNotNull('user_id')
            ->groupBy('match_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates > 0) {
            throw new RuntimeException(
                "Cannot add unique(scores.match_id, scores.user_id): {$duplicates} duplicate group(s) still present. "
                .'Run `php artisan scores:find-duplicates` and resolve the duplicates first.'
            );
        }

        Schema::table('scores', function (Blueprint $table): void {
            $table->unique(['match_id', 'user_id'], 'scores_match_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table): void {
            $table->dropUnique('scores_match_user_unique');
        });
    }
};
