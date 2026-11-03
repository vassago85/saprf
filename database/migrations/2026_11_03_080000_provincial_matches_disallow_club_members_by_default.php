<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Provincial matches must not accept club members unless a match
     * director opts in. The previous migration turned the flag on for any
     * provincial that already had a club price or a club-entry registration,
     * which kept club members able to enter by default. Turn it off.
     * Entries already on the match are left as they are.
     */
    public function up(): void
    {
        DB::table('matches')
            ->where('series_level', 'provincial')
            ->update(['club_entry_enabled' => false]);
    }

    public function down(): void
    {
        // Opting club members in is a match-director decision. There is
        // nothing earlier to restore.
    }
};
