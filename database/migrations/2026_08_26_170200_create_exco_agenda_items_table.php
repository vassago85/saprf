<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per agenda line for an ExCo meeting. `briefing` is what
     * the chair drops in before the meeting; `minutes` is what actually
     * got captured during the sitting. Two fields so both can be kept
     * side-by-side after the meeting.
     *
     * `visibility = confidential` hides the item's minutes from any
     * future member-facing summary (there isn't one yet, but we bake in
     * the flag so we do not have to migrate later). ExCo/Chair still
     * sees everything.
     *
     * `disciplinary_case_id` links an agenda item to a running case so
     * the minutes and the case notes stay side-by-side. Deleting the
     * case nulls the FK — the agenda item survives so the historical
     * meeting record stays intact.
     */
    public function up(): void
    {
        Schema::create('exco_agenda_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('exco_meetings')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('title', 200);
            $table->text('briefing')->nullable();
            $table->text('minutes')->nullable();
            $table->string('visibility', 20)->default('ordinary');
            $table->foreignId('disciplinary_case_id')->nullable()
                ->constrained('disciplinary_cases')->nullOnDelete();
            $table->timestamps();

            $table->index(['meeting_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exco_agenda_items');
    }
};
