<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Root row for a federation-wide announcement. Recipients live in
     * `announcement_recipients` (snapshotted at send time — see the
     * comment on that table); per-channel state lives in
     * `announcement_deliveries`.
     *
     * `send_at` is used by DispatchScheduledAnnouncementsJob when the
     * status is `scheduled`. `sent_at` is stamped once resolution has
     * finished and every chunk has been queued.
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->longText('body');
            $table->string('category', 40);
            $table->string('priority', 20)->default('normal');
            $table->boolean('requires_acknowledgement')->default(false);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('send_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'send_at']);
            $table->index('category');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
