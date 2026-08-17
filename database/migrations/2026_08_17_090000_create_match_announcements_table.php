<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Match director broadcasts to entrants. Each row records a single
     * "Message entrants" send: the subject/body actually mailed, the MD
     * who sent it, the registration statuses that were in scope, and how
     * many unique recipients ended up on the list after junior-to-parent
     * routing and dedupe. Persisted so MDs can audit what went out and
     * so POPIA subject requests can retrieve it.
     */
    public function up(): void
    {
        Schema::create('match_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->string('subject', 200);
            $table->text('body');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->json('status_scope');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['match_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_announcements');
    }
};
