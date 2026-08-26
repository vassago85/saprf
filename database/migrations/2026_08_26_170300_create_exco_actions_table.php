<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Follow-up action items generated in ExCo meetings. Every action has
     * an owner (`assigned_to`) and a due date so "what's going on" is
     * one query away between sittings.
     *
     * Origin links are all optional and independent: an action may be
     * attached to a meeting overall, to a specific agenda item, to a
     * disciplinary case, or to none of the above (ad-hoc between
     * meetings). Deletes null the FKs so the action itself is preserved
     * even after its originating meeting is removed.
     */
    public function up(): void
    {
        Schema::create('exco_actions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('details')->nullable();
            $table->foreignId('assigned_to')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('meeting_id')->nullable()
                ->constrained('exco_meetings')->nullOnDelete();
            $table->foreignId('agenda_item_id')->nullable()
                ->constrained('exco_agenda_items')->nullOnDelete();
            $table->foreignId('disciplinary_case_id')->nullable()
                ->constrained('disciplinary_cases')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_on']);
            $table->index('assigned_to');
            $table->index('meeting_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exco_actions');
    }
};
