<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('score_id')->constrained('scores')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->decimal('category_normalized_score', 8, 4)->nullable();
            $table->unsignedInteger('category_rank')->nullable();

            $table->unique(['score_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_category');
    }
};
