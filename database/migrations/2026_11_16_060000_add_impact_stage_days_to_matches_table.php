<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Impact stage id => 1 or 2. The match director sets every stage
            // before day-1 totals can be copied onto the provincial sibling.
            $table->json('impact_stage_days')->nullable()->after('impact_live_name_map');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('impact_stage_days');
        });
    }
};
