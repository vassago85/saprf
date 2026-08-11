<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\ShooterLog;
use App\Models\User;
use App\Services\ScoreImportService;
use App\Services\StandingsCalculationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-off importer that extracts DAY 1 scores from two 2-day national result
 * exports and loads them as standalone PROVINCIAL PR22 matches:
 *
 *   - Darling Steel Valley (21–22 Feb 2026) — Western Cape
 *   - Clash of the Legends  (6–7 Jun 2026)  — Gauteng
 *
 * The full 2-day nationals are already loaded; this command only creates the
 * provincial day-1 events so those day-1 results feed the provincial standings.
 *
 * EVERY shooter who put in a day-1 score counts: shooters without an account get
 * a stub member account (mirroring the scraped historical importer), and every
 * resulting score is marked valid + counts_for_season so nobody is dropped for
 * membership state on the day. Re-run with --replace to rebuild an existing event.
 *
 * The two exports use different layouts:
 *   - Darling: long/per-stage rows. Day 1 = stages labelled "Stage: N ...";
 *     Day 2 = "D2 Stage: N ...". Per-shooter day-1 total = sum of impacts across
 *     the day-1 stages. Division is present per row.
 *   - Clash: wide pivot. One row per shooter with "Day 1 Stage 1..8",
 *     "Day 2 Stage 1..8" and a Grand Total. Per-shooter day-1 total = sum of the
 *     "Day 1 Stage n" columns. No division column (resolved from the shooter's
 *     account where possible).
 */
class ImportProvincialDay1Command extends Command
{
    protected $signature = 'saprf:import-provincial-day1
        {--darling=storage/app/imports/darling_steel_valley_pr22_scores_by_stage.csv : Path (relative to project root) to the Darling per-stage export}
        {--clash=storage/app/imports/clash_of_the_legends_scores_by_stage.csv : Path (relative to project root) to the Clash of the Legends pivot export}
        {--replace : If the provincial match already exists, delete its scores/imports and rebuild}
        {--dry-run : Parse and print per-shooter day-1 totals, but write nothing}';

    protected $description = 'Extract day-1 scores from the Darling & Clash 2-day exports and load them as provincial PR22 matches (all shooters count)';

    private int $memberSeq = 90001;

    public function handle(ScoreImportService $importer, StandingsCalculationService $standings): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');

        $events = [
            [
                'source' => base_path((string) $this->option('darling')),
                'format' => 'long',
                'name' => 'Darling Steel Valley PR22 Provincial (Day 1)',
                'province' => 'Western Cape',
                'venue_name' => 'Darling Steel Valley',
                'match_date' => '2026-02-21',
            ],
            [
                'source' => base_path((string) $this->option('clash')),
                'format' => 'wide',
                'name' => 'Clash of the Legends PR22 Provincial (Day 1)',
                'province' => 'Gauteng',
                'venue_name' => 'Legends Adventure Farm',
                'match_date' => '2026-06-06',
            ],
        ];

        $creator = User::whereHas('roles', fn ($q) => $q->where('name', 'developer'))->first()
            ?? User::first();
        if (! $creator) {
            $this->error('No user available to own the imported matches. Seed users first.');

            return self::FAILURE;
        }

        $divisionByUser = $this->divisionSlugByUserName();

        foreach ($events as $event) {
            $this->newLine();
            $this->info("=== {$event['name']} ===");

            if (! is_file($event['source'])) {
                $this->error("  Source file not found: {$event['source']}");

                return self::FAILURE;
            }

            $rows = $event['format'] === 'long'
                ? $this->parseLong($event['source'])
                : $this->parseWide($event['source'], $divisionByUser);

            if ($rows === []) {
                $this->warn('  No day-1 rows extracted — skipping.');

                continue;
            }

            $this->line('  '.count($rows).' shooter(s) with a day-1 score.');

            if ($dryRun) {
                foreach ($rows as $row) {
                    $this->line(sprintf('    %-32s %-10s %s', $row['shooter_name'], $row['division'] ?: '—', $row['raw_score']));
                }

                continue;
            }

            $province = Province::query()->whereRaw('LOWER(name) = ?', [strtolower($event['province'])])->first();
            if (! $province) {
                $this->error("  Unknown province: {$event['province']}");

                return self::FAILURE;
            }

            $existing = MatchEvent::query()
                ->where('match_type', 'PR22')
                ->where('season', '2026')
                ->where('name', $event['name'])
                ->whereDate('match_date', $event['match_date'])
                ->first();

            if ($existing) {
                if (! $replace) {
                    $this->warn("  Match already exists (id {$existing->id}) — skipping. Re-run with --replace to rebuild.");

                    continue;
                }
                $this->warn("  Match exists (id {$existing->id}) — replacing its scores.");
                $this->deleteMatch($existing);
            }

            $match = MatchEvent::create([
                'name' => $event['name'],
                'match_type' => 'PR22',
                'series' => 'PR22',
                'series_level' => 'provincial',
                'season' => '2026',
                'province_id' => $province->id,
                'venue_name' => $event['venue_name'],
                'match_date' => $event['match_date'],
                'status' => 'completed',
                'created_by' => $creator->id,
                'published' => true,
                'division_awards_enabled' => true,
                'description' => 'Day-1 provincial results extracted from the 2-day national export.',
            ]);

            $csvPath = $this->writeImporterCsv($match, $rows);

            $scoreImport = ScoreImport::create([
                'match_id' => $match->id,
                'uploaded_by' => $creator->id,
                'source_type' => 'csv',
                'day' => null,
                'original_filename' => basename($event['source']),
                'import_status' => 'pending',
            ]);

            $imported = $importer->importCsv($scoreImport, $csvPath);

            // Make every day-1 score count: backfill accounts for unmatched
            // shooters and mark all scores valid + counts_for_season, then rebuild.
            [$total, $stubs] = $this->ensureAllCounted($match, $province->id);
            $standings->recalculateForMatch($match);

            $this->info("  Created match id {$match->id}: imported {$imported} row(s); {$total} score(s) counting, {$stubs} stub account(s) created.");
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete — nothing written.' : 'Done.');

        return self::SUCCESS;
    }

    /**
     * Ensure every score on the match counts: give unmatched shooters a stub
     * member account, then force status=valid + counts_for_season on all rows.
     *
     * @return array{0:int,1:int} [total scores, stub accounts created]
     */
    private function ensureAllCounted(MatchEvent $match, int $provinceId): array
    {
        $stubs = 0;
        $scores = $match->scores()->get();

        foreach ($scores as $score) {
            if (! $score->user_id) {
                $score->user_id = $this->resolveOrCreateUser($score->shooter_name, $provinceId, $score->division_id, $stubs);
            }
            $score->status = 'valid';
            $score->is_member = true;
            $score->counts_for_season = true;
            $score->counts_for_log = true;
            $score->save();
        }

        return [$scores->count(), $stubs];
    }

    private function resolveOrCreateUser(string $name, int $provinceId, ?int $divisionId, int &$stubs): int
    {
        $canon = strtolower($this->cleanName($name));

        $exact = User::query()->whereRaw('LOWER(name) = ?', [$canon])->value('id');
        if ($exact) {
            return (int) $exact;
        }

        // Order-insensitive token match ("Mey Aliza" ↔ "Aliza Mey"), unique only.
        $tokens = collect(explode(' ', $canon))->filter()->sort()->values();
        if ($tokens->count() >= 2) {
            $key = $tokens->implode(' ');
            $candidates = User::query()->get(['id', 'name'])->filter(function (User $u) use ($key): bool {
                return collect(preg_split('/\s+/', Str::lower(trim((string) $u->name))))
                    ->filter()->sort()->values()->implode(' ') === $key;
            });
            if ($candidates->count() === 1) {
                return (int) $candidates->first()->id;
            }
        }

        $stubs++;

        return $this->createStub($name, $provinceId, $divisionId);
    }

    private function createStub(string $name, int $provinceId, ?int $divisionId): int
    {
        $parts = preg_split('/\s+/', $this->cleanName($name));
        $first = Str::slug($parts[0] ?? 'shooter') ?: 'shooter';
        $last = count($parts) > 1 ? Str::slug((string) end($parts)) : '';
        $base = $first.($last ? '.'.$last : '');
        $email = $base.'@import.saprf.local';
        $n = 1;
        while (User::where('email', $email)->exists()) {
            $email = $base.(++$n).'@import.saprf.local';
        }

        $user = User::create([
            'name' => $this->cleanName($name),
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'province_id' => $provinceId,
            'division_id' => $divisionId,
            'is_active' => true,
            'email_verified_at' => null,
            'must_change_password' => false,
        ]);
        $user->assignRole('member');

        $saprf = $this->nextSaprfNumber();
        Membership::firstOrCreate(
            ['user_id' => $user->id],
            [
                'saprf_number' => $saprf,
                'membership_type' => 'paid',
                'status' => 'active',
                'payment_status' => 'waived',
                'start_date' => '2026-01-01',
                'expiry_date' => '2026-12-31',
            ],
        );

        return $user->id;
    }

    private function nextSaprfNumber(): string
    {
        do {
            $number = 'SAPRF-2026-'.str_pad((string) $this->memberSeq++, 5, '0', STR_PAD_LEFT);
        } while (Membership::where('saprf_number', $number)->exists());

        return $number;
    }

    private function deleteMatch(MatchEvent $match): void
    {
        $scoreIds = Score::where('match_id', $match->id)->pluck('id');
        ShooterLog::whereIn('score_id', $scoreIds)->delete();
        ShooterLog::where('match_id', $match->id)->delete();
        Score::where('match_id', $match->id)->delete();
        ScoreImport::where('match_id', $match->id)->delete();
        $match->delete();
    }

    /**
     * Parse the long/per-stage export (Darling). Day 1 = rows whose stage label
     * starts with "Stage:"; Day 2 rows start with "D2 Stage:" and are ignored.
     *
     * @return array<int, array{shooter_name:string, division:?string, raw_score:string}>
     */
    private function parseLong(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return [];
        }

        $headers = $this->readHeaders($handle);
        $idx = array_flip($headers);
        $totals = [];

        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) === 1 && trim((string) $line[0]) === '') {
                continue;
            }

            $stage = strtolower(trim((string) ($line[$idx['stage']] ?? '')));
            if ($stage === '' || Str::startsWith($stage, 'd2')) {
                continue;
            }

            $name = $this->cleanName((string) ($line[$idx['name']] ?? ''));
            if ($name === '') {
                continue;
            }

            $impacts = trim((string) ($line[$idx['impacts']] ?? ''));
            $impacts = is_numeric($impacts) ? (float) $impacts : 0.0;

            $division = isset($idx['division']) ? trim((string) ($line[$idx['division']] ?? '')) : '';

            $canon = strtolower($name);
            if (! isset($totals[$canon])) {
                $totals[$canon] = ['shooter_name' => $name, 'division' => $division ?: null, 'raw_score' => 0.0];
            }
            $totals[$canon]['raw_score'] += $impacts;
            if (($totals[$canon]['division'] === null || $totals[$canon]['division'] === '') && $division !== '') {
                $totals[$canon]['division'] = $division;
            }
        }

        fclose($handle);

        return $this->finalizeRows($totals);
    }

    /**
     * Parse the wide pivot export (Clash). Day-1 total = sum of the
     * "Day 1 Stage n" columns per shooter row.
     *
     * @param  array<string, string>  $divisionByUser  lower(name) => division slug
     * @return array<int, array{shooter_name:string, division:?string, raw_score:string}>
     */
    private function parseWide(string $path, array $divisionByUser): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return [];
        }

        $header = null;
        while (($line = fgetcsv($handle)) !== false) {
            $lower = array_map(fn ($c) => strtolower(trim((string) $c)), $line);
            if (in_array('day 1 stage 1', $lower, true)) {
                $header = $lower;
                break;
            }
        }

        if ($header === null) {
            fclose($handle);
            $this->warn('  Could not locate the "Day 1 Stage 1" header row.');

            return [];
        }

        $day1Cols = [];
        foreach ($header as $i => $label) {
            if (preg_match('/^day 1 stage \d+$/', $label)) {
                $day1Cols[] = $i;
            }
        }

        $totals = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) === 1 && trim((string) $line[0]) === '') {
                continue;
            }

            $name = $this->cleanName((string) ($line[0] ?? ''));
            $lowerName = strtolower($name);
            if ($name === '' || in_array($lowerName, ['row labels', 'grand total'], true)) {
                continue;
            }

            $sum = 0.0;
            foreach ($day1Cols as $col) {
                $val = trim((string) ($line[$col] ?? ''));
                if (is_numeric($val)) {
                    $sum += (float) $val;
                }
            }

            $totals[$lowerName] = [
                'shooter_name' => $name,
                'division' => $divisionByUser[$lowerName] ?? null,
                'raw_score' => $sum,
            ];
        }

        fclose($handle);

        return $this->finalizeRows($totals);
    }

    /**
     * Drop shooters whose day-1 total is 0 (did-not-participate on day 1) and
     * cast totals to plain strings for the importer CSV.
     *
     * @param  array<string, array{shooter_name:string, division:?string, raw_score:float}>  $totals
     * @return array<int, array{shooter_name:string, division:?string, raw_score:string}>
     */
    private function finalizeRows(array $totals): array
    {
        $rows = [];
        foreach ($totals as $entry) {
            if ($entry['raw_score'] <= 0) {
                continue;
            }
            $rows[] = [
                'shooter_name' => $entry['shooter_name'],
                'division' => $entry['division'],
                'raw_score' => (string) (0 + $entry['raw_score']),
            ];
        }

        usort($rows, fn ($a, $b) => (float) $b['raw_score'] <=> (float) $a['raw_score']);

        return $rows;
    }

    /**
     * Write the extracted rows to a flat importer-format CSV and return its path.
     *
     * @param  array<int, array{shooter_name:string, division:?string, raw_score:string}>  $rows
     */
    private function writeImporterCsv(MatchEvent $match, array $rows): string
    {
        $dir = storage_path('app/score-imports');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.DIRECTORY_SEPARATOR.'provincial-day1-'.$match->id.'-'.Str::slug($match->name).'.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, ['shooter_name', 'division', 'raw_score']);
        foreach ($rows as $row) {
            fputcsv($handle, [$row['shooter_name'], $row['division'] ?? '', $row['raw_score']]);
        }
        fclose($handle);

        return $path;
    }

    /**
     * Map of lower(name) => division slug for every user that has a division,
     * used to backfill divisions for the Clash export (which has no division column).
     *
     * @return array<string, string>
     */
    private function divisionSlugByUserName(): array
    {
        return User::query()
            ->whereNotNull('division_id')
            ->with('division:id,slug')
            ->get(['id', 'name', 'division_id'])
            ->reduce(function (array $carry, User $user): array {
                $slug = $user->division?->slug;
                if ($slug) {
                    $carry[strtolower($this->cleanName($user->name))] = $slug;
                }

                return $carry;
            }, []);
    }

    /**
     * @param  resource  $handle
     * @return array<int, string>
     */
    private function readHeaders($handle): array
    {
        $bom = pack('H*', 'EFBBBF');
        $first = fread($handle, 3);
        if ($first !== $bom) {
            rewind($handle);
        }

        $headers = fgetcsv($handle) ?: [];

        return array_map(fn ($h) => strtolower(trim((string) $h)), $headers);
    }

    private function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name));
    }
}
