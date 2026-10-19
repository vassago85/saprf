<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an explicit MD-driven "scores are final" gate on matches.
 *
 * This is intentionally independent of `matches.status`:
 *   - `status = completed` can be auto-set after a successful import,
 *     after a payout request, or by the nightly auto-complete job.
 *   - Scores are still visible on the public results page as soon as
 *     a score row exists (provisional publishing — unchanged).
 *   - What *this* flag unlocks is the shooter notification: we only
 *     tell shooters "the scores are up and names/entries are checked"
 *     when the MD actually clicks "Mark scores complete".
 *
 * `scores_finalized_notified_at` is kept as a separate column so that
 * un-marking + re-marking (e.g. MD spots a typo, fixes it, re-finalises)
 * does NOT spam everyone a second time — the notify job skips when the
 * timestamp is already set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->timestamp('scores_finalized_at')->nullable()->after('status');
            $table->foreignId('scores_finalized_by')
                ->nullable()
                ->after('scores_finalized_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('scores_finalized_notified_at')->nullable()->after('scores_finalized_by');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scores_finalized_by');
            $table->dropColumn(['scores_finalized_at', 'scores_finalized_notified_at']);
        });
    }
};
