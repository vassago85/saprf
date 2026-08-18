<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track hard-bounces on users so future non-mandatory broadcasts skip
 * dead email addresses instead of hammering Mailgun with retries.
 *
 * `email_bounced_at` = timestamp of the first permanent failure Mailgun
 * reported. Clearing this column is a manual admin action (the user
 * updates their email → we reset it in the profile update path in a
 * follow-up commit).
 *
 * `email_bounce_count` = running total of permanent failures. Not used
 * for logic yet, but very useful for reporting ("show me every user
 * whose mail has bounced more than once this month") without a JOIN
 * across the delivery table.
 *
 * `email_complained_at` = the user hit "spam" on a message. Treated
 * the same as a hard bounce for send-skipping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('email_bounced_at')->nullable()->after('email_verified_at');
            $table->unsignedInteger('email_bounce_count')->default(0)->after('email_bounced_at');
            $table->timestamp('email_complained_at')->nullable()->after('email_bounce_count');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['email_bounced_at', 'email_bounce_count', 'email_complained_at']);
        });
    }
};
