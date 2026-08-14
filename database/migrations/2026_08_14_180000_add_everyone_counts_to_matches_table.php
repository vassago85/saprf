<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Some matches — notably the Day-1 provincial extracts pulled out of 2-day
 * nationals — are imported after the fact and their organisers explicitly
 * ruled that every shooter counts regardless of membership state on the day.
 * Without a persistent marker, `ScoreValidationService::evaluateScoreStatus()`
 * demotes those scores back to `lapsed` / `non_member` any time it runs
 * (a re-import, a bulk `scores:reevaluate`, or an MD save on the score-entry
 * page — which re-evaluates every score in the match).
 *
 * This flag makes the "everyone counts" rule a first-class match property
 * that the validation service honours, so the demotion can't happen again.
 * Backfills the two known Day-1 provincial imports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            $table->boolean('everyone_counts')
                ->default(false)
                ->after('also_counts_for_provincial');
        });

        DB::table('matches')
            ->where('match_type', 'PR22')
            ->whereIn('name', [
                'Clash of the Legends PR22 Provincial (Day 1)',
                'Darling Steel Valley PR22 Provincial (Day 1)',
            ])
            ->update(['everyone_counts' => true]);
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            $table->dropColumn('everyone_counts');
        });
    }
};
