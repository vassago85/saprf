<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend the `role` enum on national_team_appearances with 'reserve'.
     *
     * Reserves are selected onto the national team but do not shoot (they
     * stand in if a main-squad shooter can't start). They have been
     * officially named in team photos and press releases since at least
     * the 2025 PR22lr Worlds, so they deserve a first-class slot in the
     * roster model — not a free-text hack in `division_label`.
     *
     * Enforcement rules that depend on this role being structural:
     *   - They DO NOT earn Protea Colours (NationalTeamAppearanceController
     *     already blocks Colours on role=manager; reserves get the same
     *     treatment).
     *   - They DO NOT count toward the "squad size" stat on the public
     *     team page — the shooting squad is the main 22, not the 27.
     *   - They RENDER in a dedicated Reserves section below the main
     *     division cards on /sa-team/{year}.
     *
     * SQLite (used in tests) ignores enum constraints, so this migration
     * is a no-op there. On MySQL we rewrite the enum to add 'reserve'.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            // SQLite / Postgres etc. — the `role` column is a plain
            // string on those drivers, no enum rewrite needed.
            return;
        }

        DB::statement("
            ALTER TABLE national_team_appearances
            MODIFY COLUMN role ENUM('shooter', 'captain', 'manager', 'official', 'reserve')
            NOT NULL DEFAULT 'shooter'
        ");
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        // Demote any 'reserve' rows to 'shooter' before shrinking the enum,
        // otherwise MySQL rejects the ALTER on data that no longer fits.
        DB::table('national_team_appearances')
            ->where('role', 'reserve')
            ->update(['role' => 'shooter']);

        DB::statement("
            ALTER TABLE national_team_appearances
            MODIFY COLUMN role ENUM('shooter', 'captain', 'manager', 'official')
            NOT NULL DEFAULT 'shooter'
        ");
    }
};
