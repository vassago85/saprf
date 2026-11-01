<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted tie flags so blades can render `T2` without re-grouping the
 * entire match. Set by StandingsCalculationService::calculateMatchRankings
 * every time ranks are recomputed; defaults to false so existing scores
 * stay un-flagged until the next rank pass.
 *
 * Could be derived on read (GROUP BY overall_rank HAVING COUNT(*) > 1),
 * but the per-row cost of that query across the result tab + exports
 * makes it worth caching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scores', function (Blueprint $table): void {
            $table->boolean('is_tied_overall')->default(false)->after('overall_rank');
            $table->boolean('is_tied_division')->default(false)->after('division_rank');
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table): void {
            $table->dropColumn(['is_tied_overall', 'is_tied_division']);
        });
    }
};
