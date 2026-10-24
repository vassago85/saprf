<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rolling history of the public standings so the standings page can render
 * ▲n / ▼n movement arrows next to each shooter without recomputing from
 * scores. One row per (shooter × scope) is written every time a match's
 * scores are finalised or a corrective recalc runs, letting StandingsMovement
 * compare "current rank" against "the rank at the previous finalisation".
 *
 * The index on (season, series, level, user_id, taken_at) is what the
 * "previous snapshot per user" lookup is tuned for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standings_snapshots', function (Blueprint $table) {
            $table->id();

            $table->string('season', 10);
            $table->string('series', 10);              // PRS | PR22
            $table->string('level', 20);               // national | provincial

            // Scope narrowers — both nullable so the overall-national
            // standings row is (province_id=null, division_id=null) and
            // a provincial Open table is (province_id=X, division_id=Y).
            $table->foreignId('province_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->foreignId('user_id')
                ->constrained()->cascadeOnDelete();

            $table->unsignedInteger('rank');
            $table->decimal('points', 10, 3);

            // Timestamp AT which this snapshot was taken — matches
            // MatchEvent.scores_finalized_at when the trigger was a
            // completeScores call, else now(). We query "most recent
            // taken_at per (user, scope) that is older than the latest",
            // so a dedicated index on it beats a trailing timestamps.
            $table->dateTime('taken_at')->index();

            $table->foreignId('triggered_by_match_id')->nullable()
                ->constrained('matches')->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['season', 'series', 'level', 'user_id', 'taken_at'],
                'standings_snapshots_scope_user_time_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standings_snapshots');
    }
};
