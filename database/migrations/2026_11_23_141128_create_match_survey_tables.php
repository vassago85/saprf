<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anonymous post-match survey, keyed on provincial / national / final
 * (championship) matches.
 *
 * Two tables by design — same split as the Decision Register:
 *   - match_survey_respondents  — who answered (never what)
 *   - match_survey_responses    — the answers (never who)
 *
 * The respondent row carries no timestamps so a clock stamp cannot be
 * joined back onto a response row to recover identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_survey_respondents', function (Blueprint $table) {
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->primary(['match_id', 'user_id']);
        });

        Schema::create('match_survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            // Answers payload. Shape is owned by MatchSurveyService; the
            // migration deliberately does not dictate individual columns
            // so the question set can evolve without a schema change.
            $table->json('answers');
            $table->timestamp('created_at')->useCurrent();

            $table->index('match_id');
        });

        // Set once per match when Exco fires the "email eligible shooters"
        // action. Non-null means the invite has already gone out; the UI
        // disables the button so a second click cannot resend.
        Schema::table('matches', function (Blueprint $table) {
            $table->timestamp('survey_invites_sent_at')->nullable()->after('walkin_fees_acknowledged_by');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('survey_invites_sent_at');
        });
        Schema::dropIfExists('match_survey_responses');
        Schema::dropIfExists('match_survey_respondents');
    }
};
