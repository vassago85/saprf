<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt-in flag for the club-entry pricing bracket.
     *
     * Before this flag existed, every club and provincial match silently
     * routed non-members onto the surcharge-free `club_entry` fee bracket.
     * That surprised match directors running provincial championships who
     * expected the normal non-member surcharge to apply — the flag turns the
     * behaviour into an explicit decision per match.
     *
     * Default is `false`. On national, final, and international matches the
     * flag is ignored (`supportsClubEntry()` already short-circuits those
     * levels), so forcing it to `false` for every row is only cosmetic there.
     *
     * Backfill:
     *   - Any match with a non-null `club_entry_fee`
     *   - Any match that already carries at least one registration booked at
     *     `membership_fee_category = 'club_entry'`
     *   gets `club_entry_enabled = true` so its historical behaviour is
     *   preserved — new sign-ups on those matches keep landing on the
     *   club price, exactly as they do today. Every other match starts
     *   with the flag off and the MD must flip it on to use the bracket.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->boolean('club_entry_enabled')
                ->default(false)
                ->after('club_entry_fee');
        });

        // Backfill existing matches whose historical behaviour depends on
        // the flag now being on. Done in a single UPDATE so a slow
        // registrations table doesn't drag the migration into N+1.
        DB::statement(<<<'SQL'
            UPDATE matches
            SET club_entry_enabled = 1
            WHERE series_level IN ('club', 'provincial')
              AND (
                club_entry_fee IS NOT NULL
                OR id IN (
                    SELECT match_id
                    FROM match_registrations
                    WHERE membership_fee_category = 'club_entry'
                )
              )
        SQL);
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('club_entry_enabled');
        });
    }
};
