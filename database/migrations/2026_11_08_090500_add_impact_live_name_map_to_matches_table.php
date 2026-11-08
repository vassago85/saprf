<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // { "Impact shooter name" => registration_id } saved by the match
            // director when an Impact name does not exactly match an entry
            // (nickname, surname difference). The live sync reads it before
            // falling through to the "unmatched" list.
            $table->json('impact_live_name_map')->nullable()->after('impact_live_unmatched');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('impact_live_name_map');
        });
    }
};
