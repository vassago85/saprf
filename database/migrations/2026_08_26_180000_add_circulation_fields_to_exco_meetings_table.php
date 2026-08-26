<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track the minutes-approval lifecycle that lives AFTER a meeting is
     * closed:
     *
     *   closed        -> minutes drafted, not yet sent out
     *   circulated    -> emailed to ExCo for review (minutes_circulated_at)
     *   adopted       -> formally accepted at a subsequent sitting
     *                    (minutes_adopted_at + minutes_adopted_meeting_id)
     *
     * Kept as timestamp fields on the existing `exco_meetings` table
     * rather than a new status enum value so the status transitions in
     * ExcoMeetingController remain the simple draft->held->closed walk.
     */
    public function up(): void
    {
        Schema::table('exco_meetings', function (Blueprint $table) {
            $table->timestamp('minutes_circulated_at')->nullable()->after('status');
            $table->foreignId('minutes_circulated_by')
                ->nullable()
                ->after('minutes_circulated_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('minutes_adopted_at')->nullable()->after('minutes_circulated_by');
            $table->foreignId('minutes_adopted_meeting_id')
                ->nullable()
                ->after('minutes_adopted_at')
                ->constrained('exco_meetings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exco_meetings', function (Blueprint $table) {
            $table->dropForeign(['minutes_adopted_meeting_id']);
            $table->dropForeign(['minutes_circulated_by']);
            $table->dropColumn([
                'minutes_circulated_at',
                'minutes_circulated_by',
                'minutes_adopted_at',
                'minutes_adopted_meeting_id',
            ]);
        });
    }
};
