<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_registrations', function (Blueprint $table) {
            $table->unsignedInteger('shot_count')->nullable()->after('rifle_configuration_id');
        });

        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->unsignedInteger('total_barrel_rounds')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('match_registrations', function (Blueprint $table) {
            $table->dropColumn('shot_count');
        });

        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->dropColumn('total_barrel_rounds');
        });
    }
};
