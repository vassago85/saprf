<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('series', 20);
            $table->string('season', 10);
            $table->foreignId('province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained('divisions')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->decimal('points', 10, 3)->default(0);
            $table->unsignedInteger('rank')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'series', 'season', 'province_id', 'division_id', 'category_id'], 'standings_composite_unique');
            $table->index(['series', 'season', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standings');
    }
};
