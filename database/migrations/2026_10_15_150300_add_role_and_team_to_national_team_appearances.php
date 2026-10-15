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
     *
     * Idempotent: every step guards against being re-run, because a prod
     * failure mid-way-through must be re-runnable to recover. The initial
     * production rollout failed on step 3 (an em-dash in a legacy label
     * breaking a byte-mode regex) after step 1 already added the columns —
     * so the second attempt must not crash on "Duplicate column".
     */
    public function up(): void
    {
        // 1. Columns + FK + index — each guarded so re-running after a
        //    partial failure doesn't crash on "Duplicate column".
        if (! Schema::hasColumn('national_team_appearances', 'role')) {
            Schema::table('national_team_appearances', function (Blueprint $table) {
                $table->enum('role', ['shooter', 'captain', 'manager', 'official'])
                    ->default('shooter')
                    ->after('division_label');
            });
        }

        if (! Schema::hasColumn('national_team_appearances', 'national_team_id')) {
            Schema::table('national_team_appearances', function (Blueprint $table) {
                $table->foreignId('national_team_id')
                    ->nullable()
                    ->after('role')
                    ->constrained('national_teams')
                    ->nullOnDelete();
            });
        }

        if (! $this->indexExists('national_team_appearances', 'national_team_appearances_national_team_id_role_index')) {
            Schema::table('national_team_appearances', function (Blueprint $table) {
                $table->index(['national_team_id', 'role']);
            });
        }

        // 2. Create a NationalTeam row for every distinct (year, championship_name)
        //    tuple already in use. Uses "insert when missing" so re-runs
        //    don't duplicate.
        $tuples = DB::table('national_team_appearances')
            ->select('year', 'championship_name', 'host_country')
            ->whereNotNull('championship_name')
            ->distinct()
            ->get();

        foreach ($tuples as $tuple) {
            $exists = DB::table('national_teams')
                ->where('year', $tuple->year)
                ->where('championship_name', $tuple->championship_name)
                ->exists();

            if ($exists) {
                continue;
            }

            $discipline = str_contains(strtolower((string) $tuple->championship_name), 'rimfire')
                ? 'rimfire'
                : 'centrefire';

            DB::table('national_teams')->insert([
                'year' => $tuple->year,
                'championship_name' => $tuple->championship_name,
                'discipline' => $discipline,
                'host_country' => $tuple->host_country,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Link each appearance to its team. In PHP so it works on both
        //    MySQL and SQLite — JOIN-UPDATE syntax isn't portable.
        //    `update` is naturally idempotent.
        foreach (DB::table('national_teams')->get(['id', 'year', 'championship_name']) as $team) {
            DB::table('national_team_appearances')
                ->where('year', $team->year)
                ->where('championship_name', $team->championship_name)
                ->whereNull('national_team_id')
                ->update(['national_team_id' => $team->id]);
        }

        // 4. Promote structural roles from the free-text label.
        //
        //    IMPORTANT: regex uses the /u modifier so multi-byte dashes
        //    (em-dash U+2014, en-dash U+2013) are handled as single
        //    codepoints. Without /u the character class walks the bytes
        //    of the em-dash individually, which previously produced a
        //    half-eaten "Open \xE2\x80" write and crashed the migration
        //    on utf8mb3 columns.
        //
        //    We ALSO normalise the written value to ASCII (hyphen only)
        //    so no non-ASCII bytes ever get persisted — the column may
        //    not be utf8mb4.
        //
        //    `where('role', 'shooter')` restricts to the default so we
        //    don't re-process rows the admin has already corrected in-UI.
        $rows = DB::table('national_team_appearances')
            ->whereNotNull('division_label')
            ->where('role', 'shooter')
            ->get(['id', 'division_label']);

        foreach ($rows as $row) {
            $ascii = $this->normaliseToAscii((string) $row->division_label);
            $lower = strtolower(trim($ascii));

            if ($lower === 'team manager') {
                DB::table('national_team_appearances')
                    ->where('id', $row->id)
                    ->update(['role' => 'manager', 'division_label' => 'Team Manager']);

                continue;
            }

            if (preg_match('/\bTeam Captain\b/iu', $ascii)) {
                // Strip the "- Team Captain" suffix (or anything after it).
                // Split string is pure ASCII now so byte-walking is safe.
                $parts = preg_split('/\s*-\s*Team Captain.*$/iu', $ascii, 2);
                $base = trim($parts[0] ?? $ascii);

                DB::table('national_team_appearances')
                    ->where('id', $row->id)
                    ->update([
                        'role' => 'captain',
                        'division_label' => $base !== '' ? $base : null,
                    ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('national_team_appearances', 'national_team_id')) {
            Schema::table('national_team_appearances', function (Blueprint $table) {
                $table->dropForeign(['national_team_id']);
            });
        }

        if ($this->indexExists('national_team_appearances', 'national_team_appearances_national_team_id_role_index')) {
            Schema::table('national_team_appearances', function (Blueprint $table) {
                $table->dropIndex(['national_team_id', 'role']);
            });
        }

        Schema::table('national_team_appearances', function (Blueprint $table) {
            $cols = array_filter(
                ['role', 'national_team_id'],
                fn ($c) => Schema::hasColumn('national_team_appearances', $c),
            );
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }

    /**
     * Normalise common Unicode dashes to the ASCII hyphen. The target
     * column may be utf8mb3/latin1 — writing an em-dash can fail or
     * truncate. Doing this once up front keeps every downstream write
     * ASCII-only and the regex trivially correct.
     */
    private function normaliseToAscii(string $value): string
    {
        return str_replace(
            ["\u{2014}", "\u{2013}", "\u{2212}", "\u{00A0}"], // em, en, minus, nbsp
            ['-',         '-',        '-',        ' '],
            $value,
        );
    }

    /**
     * Portable "does this named index exist" check — works on MySQL /
     * MariaDB (information_schema) and no-ops on SQLite (which doesn't
     * expose the index by Laravel's generated name).
     */
    private function indexExists(string $table, string $index): bool
    {
        try {
            $connection = Schema::getConnection();
            $driver = $connection->getDriverName();

            if ($driver === 'sqlite') {
                $rows = $connection->select("PRAGMA index_list('{$table}')");
                foreach ($rows as $row) {
                    if (($row->name ?? null) === $index) {
                        return true;
                    }
                }

                return false;
            }

            $database = $connection->getDatabaseName();
            $rows = $connection->select(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$database, $table, $index],
            );

            return $rows !== [];
        } catch (\Throwable) {
            return false;
        }
    }
};
