<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frozen recipient list. Written once by ResolveAudienceJob when an
     * announcement starts sending; never mutated after that. If a member
     * lapses after we already mailed them, the archive still shows they
     * received the notice — which is what makes this audit-worthy.
     *
     * `read_at` and `acknowledged_at` are per-recipient state written by
     * the member portal (marking as read on open; the "I acknowledge"
     * button on required announcements). `unique(announcement_id,
     * user_id)` guards against ever accidentally double-billing a
     * recipient during the resolve pass.
     */
    public function up(): void
    {
        Schema::create('announcement_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
            $table->index(['announcement_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
    }
};
