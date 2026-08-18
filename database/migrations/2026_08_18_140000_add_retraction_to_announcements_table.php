<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds retraction bookkeeping to announcements.
 *
 * "Retract" is a soft-off-switch for a *sent* announcement: the email is
 * already in inboxes and cannot be recalled, but we hide the row from
 * every member's /communications archive so they stop seeing it in the
 * app. Different concept to `cancelled` — cancelled means "killed before
 * anyone got it"; retracted means "sent by mistake, we're hiding the
 * archived copy while acknowledging the email itself already left the
 * building". The row itself stays in the DB for audit forensics.
 *
 * Draft/cancelled announcements are handled separately via soft-delete
 * (SoftDeletes trait already in place on the model), so this migration
 * only owns retraction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->timestamp('retracted_at')->nullable()->after('sent_at');
            $table->foreignId('retracted_by')->nullable()->after('retracted_at')
                ->constrained('users')->nullOnDelete();
            $table->string('retraction_reason', 500)->nullable()->after('retracted_by');

            // Member-facing queries filter `WHERE retracted_at IS NULL`;
            // partial-ish index (via covering both flavours) keeps that
            // hot path quick even as the table grows.
            $table->index(['retracted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['retracted_at']);
            $table->dropForeign(['retracted_by']);
            $table->dropColumn(['retracted_at', 'retracted_by', 'retraction_reason']);
        });
    }
};
