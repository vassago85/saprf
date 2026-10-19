<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three per-user toggles for the "scores are complete" notification plus
 * a `score_prefs_configured_at` marker so the one-time post-login prompt
 * knows whether the shooter has ever expressed a preference.
 *
 *   notify_own_match_scores (default true)
 *     Shooter is told when matches *they shot* are finalised.
 *
 *   notify_all_prs_scores / notify_all_pr22_scores (default false)
 *     Subscribe to every finalised match in a series, even ones they
 *     did not shoot. Opt-in only.
 *
 *   score_prefs_configured_at
 *     Null → show the one-time prompt on next sign-in.
 *     Set   → never show the prompt again. Updating prefs via the
 *             profile form also stamps this (saving the form is an
 *             equally explicit choice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('notify_own_match_scores')->default(true)->after('push_enabled');
            $table->boolean('notify_all_prs_scores')->default(false)->after('notify_own_match_scores');
            $table->boolean('notify_all_pr22_scores')->default(false)->after('notify_all_prs_scores');
            $table->timestamp('score_prefs_configured_at')->nullable()->after('notify_all_pr22_scores');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'notify_own_match_scores',
                'notify_all_prs_scores',
                'notify_all_pr22_scores',
                'score_prefs_configured_at',
            ]);
        });
    }
};
