<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('standings', 'division')) {
            Schema::table('standings', function (Blueprint $table) {
                $table->string('division', 50)->default('Open')->after('season');
            });
        }

        // Shrink series/season/division so the composite unique key fits within 3072 bytes
        DB::statement('ALTER TABLE `standings` MODIFY `series` VARCHAR(20) NOT NULL');
        DB::statement('ALTER TABLE `standings` MODIFY `season` VARCHAR(10) NOT NULL');
        DB::statement('ALTER TABLE `standings` MODIFY `division` VARCHAR(50) NOT NULL DEFAULT \'Open\'');

        Schema::table('standings', function (Blueprint $table) {
            $table->unique(['user_id', 'series', 'season', 'division', 'province_id'], 'standings_unique_with_division');
            $table->index(['series', 'season', 'division', 'rank'], 'standings_series_season_division_rank_index');
        });

        Schema::table('standings', function (Blueprint $table) {
            $table->dropUnique('standings_user_id_series_season_province_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('standings', function (Blueprint $table) {
            $table->unique(['user_id', 'series', 'season', 'province_id'], 'standings_user_id_series_season_province_id_unique');
        });

        Schema::table('standings', function (Blueprint $table) {
            $table->dropUnique('standings_unique_with_division');
            $table->dropIndex('standings_series_season_division_rank_index');
            $table->dropColumn('division');
        });
    }
};
