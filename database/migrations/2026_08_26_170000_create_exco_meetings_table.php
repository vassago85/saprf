<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Root row for an ExCo sitting. Kept deliberately small: the working
     * data lives on the child agenda-item / action rows.
     *
     * `status` walks draft (building the agenda) -> held (minutes being
     * captured) -> closed (nothing further to add). We do not model
     * quorum, voting, or in-progress vs adjourned — this is a working
     * notepad, not the Judicial Code hearing pipeline.
     */
    public function up(): void
    {
        Schema::create('exco_meetings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('type', 20)->default('regular');
            $table->timestamp('scheduled_at');
            $table->string('location', 200)->nullable();
            $table->text('attendance_notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exco_meetings');
    }
};
