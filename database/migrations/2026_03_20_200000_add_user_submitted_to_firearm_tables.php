<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firearm_makes', function (Blueprint $table) {
            $table->boolean('user_submitted')->default(false)->after('is_active');
        });

        Schema::table('firearm_models', function (Blueprint $table) {
            $table->boolean('user_submitted')->default(false)->after('is_active');
        });

        Schema::table('firearm_calibres', function (Blueprint $table) {
            $table->boolean('user_submitted')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('firearm_makes', function (Blueprint $table) {
            $table->dropColumn('user_submitted');
        });

        Schema::table('firearm_models', function (Blueprint $table) {
            $table->dropColumn('user_submitted');
        });

        Schema::table('firearm_calibres', function (Blueprint $table) {
            $table->dropColumn('user_submitted');
        });
    }
};
