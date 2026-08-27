<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proposed amendments to circulated meeting minutes. Any ExCo member
     * can submit one during the review window (minutes circulated ->
     * not yet adopted); the chair/secretary then accepts (applies the
     * edit) or rejects with a note.
     *
     * The amendment is a first-class row so the resolution trail
     * survives adoption — accepted and rejected amendments are shown
     * in the printable minutes as part of the audit record, not
     * squashed into the minute text.
     */
    public function up(): void
    {
        Schema::create('exco_minute_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')
                ->constrained('exco_meetings')
                ->cascadeOnDelete();
            // Nullable so a member can post a general comment on the
            // minutes as a whole (rare but legitimate).
            $table->foreignId('agenda_item_id')
                ->nullable()
                ->constrained('exco_agenda_items')
                ->cascadeOnDelete();
            $table->foreignId('proposed_by')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->text('proposed_text');
            $table->string('status', 20)->default('pending');
            $table->foreignId('resolved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['meeting_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exco_minute_amendments');
    }
};
