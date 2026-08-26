<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Confidential ExCo case register. Every row is POPIA-sensitive and
     * only visible to `developer|exco|chair`. Notes and attachments live
     * on child tables so the timeline of who added what is preserved.
     *
     * `reference` is human-facing (e.g. DC-2026-001) and stamped by the
     * controller on create so ExCo can quote it in minutes/emails without
     * revealing the numeric id.
     *
     * A subject may or may not be a platform user: for a non-member
     * `subject_user_id` is null and `subject_name` holds a free-text
     * label. Exactly one of the two must be present — enforced in the
     * request validator.
     */
    public function up(): void
    {
        Schema::create('disciplinary_cases', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('subject_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('subject_name', 200)->nullable();
            $table->string('title', 200);
            $table->text('summary')->nullable();
            $table->string('status', 30)->default('reported');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('subject_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_cases');
    }
};
