<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_income', function (Blueprint $table) {
            $table->foreignId('sponsor_id')
                ->nullable()
                ->after('category')
                ->constrained('sponsors')
                ->nullOnDelete();

            $table->index('sponsor_id');
        });
    }

    public function down(): void
    {
        Schema::table('platform_income', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sponsor_id');
        });
    }
};
