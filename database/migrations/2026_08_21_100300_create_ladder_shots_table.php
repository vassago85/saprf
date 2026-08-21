<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ladder_shots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ladder_step_id')->constrained()->cascadeOnDelete();
            $table->decimal('velocity_fps', 6, 1);
            $table->unsignedSmallInteger('sequence')->default(0);
            // Excluded shots stay visible in the UI but leave every calculation —
            // the shooter drops chrono misreads without losing the record of them.
            $table->boolean('excluded')->default(false);
            $table->timestamps();

            $table->index(['ladder_step_id', 'excluded']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ladder_shots');
    }
};
