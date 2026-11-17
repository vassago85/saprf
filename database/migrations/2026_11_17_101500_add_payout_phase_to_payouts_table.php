<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A match director may request two payouts for one match: one before the
 * match, and one after it starts. Existing rows stay null and are treated
 * as the before-match payout so a post-match top-up can still be requested.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->string('payout_phase', 20)->nullable()->after('payee_type');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropColumn('payout_phase');
        });
    }
};
