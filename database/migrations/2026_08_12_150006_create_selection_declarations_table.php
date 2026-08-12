<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selection_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selection_athlete_id')
                ->constrained('selection_athletes')
                ->cascadeOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('form_data')->nullable();
            $table->string('signed_form_path')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->unique('selection_athlete_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('selection_declarations');
    }
};
