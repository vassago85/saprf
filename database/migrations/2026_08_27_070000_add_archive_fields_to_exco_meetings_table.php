<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archive escape hatch for closed meetings. Hard-deleting a closed
     * meeting silently breaks audit logs, action items linked to it, and
     * the "adopted at meeting X" back-reference — archiving instead
     * hides the row from active views while keeping every relationship
     * intact.
     *
     * Draft and held meetings still hard-delete via the existing
     * destroy() flow (they're throwaway cleanup, not history).
     */
    public function up(): void
    {
        Schema::table('exco_meetings', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('minutes_adopted_meeting_id');
            $table->foreignId('archived_by')
                ->nullable()
                ->after('archived_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('archive_reason', 500)->nullable()->after('archived_by');

            // Every list view starts with "not archived", so index the
            // column to keep those queries cheap as the archive grows.
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('exco_meetings', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropForeign(['archived_by']);
            $table->dropColumn(['archived_at', 'archived_by', 'archive_reason']);
        });
    }
};
