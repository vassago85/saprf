<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->foreignId('results_announcement_id')
                ->nullable()
                ->after('announcement_id')
                ->constrained('announcements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('results_announcement_id');
        });
    }
};
