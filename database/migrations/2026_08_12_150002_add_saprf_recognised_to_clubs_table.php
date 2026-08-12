<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clubs', 'saprf_recognised')) {
            Schema::table('clubs', function (Blueprint $table) {
                $table->boolean('saprf_recognised')->default(true)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clubs', 'saprf_recognised')) {
            Schema::table('clubs', function (Blueprint $table) {
                $table->dropColumn('saprf_recognised');
            });
        }
    }
};
