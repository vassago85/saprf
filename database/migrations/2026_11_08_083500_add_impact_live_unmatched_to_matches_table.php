<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->json('impact_live_unmatched')->nullable()->after('impact_live_error');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('impact_live_unmatched');
        });
    }
};
