<?php

namespace App\Console\Commands;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\StandingsCalculationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-shot importer that turns the CSVs produced by scripts/scrape_pr22.php
 * (in storage/scraped/pr22/) into real MatchEvent, User, Membership and
 * Score records, then runs the season standings recalculation.
 *
 * Defaults to WIPING non-staff shooter data first so the imported dataset
 * replaces any fabricated demo shooters. Staff accounts on @saprf.co.za
 * are preserved.
 */
class ImportScrapedPr22Command extends Command
{
    protected $signature = 'pr22:import-scraped
        {--dir=storage/scraped/pr22 : Directory containing matches.csv and per-match CSVs}
        {--skip-source-ids=250 : Comma-separated source event IDs to skip (defaults to WC provincial mirror)}
        {--dry-run : Parse everything and print counts, but write nothing}
        {--no-wipe : Skip the wipe step (import additively on top of existing data)}';

    protected $description = 'Import scraped SAPRF PR22 2026 match results (scores + stub users) into the DB';

    public function handle(StandingsCalculationService $standings): int
    {
        $dir = base_path((string) $this->option('dir'));
        $catalog = $dir.DIRECTORY_SEPARATOR.'matches.csv';

        if (!is_file($catalog)) {
            $this->error("matches.csv not found at {$catalog}. Run scripts/scrape_pr22.php first.");
            return self::FAILURE;
        }

        $skipIds = array_filter(array_map('trim', explode(',', (string) $this->option('skip-source-ids'))));
        $dryRun = (bool) $this->option('dry-run');
        $doWipe = !$this->option('no-wipe');

        $this->info('=== SAPRF PR22 2026 Scraped Import ===');
        $this->line("Source dir: {$dir}");
        $this->line('Skip source IDs: '.implode(', ', $skipIds ?: ['<none>']));
        $this->line('Wipe first: '.($doWipe ? 'yes' : 'no'));
        $this->line('Dry run: '.($dryRun ? 'YES (nothing will be written)' : 'no'));
        $this->newLine();

        $matches = $this->readCsv($catalog);
        $matches = array_values(array_filter($matches, fn ($m) => !in_array($m['source_id'], $skipIds, true)));

        $this->info(count($matches).' match(es) after skip filter.');

        $roster = $this->buildRoster($dir, $matches);
        $this->info(count($roster).' unique shooters across all matches.');

        $provinces = Province::all()->keyBy(fn ($p) => strtolower($p->name));
        $missingProv = collect($matches)->pluck('province')->unique()
            ->reject(fn ($p) => $provinces->has(strtolower($p)));
        if ($missingProv->isNotEmpty()) {
            $this->error('Unknown province name(s) on matches: '.$missingProv->implode(', '));
            return self::FAILURE;
        }

        $rosterMissingProv = collect($roster)->filter(fn ($r) => !$provinces->has(strtolower($r['province'])));
        if ($rosterMissingProv->isNotEmpty()) {
            $this->error('Unknown province(s) on shooters: '.$rosterMissingProv->pluck('province')->unique()->implode(', '));
            return self::FAILURE;
        }

        $divisions = Division::all()->keyBy(fn ($d) => strtolower($d->slug));
        $divisionsByName = Division::all()->keyBy(fn ($d) => strtolower($d->name));

        if ($dryRun) {
            $this->line('[dry-run] Would wipe: '.($doWipe ? 'yes' : 'no'));
            $this->line('[dry-run] Would create '.count($roster).' users + memberships');
            $this->line('[dry-run] Would create '.count($matches).' matches');
            $totalRows = 0;
            foreach ($matches as $m) {
                $totalRows += count($this->readCsv(base_path($m['scores_csv'])));
            }
            $this->line('[dry-run] Would create '.$totalRows.' score rows');
            return self::SUCCESS;
        }

        if ($doWipe) {
            $this->wipeNonStaffData();
        }

        DB::transaction(function () use ($roster, $matches, $provinces, $divisions, $divisionsByName) {
            $creator = User::whereHas('roles', fn ($q) => $q->where('name', 'developer'))->first()
                ?? User::first();
            if (!$creator) {
                throw new \RuntimeException('No user available to own imported matches. Seed RolesAndUsersSeeder first.');
            }

            $this->info('Creating '.count($roster).' shooter accounts + memberships...');
            $userIdByCanon = $this->createStubShooters($roster, $provinces);
            $this->line('  '.count($userIdByCanon).' users ready.');

            $this->info('Creating '.count($matches).' matches + scores...');
            $totalScores = 0;
            foreach ($matches as $m) {
                $match = $this->createMatch($m, $provinces, $creator->id);
                $rows = $this->readCsv(base_path($m['scores_csv']));
                $totalScores += $this->createScores($match, $rows, $userIdByCanon, $divisions, $divisionsByName);
            }
            $this->line("  {$totalScores} score rows imported.");
        });

        $this->info('Recalculating match rankings + season standings...');
        MatchEvent::where('match_type', 'pr22')->where('season', '2026')
            ->orderBy('match_date')
            ->get()
            ->each(function ($match) use ($standings) {
                $standings->calculateMatchRankings($match);
                $standings->calculateProvincialNormalizedScores($match);
            });

        $standings->recalculateSeasonStandings('pr22', '2026', null);
        foreach (Province::all() as $prov) {
            $standings->recalculateSeasonStandings('pr22', '2026', $prov->id);
        }

        $this->printSummary();
        $this->info('Done.');
        return self::SUCCESS;
    }

    private function wipeNonStaffData(): void
    {
        $this->warn('Wiping non-staff shooter data...');

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        } elseif ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            foreach ([
                'shooter_logs', 'score_imports', 'match_registrations', 'match_expenses',
                'match_division', 'payouts', 'membership_payments', 'payments',
                'rifle_configurations', 'ammo_loads', 'provincial_committees',
                'audit_logs', 'user_qualification_progress',
            ] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            Score::query()->delete();
            Standing::query()->delete();
            MatchEvent::query()->delete();

            $preservedIds = User::query()
                ->where('email', 'like', '%@saprf.co.za')
                ->pluck('id');

            Membership::query()->whereNotIn('user_id', $preservedIds)->delete();

            User::query()
                ->whereNotIn('id', $preservedIds)
                ->get()
                ->each(fn ($u) => $u->forceDelete());

            $this->line('  Preserved '.$preservedIds->count().' staff user(s).');
        } finally {
            if ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            } elseif ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }

    /**
     * @return array<int, array{name:string,canon:string,provinces:array<string,int>,divisions:array<string,int>,province:string}>
     */
    private function buildRoster(string $dir, array $matches): array
    {
        $roster = [];
        foreach ($matches as $m) {
            $csvPath = base_path($m['scores_csv']);
            if (!is_file($csvPath)) {
                $this->warn("  score CSV missing: {$csvPath}");
                continue;
            }
            $rows = $this->readCsv($csvPath);
            foreach ($rows as $row) {
                $name = $this->cleanName($row['shooter_name'] ?? '');
                if ($name === '') continue;
                $canon = strtolower($name);
                if (!isset($roster[$canon])) {
                    $roster[$canon] = [
                        'name' => $name,
                        'canon' => $canon,
                        'provinces' => [],
                        'divisions' => [],
                    ];
                }
                $prov = $m['province'];
                $div = strtolower(trim($row['division'] ?? ''));
                $roster[$canon]['provinces'][$prov] = ($roster[$canon]['provinces'][$prov] ?? 0) + 1;
                if ($div !== '') {
                    $roster[$canon]['divisions'][$div] = ($roster[$canon]['divisions'][$div] ?? 0) + 1;
                }
            }
        }

        foreach ($roster as &$entry) {
            arsort($entry['provinces']);
            $entry['province'] = array_key_first($entry['provinces']);
        }
        unset($entry);

        return array_values($roster);
    }

    /**
     * @return array<string, int> canonical-name => user_id
     */
    private function createStubShooters(array $roster, $provinces): array
    {
        $userIdByCanon = [];
        $memberNumberSeq = 10001;
        $emailSeq = [];

        foreach ($roster as $entry) {
            $existing = User::query()
                ->whereRaw('LOWER(name) = ?', [$entry['canon']])
                ->first();

            if ($existing) {
                $userIdByCanon[$entry['canon']] = $existing->id;
                if ($existing->membership) continue;
            }

            [$firstSlug, $lastSlug] = $this->splitNameForEmail($entry['name']);
            $emailBase = $firstSlug.($lastSlug ? '.'.$lastSlug : '');
            $email = $emailBase.'@import.saprf.local';
            $n = ($emailSeq[$emailBase] ?? 0) + 1;
            while (User::where('email', $email)->exists()) {
                $n++;
                $email = $emailBase.$n.'@import.saprf.local';
            }
            $emailSeq[$emailBase] = $n;

            $user = $existing ?? User::create([
                'name' => $entry['name'],
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'province_id' => $provinces->get(strtolower($entry['province']))?->id,
                'is_active' => true,
                'email_verified_at' => null,
                'must_change_password' => false,
            ]);
            $user->assignRole('member');

            $saprfNumber = 'SAPRF-2026-'.str_pad((string) $memberNumberSeq++, 5, '0', STR_PAD_LEFT);
            while (Membership::where('saprf_number', $saprfNumber)->exists()) {
                $saprfNumber = 'SAPRF-2026-'.str_pad((string) $memberNumberSeq++, 5, '0', STR_PAD_LEFT);
            }

            Membership::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'saprf_number' => $saprfNumber,
                    'membership_type' => 'paid',
                    'status' => 'active',
                    'payment_status' => 'waived',
                    'start_date' => '2026-01-01',
                    'expiry_date' => '2026-12-31',
                ],
            );

            $userIdByCanon[$entry['canon']] = $user->id;
        }

        return $userIdByCanon;
    }

    private function createMatch(array $m, $provinces, int $creatorId): MatchEvent
    {
        $provinceId = $provinces->get(strtolower($m['province']))?->id;
        $endDate = $m['match_end_date'] !== '' ? $m['match_end_date'] : null;

        return MatchEvent::create([
            'name' => $m['name'],
            'match_type' => 'pr22',
            'series' => 'pr22',
            'series_level' => $m['series_level'],
            'season' => '2026',
            'province_id' => $provinceId,
            'venue_name' => $m['venue_name'] ?: null,
            'match_date' => $m['match_date'],
            'match_end_date' => $endDate,
            'status' => 'completed',
            'created_by' => $creatorId,
            'published' => true,
            'division_awards_enabled' => true,
            'also_counts_for_provincial' => (bool) (int) ($m['also_counts_for_provincial'] ?? 0),
            'description' => trim(sprintf(
                "Imported from precisionrifle.co.za event #%s\nMatch Director: %s\nContact: %s",
                $m['source_id'], $m['match_director'] ?: '—', $m['contact'] ?: '—'
            )),
        ]);
    }

    private function createScores(MatchEvent $match, array $rows, array $userIdByCanon, $divisions, $divisionsByName): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $name = $this->cleanName($row['shooter_name'] ?? '');
            if ($name === '') continue;
            $userId = $userIdByCanon[strtolower($name)] ?? null;
            if (!$userId) {
                $this->warn("  ! Shooter not in roster (skipped): {$name} in {$match->name}");
                continue;
            }

            $rawScore = trim((string) ($row['raw_score'] ?? ''));
            if ($rawScore === '' || !is_numeric($rawScore)) continue;

            $divRaw = strtolower(trim((string) ($row['division'] ?? '')));
            $divisionId = $divisions->get($divRaw)?->id
                ?? $divisionsByName->get($divRaw)?->id
                ?? null;

            $placement = null;
            if (isset($row['placement']) && $row['placement'] !== '' && is_numeric($row['placement'])) {
                $placement = (int) $row['placement'];
            }

            Score::create([
                'match_id' => $match->id,
                'user_id' => $userId,
                'shooter_name' => $name,
                'division_id' => $divisionId,
                'day1_raw_score' => (float) $rawScore,
                'placement' => $placement,
                'status' => 'valid',
                'is_member' => true,
                'match_date' => $match->match_date,
                'counts_for_log' => true,
                'counts_for_season' => true,
                'raw_meta' => ['source' => 'precisionrifle.co.za', 'imported' => now()->toIso8601String()],
            ]);
            $count++;
        }
        return $count;
    }

    private function printSummary(): void
    {
        $userCount = User::query()->count();
        $importUsers = User::where('email', 'like', '%@import.saprf.local')->count();
        $matchCount = MatchEvent::where('match_type', 'pr22')->where('season', '2026')->count();
        $scoreCount = Score::whereHas('match', fn ($q) => $q->where('match_type', 'pr22')->where('season', '2026'))->count();
        $standingCount = Standing::where('series', 'pr22')->where('season', '2026')->count();

        $this->newLine();
        $this->info('--- Summary ---');
        $this->line("Users (total):             {$userCount}");
        $this->line("Users (imported stubs):    {$importUsers}");
        $this->line("PR22 2026 matches:         {$matchCount}");
        $this->line("PR22 2026 score rows:      {$scoreCount}");
        $this->line("PR22 2026 standings rows:  {$standingCount}");
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if (!$handle) return [];

        $bom = pack('H*', 'EFBBBF');
        $first = fread($handle, 3);
        if ($first !== $bom) rewind($handle);

        $headers = fgetcsv($handle);
        if (!$headers) { fclose($handle); return []; }
        $headers = array_map(fn ($h) => trim(strtolower((string) $h)), $headers);

        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) === 1 && trim((string) $line[0]) === '') continue;
            if (count($line) < count($headers)) {
                $line = array_pad($line, count($headers), '');
            } elseif (count($line) > count($headers)) {
                $line = array_slice($line, 0, count($headers));
            }
            $rows[] = array_combine($headers, array_map('trim', $line));
        }

        fclose($handle);
        return $rows;
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        return $name;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitNameForEmail(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name));
        $first = Str::slug($parts[0] ?? 'shooter');
        $last = Str::slug(end($parts) ?: '');
        if (count($parts) < 2) $last = '';
        return [$first ?: 'shooter', $last];
    }
}
