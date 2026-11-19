<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Split the old `club` series level into two:
     *
     *   - `club_only`         — only members of the host club can enter.
     *                            Scores never feed the provincial log.
     *   - `club_provincial`   — hosted at a club, but entry works like a
     *                            provincial. Full members can always enter,
     *                            club-tier shooters only when the match
     *                            director turns on Allow club members. Full
     *                            members' scores count on the provincial log.
     *
     * Existing `series_level = 'club'` rows migrate to `club_only`. They have
     * no host club today (the column did not exist), so the entry gate stays
     * open until an MD picks a club.
     *
     * Three new columns:
     *
     *   - `club_id`                    — the host club. Required by the form
     *                                     when the level is `club_only` or
     *                                     `club_provincial`.
     *   - `registration_method`        — `platform` (pay on SAPRF, the
     *                                     default) or `external` (send the
     *                                     shooter to the club's own URL
     *                                     after creating the SAPRF entry).
     *   - `external_registration_url`  — where to redirect when the method
     *                                     is `external`. Required by the form
     *                                     when the method is `external`.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->foreignId('club_id')
                ->nullable()
                ->after('province_id')
                ->constrained('clubs')
                ->nullOnDelete();

            $table->string('registration_method', 20)
                ->default('platform')
                ->after('club_entry_enabled');

            $table->string('external_registration_url', 500)
                ->nullable()
                ->after('registration_method');
        });

        // Promote every historical club match to club_only. These rows have
        // no host club, so the entry gate stays permissive (see
        // MembershipValidationService::mayEnterMatch — the host-club check
        // only applies once `club_id` is set). This keeps today's behaviour
        // intact while the MD picks a club at their leisure.
        DB::table('matches')
            ->where('series_level', 'club')
            ->update(['series_level' => 'club_only']);
    }

    public function down(): void
    {
        DB::table('matches')
            ->where('series_level', 'club_only')
            ->update(['series_level' => 'club']);

        DB::table('matches')
            ->where('series_level', 'club_provincial')
            ->update(['series_level' => 'club']);

        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['external_registration_url', 'registration_method']);
            $table->dropConstrainedForeignId('club_id');
        });
    }
};
