<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            if (!Schema::hasColumn('matches', 'match_director')) {
                $table->string('match_director')->nullable()->after('venue_location');
            }
            if (!Schema::hasColumn('matches', 'match_director_contact')) {
                $table->string('match_director_contact')->nullable()->after('match_director');
            }
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            foreach (['match_director', 'match_director_contact'] as $col) {
                if (Schema::hasColumn('matches', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
