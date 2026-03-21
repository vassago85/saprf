<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_division', function (Blueprint $table) {
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('division_id')->constrained('divisions')->cascadeOnDelete();

            $table->primary(['match_id', 'division_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_division');
    }
};
