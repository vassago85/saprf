<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('score_imports', function (Blueprint $table) {
            // For 2-day matches, an MD uploads one CSV per day. This flag tells the
            // importer which day the file represents (1 or 2). Null = 1-day match /
            // legacy import (writes raw_score directly).
            $table->unsignedTinyInteger('day')->nullable()->after('source_type');
        });
    }

    public function down(): void
    {
        Schema::table('score_imports', function (Blueprint $table) {
            $table->dropColumn('day');
        });
    }
};
