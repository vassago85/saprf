<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shooter_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('score_id')->constrained('scores')->cascadeOnUpdate()->restrictOnDelete();
            $table->boolean('counted')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('score_id');
            $table->index(['user_id', 'counted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shooter_logs');
    }
};
