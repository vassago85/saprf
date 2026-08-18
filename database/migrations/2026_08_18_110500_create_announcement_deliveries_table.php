<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-channel delivery outcome for each recipient. One row per
     * (recipient, channel) attempted — so a recipient who receives a
     * high-priority policy notice via in-app + email + push produces
     * three rows.
     *
     * `error` is truncated to 500 chars because the goal is a
     * human-scannable audit, not a full stack trace (those live in the
     * queue log).
     */
    public function up(): void
    {
        Schema::create('announcement_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_recipient_id')
                ->constrained('announcement_recipients')
                ->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('status', 20)->default('queued');
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_recipient_id', 'channel']);
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_deliveries');
    }
};
