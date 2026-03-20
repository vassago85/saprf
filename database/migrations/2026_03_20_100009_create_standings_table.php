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
            $table->string('series');
            $table->string('season');
            $table->foreignId('province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $table->decimal('points', 10, 3)->default(0);
            $table->unsignedInteger('rank')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'series', 'season', 'province_id']);
            $table->index(['series', 'season', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standings');
    }
};
