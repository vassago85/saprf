<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A raw_score of 0 on an imported match is ambiguous: the shooter may have
 * genuinely zeroed the match (DNF/no points), or they may have been listed
 * on the score sheet by mistake and never turned up. The MD needs to
 * distinguish the two so the results and season log stay honest.
 *
 * These columns record the MD's explicit confirmation that the zero was
 * genuine participation. Non-zero scores don't need confirmation — this is
 * only surfaced in the score-imports/{id} review banner when raw_score is 0
 * and participation_confirmed_at is null. Marking a shooter absent deletes
 * their score row entirely, so a "confirmed absent" state is not needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scores', function (Blueprint $table): void {
            $table->timestamp('participation_confirmed_at')->nullable()->after('validation_reason');
            $table->foreignId('participation_confirmed_by')
                ->nullable()
                ->after('participation_confirmed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table): void {
            $table->dropForeign(['participation_confirmed_by']);
            $table->dropColumn(['participation_confirmed_at', 'participation_confirmed_by']);
        });
    }
};
