<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Club entry fee. On a `club` or `provincial` match this is what a
     * non-member pays — a match-director-set price, separate from the
     * active-member fee, with no non-member surcharge added on top.
     *
     * Nullable: when the MD leaves it blank, pricing falls back to the
     * active-member fee so club entrants pay the same as members. Nationals,
     * finals, and internationals ignore this column entirely — those matches
     * keep today's non-member surcharge.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->decimal('club_entry_fee', 10, 2)
                ->nullable()
                ->after('junior_fee');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('club_entry_fee');
        });
    }
};
