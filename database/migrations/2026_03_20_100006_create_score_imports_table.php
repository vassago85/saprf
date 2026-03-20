<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('source_type')->default('manual');
            $table->string('original_filename');
            $table->string('import_status')->default('pending');
            $table->text('notes')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['match_id', 'import_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_imports');
    }
};
