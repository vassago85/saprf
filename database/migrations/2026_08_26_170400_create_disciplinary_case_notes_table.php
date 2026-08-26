<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Timestamped notes on a disciplinary case. Append-only from the UI
     * (delete is limited to the note author) so the case timeline is
     * evidentiary and cannot be quietly rewritten by whoever opens the
     * page last.
     */
    public function up(): void
    {
        Schema::create('disciplinary_case_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('disciplinary_cases')->cascadeOnDelete();
            $table->text('body');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_case_notes');
    }
};
