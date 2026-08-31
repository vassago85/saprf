<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when the MD/admin last emailed a shooter about an outstanding
 * match entry fee. Used to:
 *
 *   1. Prevent accidental double-sends (UI shows "Inquiry sent 2h ago"
 *      once it's populated, instead of a fresh "Send" button).
 *   2. Give staff a plain audit trail on the registration row itself —
 *      no need to grep the audit log to know the nudge went out.
 *
 * Nullable timestamp on purpose: absence means "never asked".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_registrations', function (Blueprint $table): void {
            $table->timestamp('payment_inquiry_sent_at')
                ->nullable()
                ->after('walk_in_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('match_registrations', function (Blueprint $table): void {
            $table->dropColumn('payment_inquiry_sent_at');
        });
    }
};
