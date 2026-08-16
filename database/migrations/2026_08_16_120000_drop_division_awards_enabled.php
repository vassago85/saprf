<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('matches', 'division_awards_enabled')) {
            Schema::table('matches', function (Blueprint $table) {
                $table->dropColumn('division_awards_enabled');
            });
        }

        DB::table('settings')->where('key', 'division_awards_enabled')->delete();
        Cache::forget('saprf_settings');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('matches', 'division_awards_enabled')) {
            Schema::table('matches', function (Blueprint $table) {
                $table->boolean('division_awards_enabled')->default(false);
            });
        }
    }
};
