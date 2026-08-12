<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds evaluation_mode to selection_cycles so a cycle can opt out of the
 * strict per-rule evaluators and treat every athlete as eligible + having
 * participated (the "assume everyone qualifies" mode). Used for:
 *
 *   - historical cycles whose teams were already selected before the
 *     subsystem existed (no point back-filling detailed rule evaluations);
 *   - current cycles where the source data for the strict rules isn't
 *     complete yet (citizenship, club recognition, sanctioning body, etc.) —
 *     in these the nomination letter (DEC-01) is the only real gate.
 *
 * Defaults to 'assume_qualified' because that mirrors the current data
 * reality. Cycles can be flipped to 'strict' via the admin UI once data
 * quality catches up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('selection_cycles', function (Blueprint $table) {
            $table->string('evaluation_mode', 32)
                ->default('assume_qualified')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('selection_cycles', function (Blueprint $table) {
            $table->dropColumn('evaluation_mode');
        });
    }
};
