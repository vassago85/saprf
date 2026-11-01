<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The match close-out wizard has an explicit step where the match director
 * acknowledges that both the platform fee and the SAPRF levy are deducted
 * from their payout for every walk-in on this match. We record WHO ticked
 * the box and WHEN so a finance query ("why was the MD's payout short?")
 * has a definitive trail.
 *
 * Nullable because:
 *   - older matches pre-wizard will never see the UI, but still need to
 *     flow through the payout gate; the controller treats "no walk-ins
 *     and no acknowledgement" as implicit consent, and a direct admin
 *     payout action can bypass the gate.
 *   - a NEW walk-in added after the box is ticked clears both columns so
 *     the MD re-confirms the fresh total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->timestamp('walkin_fees_acknowledged_at')
                ->nullable()
                ->after('scores_finalized_notified_at');
            $table->foreignId('walkin_fees_acknowledged_by')
                ->nullable()
                ->after('walkin_fees_acknowledged_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('walkin_fees_acknowledged_by');
            $table->dropColumn('walkin_fees_acknowledged_at');
        });
    }
};
