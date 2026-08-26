<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files attached to a disciplinary case. Stored on the dedicated
     * private `disciplinary` disk (storage/app/disciplinary) — never on
     * the public disk. Members must not be able to touch these files by
     * URL guessing; download happens through an authenticated
     * ExCo-gated controller.
     */
    public function up(): void
    {
        Schema::create('disciplinary_case_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('disciplinary_cases')->cascadeOnDelete();
            $table->string('path');
            $table->string('filename', 200);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_case_attachments');
    }
};
