<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marker for the daily closed-survey report dispatcher.
 *
 * `survey_invites_sent_at` tracks the Exco/MD "email everyone the link"
 * action that happens when the survey opens. This column is the
 * symmetrical marker for the AUTOMATIC run that fires once the five-day
 * window has closed and emails the aggregated PDF to the match director,
 * plus every exco/owner/admin account. The sweep ignores matches where
 * the column is non-null, so it is naturally idempotent — a re-run never
 * sends the report twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            $table->timestamp('survey_report_sent_at')
                ->nullable()
                ->after('survey_invites_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            $table->dropColumn('survey_report_sent_at');
        });
    }
};
