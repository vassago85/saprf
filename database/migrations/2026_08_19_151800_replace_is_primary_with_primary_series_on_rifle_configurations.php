<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->string('primary_series', 8)->nullable()->after('is_primary');
            $table->boolean('show_on_profile')->default(false)->after('primary_series');
        });

        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->unique(['user_id', 'primary_series']);
            $table->dropColumn('is_primary');
        });
    }

    public function down(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'primary_series']);
            $table->boolean('is_primary')->default(false)->after('notes');
        });

        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->dropColumn(['primary_series', 'show_on_profile']);
        });
    }
};
