<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            // For 2-day matches, MDs enter each day separately.
            //   - raw_score           = day1_raw_score + day2_raw_score (auto)
            //   - provincial_raw_score = day1_raw_score (auto, when match.also_counts_for_provincial)
            //
            // For 1-day matches, day1_raw_score = raw_score and day2_raw_score is null.
            $table->decimal('day1_raw_score', 10, 3)->nullable()->after('raw_score');
            $table->decimal('day2_raw_score', 10, 3)->nullable()->after('day1_raw_score');
        });

        // Backfill existing scores. Any score that currently has a raw_score becomes
        // a day1_raw_score by default (safe: 1-day matches unchanged; 2-day nationals
        // may need re-entry to split correctly, which is expected under the new rules).
        DB::statement('UPDATE scores SET day1_raw_score = raw_score WHERE day1_raw_score IS NULL AND raw_score IS NOT NULL');

        // Where the match already has a provincial_raw_score set (via stage columns),
        // treat that as the "day 1" total for clarity going forward.
        DB::statement('UPDATE scores SET day1_raw_score = provincial_raw_score WHERE provincial_raw_score IS NOT NULL AND provincial_raw_score > 0');
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropColumn(['day1_raw_score', 'day2_raw_score']);
        });
    }
};
