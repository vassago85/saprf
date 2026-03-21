<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qualification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('series');
            $table->string('season');
            $table->unsignedInteger('min_out_of_province_matches')->default(0);
            $table->unsignedSmallInteger('best_of_count')->nullable();
            $table->unsignedSmallInteger('total_qualifying_matches')->nullable();
            $table->boolean('weighted_final_enabled')->default(false);
            $table->decimal('weighted_final_multiplier', 4, 2)->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['series', 'season']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualification_rules');
    }
};
