<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A match with `series_level = 'final'` means "Final / Champs" in the
     * admin UI — but that covers every provincial/club championship as well
     * as the actual SA Championships. The federation colour rules only award
     * a colour once a shooter has shot the *real* SA Champs, so this explicit
     * flag separates the two. The box is only tickable on the match-edit form
     * when the series level is already set to `final`.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->boolean('is_sa_championships')->default(false)->after('series_level');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('is_sa_championships');
        });
    }
};
