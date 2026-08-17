<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Score;
use App\Models\User;
use App\Services\StandingsCalculationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Apply a per-stage pivot-style score correction to an existing MatchEvent.
 *
 * The Practiscore results we normally import are one row per shooter with a
 * pre-summed Impacts column. When a match director spots an error after the
 * fact and re-runs the stats from scoring hardware, what they send is a
 * pivot table with one column per stage (Day 1 Stage 1..N, Day 2 Stage 1..M)
 * and a Grand Total. This command consumes that shape and re-writes each
 * shooter's Score row so their total matches the CSV.
 *
 * Expected CSV shape (header + data rows; blank leading row optional):
 *
 *     Row Labels,Day 1 Stage 1,Day 1 Stage 2,...,Day 2 Stage 1,...,Grand Total
 *     Andries Lategan,8,12,8,9,10,9,11,11,9,87
 *
 * How columns map to Score fields:
 *
 *     day1_raw_score = SUM of every "Day 1 Stage N" column
 *     day2_raw_score = SUM of every "Day 2 Stage N" column
 *     raw_score      = day1 + day2 (recomputed by Score::booted() observer)
 *
 * Non-numeric columns and the Grand Total column are ignored (the total is
 * only used as a sanity check against the summed per-stage impacts).
 *
 * Safety:
 *   - Idempotent: re-running with the same CSV rewrites to the same values.
 *   - Dry-run by default when --dry-run is passed; nothing is written.
 *   - Wraps the batch in a DB transaction so a mid-batch failure rolls back.
 *   - Writes a single `score_correction_batch` AuditLog with the diff summary.
 *   - Shooters not found by name are warned-and-skipped (fuzzy fallback:
 *     case-insensitive equality first, then ASCII-folded equality so the
 *     UTF-8-mangled "LinÃ©" matches the real "Liné").
 *   - When an existing Score row is missing for a matched user, the row is
 *     only created with --create-missing (and needs --division for the slug).
 *   - After a successful write, rankings + season standings are recalculated
 *     unless --skip-standings is passed.
 */
class ApplyStagePivotScoresCommand extends Command
{
    protected $signature = 'scores:apply-stage-pivot
        {match : ID of the target MatchEvent}
        {csv : Path to the pivot CSV (absolute, or relative to base_path)}
        {--dry-run : Report what would change without writing}
        {--create-missing : Also create Score rows for shooters with no existing row for this match}
        {--division= : Division slug to use when creating missing rows (required with --create-missing)}
        {--skip-standings : Skip StandingsCalculationService::recalculateForMatch after writing}';

    protected $description = 'Update Score rows on an existing match from a per-stage pivot CSV';

    public function handle(StandingsCalculationService $standings): int
    {
        $matchId = (int) $this->argument('match');
        $csvArg = (string) $this->argument('csv');
        $csvPath = $this->resolveCsvPath($csvArg);
        $dryRun = (bool) $this->option('dry-run');
        $createMissing = (bool) $this->option('create-missing');
        $divisionSlug = $this->option('division') ? strtolower((string) $this->option('division')) : null;
        $skipStandings = (bool) $this->option('skip-standings');

        if (! is_file($csvPath)) {
            $this->error("CSV not found: {$csvPath}");
            return self::FAILURE;
        }

        /** @var MatchEvent|null $match */
        $match = MatchEvent::find($matchId);
        if (! $match) {
            $this->error("MatchEvent #{$matchId} not found.");
            return self::FAILURE;
        }

        [$headers, $rows] = $this->readCsv($csvPath);
        if ($rows === []) {
            $this->error('CSV parsed to zero data rows. Aborting.');
            return self::FAILURE;
        }

        [$day1Cols, $day2Cols, $totalCol, $unknownCols] = $this->classifyColumns($headers);
        if ($day1Cols === [] && $day2Cols === []) {
            $this->error('CSV has no columns matching "Day 1 Stage N" or "Day 2 Stage N". Aborting.');
            return self::FAILURE;
        }

        $creationDivision = null;
        if ($createMissing) {
            if ($divisionSlug === null || $divisionSlug === '') {
                $this->error('--create-missing needs --division=<slug> so new Score rows have a division.');
                return self::FAILURE;
            }
            $creationDivision = Division::whereRaw('LOWER(slug) = ?', [$divisionSlug])->first();
            if (! $creationDivision) {
                $this->error("Division slug '{$divisionSlug}' does not exist.");
                return self::FAILURE;
            }
        }

        $this->info('=== Stage-pivot score correction ===');
        $this->line("Match:        #{$match->id}  {$match->name}");
        $this->line("Date:         {$match->match_date->toDateString()}   Series: {$match->series} / {$match->series_level}");
        $this->line("CSV:          {$csvPath}");
        $this->line('Day 1 stages: '.count($day1Cols).' ('.implode(', ', $day1Cols).')');
        $this->line('Day 2 stages: '.count($day2Cols).' ('.implode(', ', $day2Cols).')');
        if ($totalCol !== null) {
            $this->line("Total column: {$totalCol}");
        }
        if ($unknownCols !== []) {
            $this->warn('Ignored columns (not a Day/Stage header): '.implode(', ', $unknownCols));
        }
        $this->line('Mode:         '.($dryRun ? 'DRY RUN (no writes)' : 'WRITE'));
        $this->line('Missing rows: '.($createMissing ? "create with division='{$divisionSlug}'" : 'warn and skip'));
        $this->line('Rows read:    '.count($rows));
        $this->newLine();

        $plan = $this->buildPlan($rows, $headers, $day1Cols, $day2Cols, $totalCol, $match);

        $this->renderPlan($plan);

        if ($plan['errors'] !== []) {
            $this->error('Aborting because '.count($plan['errors']).' row(s) had irrecoverable errors above.');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('[dry-run] Would update '.count($plan['updates']).' score(s) and '
                .($createMissing ? 'create '.count($plan['missing']).' new score(s).' : 'skip '.count($plan['missing']).' shooter(s) without an existing Score row.'));
            return self::SUCCESS;
        }

        $applied = DB::transaction(function () use ($plan, $match, $createMissing, $creationDivision) {
            $applied = ['updated' => 0, 'created' => 0, 'skipped_no_change' => 0];

            foreach ($plan['updates'] as $u) {
                $score = Score::find($u['score_id']);
                if (! $score) {
                    continue;
                }
                if ((float) $score->day1_raw_score === (float) $u['day1']
                    && (float) $score->day2_raw_score === (float) $u['day2']) {
                    $applied['skipped_no_change']++;
                    continue;
                }
                $score->day1_raw_score = $u['day1'];
                $score->day2_raw_score = $u['day2'];
                $score->raw_meta = array_merge((array) $score->raw_meta, [
                    'stage_pivot_correction' => [
                        'applied_at' => now()->toIso8601String(),
                        'day1_stages' => $u['day1_stages'],
                        'day2_stages' => $u['day2_stages'],
                        'csv_grand_total' => $u['csv_total'],
                    ],
                ]);
                $score->save();
                $applied['updated']++;
            }

            if ($createMissing && $creationDivision) {
                foreach ($plan['missing'] as $m) {
                    Score::create([
                        'match_id' => $match->id,
                        'user_id' => $m['user_id'],
                        'shooter_name' => $m['name'],
                        'division_id' => $creationDivision->id,
                        'day1_raw_score' => $m['day1'],
                        'day2_raw_score' => $m['day2'],
                        'status' => 'valid',
                        'is_member' => true,
                        'match_date' => $match->match_date,
                        'counts_for_log' => true,
                        'counts_for_season' => true,
                        'raw_meta' => [
                            'source' => 'stage_pivot_correction',
                            'applied_at' => now()->toIso8601String(),
                            'day1_stages' => $m['day1_stages'],
                            'day2_stages' => $m['day2_stages'],
                            'csv_grand_total' => $m['csv_total'],
                        ],
                    ]);
                    $applied['created']++;
                }
            }

            AuditLog::create([
                'user_id' => null,
                'actor_type' => 'system',
                'action_type' => 'score_correction_batch',
                'entity_type' => 'MatchEvent',
                'entity_id' => $match->id,
                'old_value' => null,
                'new_value' => [
                    'source' => 'stage_pivot_csv',
                    'updated' => $applied['updated'],
                    'created' => $applied['created'],
                    'skipped_no_change' => $applied['skipped_no_change'],
                    'unmatched_names' => collect($plan['unmatched'])->pluck('name')->all(),
                    'missing_rows' => $createMissing ? [] : collect($plan['missing'])->pluck('name')->all(),
                ],
                'reason' => "Stage-pivot score correction on match #{$match->id}",
            ]);

            return $applied;
        });

        $this->newLine();
        $this->info("Updated: {$applied['updated']}   Created: {$applied['created']}   No-change: {$applied['skipped_no_change']}");

        if (! $skipStandings) {
            $this->info('Recalculating rankings + season standings...');
            $standings->recalculateForMatch($match->fresh());
        }

        return self::SUCCESS;
    }

    private function resolveCsvPath(string $csvArg): string
    {
        if (is_file($csvArg)) {
            return $csvArg;
        }
        $candidate = base_path($csvArg);

        return $candidate;
    }

    /**
     * Read a CSV, skipping a leading pivot-header row like "Sum of Impacts,Column Labels,,,"
     * that Excel emits above the real header. Returns [$headers, $rows] where
     * $rows is a list of associative arrays keyed by the trimmed header.
     *
     * @return array{0: list<string>, 1: list<array<string,string>>}
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return [[], []];
        }

        // Strip UTF-8 BOM if present so the first header cell doesn't come
        // back as "\u{FEFF}Row Labels" and blow up the header lookup.
        $bom = pack('H*', 'EFBBBF');
        $first = fread($handle, 3);
        if ($first !== $bom) {
            rewind($handle);
        }

        $rows = [];
        $headers = [];

        while (($row = fgetcsv($handle)) !== false) {
            $trimmed = array_map(fn ($c) => trim((string) $c), $row);
            if (implode('', $trimmed) === '') {
                continue;
            }

            // A pivot header row like "Sum of Impacts,Column Labels,,,,..." —
            // first cell is a metric name, most other cells are blank.
            if ($headers === [] && $this->looksLikePivotMetadataRow($trimmed)) {
                continue;
            }

            if ($headers === []) {
                $headers = $trimmed;

                continue;
            }

            $assoc = [];
            foreach ($headers as $i => $h) {
                if ($h === '') {
                    continue;
                }
                $assoc[$h] = $trimmed[$i] ?? '';
            }
            $rows[] = $assoc;
        }
        fclose($handle);

        return [$headers, $rows];
    }

    /**
     * @param  list<string>  $row
     */
    private function looksLikePivotMetadataRow(array $row): bool
    {
        if (count($row) < 2) {
            return false;
        }
        $blankCount = 0;
        foreach ($row as $cell) {
            if ($cell === '') {
                $blankCount++;
            }
        }
        // Excel pivot metadata row: one label + one label + many blanks. If
        // more than half the cells are blank and the first cell isn't a
        // typical name header, treat as metadata.
        $firstLooksLikeMetric = stripos($row[0], 'sum of') === 0 || stripos($row[0], 'count of') === 0;

        return $firstLooksLikeMetric && $blankCount >= (count($row) / 2);
    }

    /**
     * Split the header row into Day 1 stages, Day 2 stages, and the Grand
     * Total column. Anything else is reported as "unknown" so the operator
     * spots typos before they lose data.
     *
     * @param  list<string>  $headers
     * @return array{0: list<string>, 1: list<string>, 2: string|null, 3: list<string>}
     */
    private function classifyColumns(array $headers): array
    {
        $day1 = [];
        $day2 = [];
        $total = null;
        $unknown = [];

        foreach ($headers as $h) {
            if ($h === '') {
                continue;
            }
            $lower = strtolower($h);
            if ($lower === 'row labels' || $lower === 'name' || $lower === 'shooter' || $lower === 'competitor') {
                continue;
            }
            if (preg_match('/^day\s*1\s*stage\s*\d+/i', $h)) {
                $day1[] = $h;

                continue;
            }
            if (preg_match('/^day\s*2\s*stage\s*\d+/i', $h)) {
                $day2[] = $h;

                continue;
            }
            if (preg_match('/grand\s*total/i', $h) || strtolower($h) === 'total') {
                $total = $h;

                continue;
            }
            $unknown[] = $h;
        }

        return [$day1, $day2, $total, $unknown];
    }

    /**
     * Build the write plan before any DB mutation. Reports:
     *   - updates: rows where a Score exists and needs new day1/day2 values
     *   - missing: rows where the user exists but has no Score for this match
     *   - unmatched: CSV names that don't match a User at all
     *   - warnings: sums-don't-match-grand-total, duplicate CSV row for one user
     *   - errors: irrecoverable (non-numeric stage value)
     *
     * @param  list<array<string,string>>  $rows
     * @param  list<string>  $headers
     * @param  list<string>  $day1Cols
     * @param  list<string>  $day2Cols
     * @return array{
     *   updates: list<array<string,mixed>>,
     *   missing: list<array<string,mixed>>,
     *   unmatched: list<array<string,mixed>>,
     *   warnings: list<string>,
     *   errors: list<string>
     * }
     */
    private function buildPlan(array $rows, array $headers, array $day1Cols, array $day2Cols, ?string $totalCol, MatchEvent $match): array
    {
        $updates = [];
        $missing = [];
        $unmatched = [];
        $warnings = [];
        $errors = [];
        $seenUserIds = [];

        $nameHeader = $this->detectNameHeader($headers);
        if ($nameHeader === null) {
            $errors[] = 'Could not find a shooter name column (looked for: Row Labels, Name, Shooter, Competitor).';

            return compact('updates', 'missing', 'unmatched', 'warnings', 'errors');
        }

        foreach ($rows as $rowIndex => $row) {
            $rawName = trim((string) ($row[$nameHeader] ?? ''));
            if ($rawName === '') {
                continue;
            }
            $name = $this->cleanName($rawName);

            $day1Stages = $this->collectStageValues($row, $day1Cols);
            $day2Stages = $this->collectStageValues($row, $day2Cols);

            $day1Sum = array_sum(array_map(fn ($v) => (float) $v, $day1Stages));
            $day2Sum = array_sum(array_map(fn ($v) => (float) $v, $day2Stages));

            $csvTotal = null;
            if ($totalCol !== null) {
                $raw = trim((string) ($row[$totalCol] ?? ''));
                if ($raw !== '' && is_numeric($raw)) {
                    $csvTotal = (float) $raw;
                    if (abs(($day1Sum + $day2Sum) - $csvTotal) > 0.0001) {
                        $warnings[] = "{$name}: per-stage sum (".($day1Sum + $day2Sum).") does not equal Grand Total ({$csvTotal}) — check the CSV";
                    }
                }
            }

            foreach ([...$day1Cols, ...$day2Cols] as $col) {
                $raw = trim((string) ($row[$col] ?? ''));
                if ($raw !== '' && ! is_numeric($raw)) {
                    $errors[] = "{$name} / {$col}: '{$raw}' is not numeric";
                }
            }

            $user = $this->findUser($name);
            if (! $user) {
                $unmatched[] = ['name' => $name];

                continue;
            }

            if (isset($seenUserIds[$user->id])) {
                $warnings[] = "{$name}: duplicate CSV row for the same user (id {$user->id}) — only the last wins";
            }
            $seenUserIds[$user->id] = true;

            $score = Score::where('match_id', $match->id)->where('user_id', $user->id)->first();

            if (! $score) {
                $missing[] = [
                    'name' => $name,
                    'user_id' => $user->id,
                    'day1' => $day1Sum,
                    'day2' => $day2Sum,
                    'day1_stages' => $day1Stages,
                    'day2_stages' => $day2Stages,
                    'csv_total' => $csvTotal,
                ];

                continue;
            }

            $updates[] = [
                'score_id' => $score->id,
                'user_id' => $user->id,
                'name' => $name,
                'was_day1' => (float) ($score->day1_raw_score ?? 0),
                'was_day2' => (float) ($score->day2_raw_score ?? 0),
                'was_total' => (float) ($score->raw_score ?? 0),
                'day1' => $day1Sum,
                'day2' => $day2Sum,
                'day1_stages' => $day1Stages,
                'day2_stages' => $day2Stages,
                'csv_total' => $csvTotal,
            ];
        }

        return compact('updates', 'missing', 'unmatched', 'warnings', 'errors');
    }

    private function detectNameHeader(array $headers): ?string
    {
        foreach ($headers as $h) {
            $lower = strtolower(trim($h));
            if (in_array($lower, ['row labels', 'name', 'shooter', 'competitor'], true)) {
                return $h;
            }
        }

        return null;
    }

    /**
     * @param  array<string,string>  $row
     * @param  list<string>  $cols
     * @return array<string,float>
     */
    private function collectStageValues(array $row, array $cols): array
    {
        $out = [];
        foreach ($cols as $col) {
            $raw = trim((string) ($row[$col] ?? ''));
            $out[$col] = ($raw !== '' && is_numeric($raw)) ? (float) $raw : 0.0;
        }

        return $out;
    }

    /**
     * Match a CSV name to a User row with progressively looser strategies:
     *  1. Exact case-insensitive name match.
     *  2. Canonicalised match — strip every non-ASCII byte and collapse
     *     whitespace on both sides, then compare. Catches Excel exports
     *     that came back through Latin-1 and left "LinÃ©" mojibake in
     *     the CSV: canonicalising both "LinÃ© de Witt" and "Liné de Witt"
     *     drops the mangled multi-byte sequence and returns "lin de witt",
     *     which matches. Transliteration via Str::ascii() would map "Ã"
     *     to "A" and diverge, so we deliberately strip rather than fold.
     */
    private function findUser(string $name): ?User
    {
        $lower = strtolower($name);
        $user = User::whereRaw('LOWER(name) = ?', [$lower])->first();
        if ($user) {
            return $user;
        }

        $canonical = $this->canonicalName($name);
        if ($canonical === '') {
            return null;
        }

        // Scope candidates by a plain-ASCII prefix so we don't pull every
        // User row on a big table. Three letters is enough to keep the
        // shortlist tiny while still catching diacritics on the first name.
        $prefix = substr($canonical, 0, 3);
        if (strlen($prefix) < 3) {
            return null;
        }
        $candidates = User::whereRaw('LOWER(name) LIKE ?', [$prefix.'%'])->get();
        foreach ($candidates as $c) {
            if ($this->canonicalName($c->name) === $canonical) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Byte-level normalization for name matching. Strips everything except
     * lowercase ASCII letters and spaces, then collapses whitespace. Not a
     * transliteration — we're deliberately dropping mangled multi-byte
     * sequences ("Ã©") instead of trying to guess their intent.
     */
    private function canonicalName(string $name): string
    {
        $lower = strtolower($name);
        $stripped = preg_replace('/[^a-z ]+/', '', $lower) ?? '';

        return trim((string) preg_replace('/\s+/', ' ', $stripped));
    }

    private function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /**
     * @param  array{updates: list<array<string,mixed>>, missing: list<array<string,mixed>>, unmatched: list<array<string,mixed>>, warnings: list<string>, errors: list<string>}  $plan
     */
    private function renderPlan(array $plan): void
    {
        if ($plan['errors'] !== []) {
            $this->error('Errors:');
            foreach ($plan['errors'] as $e) {
                $this->line("  ✗ {$e}");
            }
            $this->newLine();
        }

        if ($plan['warnings'] !== []) {
            $this->warn('Warnings:');
            foreach ($plan['warnings'] as $w) {
                $this->line("  ! {$w}");
            }
            $this->newLine();
        }

        if ($plan['unmatched'] !== []) {
            $this->warn('Unmatched names (no matching User in DB — will be skipped):');
            foreach ($plan['unmatched'] as $u) {
                $this->line("  - {$u['name']}");
            }
            $this->newLine();
        }

        if ($plan['missing'] !== []) {
            $this->warn('Users with NO existing Score row on this match (use --create-missing to add):');
            foreach ($plan['missing'] as $m) {
                $this->line("  + {$m['name']}: day1={$m['day1']}  day2={$m['day2']}  total=".($m['day1'] + $m['day2']));
            }
            $this->newLine();
        }

        if ($plan['updates'] !== []) {
            $this->info('Score changes (existing rows):');
            foreach ($plan['updates'] as $u) {
                $newTotal = $u['day1'] + $u['day2'];
                $delta = $newTotal - $u['was_total'];
                $sign = $delta > 0 ? '+' : ($delta < 0 ? '' : ' ');
                if (abs($delta) < 0.0001 && abs($u['day1'] - $u['was_day1']) < 0.0001 && abs($u['day2'] - $u['was_day2']) < 0.0001) {
                    $this->line("  = {$u['name']}: unchanged (day1={$u['day1']}, day2={$u['day2']}, total={$newTotal})");

                    continue;
                }
                $this->line(sprintf(
                    '  ~ %-35s day1: %5.1f -> %5.1f    day2: %5.1f -> %5.1f    total: %5.1f -> %5.1f  (%s%.1f)',
                    $u['name'],
                    $u['was_day1'],
                    $u['day1'],
                    $u['was_day2'],
                    $u['day2'],
                    $u['was_total'],
                    $newTotal,
                    $sign,
                    $delta
                ));
            }
        }
    }
}
