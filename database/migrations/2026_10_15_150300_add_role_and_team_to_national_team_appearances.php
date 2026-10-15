<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add role + national_team_id to appearances and backfill from the
     * existing text labels.
     *
     * Rationale:
     *   - "Team Manager" was encoded in `division_label`, forcing every
     *     consumer (badge, roster page, standings) to string-compare the
     *     label. A proper `role` enum (`shooter`, `captain`, `manager`,
     *     `official`) lets filters be structural.
     *   - "Open — Team Captain" was similarly a label hack. The backfill
     *     strips it back to "Open" and sets role=captain so the roster
     *     page can render a dedicated Captain tile.
     *
     * Each distinct (year, championship_name) pair becomes a NationalTeam
     * row; existing appearances are then linked by that pair.
     */
    public function up(): void
    {
        Schema::table('national_team_appearances', function (Blueprint $table) {
            $table->enum('role', ['shooter', 'captain', 'manager', 'official'])
                ->default('shooter')
                ->after('division_label');
            $table->foreignId('national_team_id')
                ->nullable()
                ->after('role')
                ->constrained('national_teams')
                ->nullOnDelete();
            $table->index(['national_team_id', 'role']);
        });

        // 1. Create a NationalTeam row for every (year, championship_name)
        //    tuple already in use.
        $tuples = DB::table('national_team_appearances')
            ->select('year', 'championship_name', 'host_country')
            ->whereNotNull('championship_name')
            ->distinct()
            ->get();

        foreach ($tuples as $tuple) {
            $discipline = str_contains(strtolower((string) $tuple->championship_name), 'rimfire')
                ? 'rimfire'
                : 'centrefire';

            // Use updateOrInsert so re-running after a partial failure is safe.
            $existing = DB::table('national_teams')
                ->where('year', $tuple->year)
                ->where('championship_name', $tuple->championship_name)
                ->first();

            if ($existing === null) {
                DB::table('national_teams')->insert([
                    'year' => $tuple->year,
                    'championship_name' => $tuple->championship_name,
                    'discipline' => $discipline,
                    'host_country' => $tuple->host_country,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 2. Link each appearance to its team. In PHP so it works on
        //    both MySQL (prod) and SQLite (tests) — the JOIN-UPDATE syntax
        //    isn't portable.
        $teams = DB::table('national_teams')
            ->get(['id', 'year', 'championship_name']);

        foreach ($teams as $team) {
            DB::table('national_team_appearances')
                ->where('year', $team->year)
                ->where('championship_name', $team->championship_name)
                ->update(['national_team_id' => $team->id]);
        }

        // 3. Promote structural roles from the free-text label.
        //    Done row-by-row so the captain parsing stays readable and
        //    works identically on MySQL and SQLite.
        $rows = DB::table('national_team_appearances')
            ->whereNotNull('division_label')
            ->get(['id', 'division_label']);

        foreach ($rows as $row) {
            $label = (string) $row->division_label;
            $lower = strtolower(trim($label));

            if ($lower === 'team manager') {
                DB::table('national_team_appearances')
                    ->where('id', $row->id)
                    ->update(['role' => 'manager', 'division_label' => 'Team Manager']);
                continue;
            }

            // "Open — Team Captain" (em dash) or "Open - Team Captain" (ASCII)
            if (preg_match('/\bTeam Captain\b/i', $label)) {
                $base = preg_split('/\s*[—\-]\s*Team Captain/i', $label)[0] ?? $label;
                DB::table('national_team_appearances')
                    ->where('id', $row->id)
                    ->update([
                        'role' => 'captain',
                        'division_label' => trim($base) ?: null,
                    ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('national_team_appearances', function (Blueprint $table) {
            $table->dropForeign(['national_team_id']);
            $table->dropIndex(['national_team_id', 'role']);
            $table->dropColumn(['role', 'national_team_id']);
        });
    }
};
