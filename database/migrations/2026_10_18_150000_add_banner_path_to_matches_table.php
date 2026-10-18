<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional promotional banner MD's can upload from match setup.
     * Stored on the public disk under match-banners/.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->string('banner_path')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('banner_path');
        });
    }
};
