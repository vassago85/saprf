<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clarification on the published squadding decision, matching the
     * notice Andries Lategan sent for SA Champs 21-22 November.
     * Kept in sync with ExcoNov2026DecisionSeeder.
     */
    private const SQUADDING_TITLE = 'Squadding at SA Champs by ranking and division';

    private const SQUADDING_NOTE = 'As set out in the notice for SA Champs 21-22 November, squadding is by overall ranking. Ladies and juniors will each have their own squad if there are enough entries to fill one, or they will be squadded together in one squad. The first day of the SA Champs will also count as the last provincial match for the season.';

    public function up(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->text('note')->nullable()->after('body');
        });

        DB::table('decisions')
            ->where('title', self::SQUADDING_TITLE)
            ->whereNull('note')
            ->update(['note' => self::SQUADDING_NOTE]);
    }

    public function down(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
