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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Additive importer for the CSVs produced by scripts/scrape_prs.php
 * (in storage/scraped/prs/). Turns them into real MatchEvent, User,
 * Membership and Score records, then recalculates the PRS annual log.
 *
 * Unlike pr22:import-scraped this NEVER wipes — it adds PRS data alongside
 * whatever already exists (e.g. the PR22 import), reusing any shooter that
 * already exists by normalised (case-insensitive) name. Only genuinely new
 * shooters get a fresh stub user + membership.
 *
 * Completed matches (matches.csv) are imported with scores. Upcoming matches
 * (upcoming.csv) are created as published, score-less events so they show on
 * the calendar. Re-running is safe: existing matches (matched by name +
 * series + season) are left untouched.
 */
class ImportScrapedPrsCommand extends Command
{
    protected $signature = 'prs:import-scraped
        {--dir=storage/scraped/prs : Directory containing matches.csv, upcoming.csv and per-match CSVs}
        {--skip-source-ids=252 : Comma-separated source event IDs to skip (defaults to the resultless NW provincial placeholder)}
        {--dry-run : Parse everything and print counts, but write nothing}
        {--skip-upcoming : Do not create the upcoming (score-less) matches}';

    protected $description = 'Import scraped SAPRF PRS 2026 match results additively (keeps existing data, reuses shooters by name)';

    public function handle(StandingsCalculationService $standings): int
    {
        $dir = base_path((string) $this->option('dir'));
        $catalog = $dir.DIRECTORY_SEPARATOR.'matches.csv';
        $upcomingCatalog = $dir.DIRECTORY_SEPARATOR.'upcoming.csv';

        if (! is_file($catalog)) {
            $this->error("matches.csv not found at {$catalog}. Run scripts/scrape_prs.php first.");

            return self::FAILURE;
        }

        $skipIds = array_filter(array_map('trim', explode(',', (string) $this->option('skip-source-ids'))));
        $dryRun = (bool) $this->option('dry-run');
        $doUpcoming = ! $this->option('skip-upcoming');

        $this->info('=== SAPRF PRS 2026 Scraped Import (additive) ===');
        $this->line("Source dir: {$dir}");
        $this->line('Skip source IDs: '.implode(', ', $skipIds ?: ['<none>']));
        $this->line('Import upcoming: '.($doUpcoming ? 'yes' : 'no'));
        $this->line('Dry run: '.($dryRun ? 'YES (nothing will be written)' : 'no'));
        $this->newLine();

        $matches = array_values(array_filter(
            $this->readCsv($catalog),
            fn ($m) => ! in_array($m['source_id'], $skipIds, true),
        ));
        $upcoming = ($doUpcoming && is_file($upcomingCatalog))
            ? array_values(array_filter(
                $this->readCsv($upcomingCatalog),
                fn ($m) => ! in_array($m['source_id'], $skipIds, true),
            ))
            : [];

        $this->info(count($matches).' completed match(es), '.count($upcoming).' upcoming match(es) after skip filter.');

        $roster = $this->buildRoster($dir, $matches);
        $this->info(count($roster).' unique shooters across completed matches.');

        $provinces = Province::all()->keyBy(fn ($p) => strtolower($p->name));
        $allProvinceNames = collect($matches)->pluck('province')
            ->merge(collect($upcoming)->pluck('province'))
            ->merge(collect($roster)->pluck('province'))
            ->unique();
        $missingProv = $allProvinceNames->reject(fn ($p) => $provinces->has(strtolower((string) $p)));
        if ($missingProv->filter()->isNotEmpty()) {
            $this->error('Unknown province name(s): '.$missingProv->filter()->implode(', '));

            return self::FAILURE;
        }

        $divisions = Division::all()->keyBy(fn ($d) => strtolower($d->slug));
        $divisionsByName = Division::all()->keyBy(fn ($d) => strtolower($d->name));

        if ($dryRun) {
            $existingNames = User::query()->pluck('id', DB::raw('LOWER(name)'));
            $newShooters = collect($roster)->reject(fn ($r) => isset($existingNames[$r['canon']]))->count();
            $this->line('[dry-run] Would reuse '.(count($roster) - $newShooters).' existing shooters, create '.$newShooters.' new stub users');
            $this->line('[dry-run] Would create up to '.count($matches).' completed matches (skipping any already present)');
            $this->line('[dry-run] Would create up to '.count($upcoming).' upcoming matches');
            $totalRows = 0;
            foreach ($matches as $m) {
                $totalRows += count($this->readCsv(base_path($m['scores_csv'])));
            }
            $this->line('[dry-run] Would create up to '.$totalRows.' score rows');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($roster, $matches, $upcoming, $provinces, $divisions, $divisionsByName) {
            $creator = User::whereHas('roles', fn ($q) => $q->where('name', 'developer'))->first()
                ?? User::first();
            if (! $creator) {
                throw new \RuntimeException('No user available to own imported matches. Seed RolesAndUsersSeeder first.');
            }

            $this->info('Ensuring '.count($roster).' shooter accounts + memberships...');
            $userIdByCanon = $this->createOrReuseShooters($roster, $provinces, $divisions, $divisionsByName);
            $this->line('  '.count($userIdByCanon).' shooters ready.');

            $this->info('Importing completed matches + scores...');
            $newMatches = 0;
            $totalScores = 0;
            foreach ($matches as $m) {
                [$match, $created] = $this->firstOrCreateMatch($m, $provinces, $creator->id, 'completed');
                if (! $created) {
                    $this->line("  = exists, skipping scores: {$m['name']}");

                    continue;
                }
                $newMatches++;
                $rows = $this->readCsv(base_path($m['scores_csv']));
                $totalScores += $this->createScores($match, $rows, $userIdByCanon, $divisions, $divisionsByName);
            }
            $this->line("  {$newMatches} new match(es), {$totalScores} score rows imported.");

            if ($upcoming !== []) {
                $this->info('Creating upcoming matches...');
                $newUpcoming = 0;
                foreach ($upcoming as $m) {
                    [, $created] = $this->firstOrCreateMatch($m, $provinces, $creator->id, 'open');
                    if ($created) {
                        $newUpcoming++;
                    }
                }
                $this->line("  {$newUpcoming} upcoming match(es) created.");
            }
        });

        $this->info('Recalculating PRS match rankings + annual-log standings...');
        MatchEvent::where('match_type', 'PRS')->where('season', '2026')
            ->where('status', 'completed')
            ->orderBy('match_date')
            ->get()
            ->each(fn ($match) => $standings->calculateMatchRankings($match));

        $standings->recalculateSeasonStandings('PRS', '2026', null);

        $this->printSummary();
        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{name:string,canon:string,provinces:array<string,int>,divisions:array<string,int>,province:string,division:?string}>
     */
    private function buildRoster(string $dir, array $matches): array
    {
        $roster = [];
        foreach ($matches as $m) {
            $csvPath = base_path($m['scores_csv']);
            if (! is_file($csvPath)) {
                $this->warn("  score CSV missing: {$csvPath}");

                continue;
            }
            foreach ($this->readCsv($csvPath) as $row) {
                $name = $this->cleanName($row['shooter_name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $canon = strtolower($name);
                $roster[$canon] ??= ['name' => $name, 'canon' => $canon, 'provinces' => [], 'divisions' => []];
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
            arsort($entry['divisions']);
            $entry['division'] = $entry['divisions'] !== [] ? array_key_first($entry['divisions']) : null;
        }
        unset($entry);

        return array_values($roster);
    }

    /**
     * @return array<string, int> canonical-name => user_id
     */
    private function createOrReuseShooters(array $roster, $provinces, $divisions, $divisionsByName): array
    {
        $userIdByCanon = [];
        $memberNumberSeq = 20001;
        $emailSeq = [];
        $reused = 0;
        $created = 0;

        foreach ($roster as $entry) {
            $divisionId = $entry['division'] !== null
                ? ($divisions->get($entry['division'])?->id ?? $divisionsByName->get($entry['division'])?->id)
                : null;

            $user = User::query()->whereRaw('LOWER(name) = ?', [$entry['canon']])->first();

            if ($user) {
                $reused++;
                // Backfill a division only if the shooter doesn't already have one
                // (never clobber a division assigned by an earlier import).
                if ($divisionId && ! $user->division_id) {
                    $user->update(['division_id' => $divisionId]);
                }
            } else {
                $created++;
                [$firstSlug, $lastSlug] = $this->splitNameForEmail($entry['name']);
                $emailBase = $firstSlug.($lastSlug ? '.'.$lastSlug : '');
                $email = $emailBase.'@import.saprf.local';
                $n = ($emailSeq[$emailBase] ?? 0) + 1;
                while (User::where('email', $email)->exists()) {
                    $n++;
                    $email = $emailBase.$n.'@import.saprf.local';
                }
                $emailSeq[$emailBase] = $n;

                $user = User::create([
                    'name' => $entry['name'],
                    'email' => $email,
                    'password' => Hash::make(Str::random(40)),
                    'province_id' => $provinces->get(strtolower($entry['province']))?->id,
                    'division_id' => $divisionId,
                    'is_active' => true,
                    'email_verified_at' => null,
                    'must_change_password' => false,
                ]);
                $user->assignRole('member');
            }

            // Ensure an active, fee-waived 2026 membership so scores count.
            if (! $user->membership) {
                $saprfNumber = 'SAPRF-2026-'.str_pad((string) $memberNumberSeq++, 5, '0', STR_PAD_LEFT);
                while (Membership::where('saprf_number', $saprfNumber)->exists()) {
                    $saprfNumber = 'SAPRF-2026-'.str_pad((string) $memberNumberSeq++, 5, '0', STR_PAD_LEFT);
                }
                Membership::create([
                    'user_id' => $user->id,
                    'saprf_number' => $saprfNumber,
                    'membership_type' => 'paid',
                    'status' => 'active',
                    'payment_status' => 'waived',
                    'start_date' => '2026-01-01',
                    'expiry_date' => '2026-12-31',
                ]);
            }

            $userIdByCanon[$entry['canon']] = $user->id;
        }

        $this->line("  reused {$reused} existing, created {$created} new shooter(s).");

        return $userIdByCanon;
    }

    /**
     * @return array{0:MatchEvent,1:bool} [match, wasCreated]
     */
    private function firstOrCreateMatch(array $m, $provinces, int $creatorId, string $status): array
    {
        $director = ($m['match_director'] ?? '') ?: null;
        $contact = ($m['contact'] ?? '') ?: null;

        $existing = MatchEvent::where('match_type', 'PRS')
            ->where('season', '2026')
            ->where('name', $m['name'])
            ->first();
        if ($existing) {
            // Backfill the match director on matches imported before this field
            // existed, without touching their scores.
            if ($director && $existing->match_director !== $director) {
                $existing->match_director = $director;
                $existing->match_director_contact = $contact;
                $existing->save();
            }
            return [$existing, false];
        }

        $endDate = ($m['match_end_date'] ?? '') !== '' ? $m['match_end_date'] : null;

        $match = MatchEvent::create([
            'name' => $m['name'],
            'match_type' => 'PRS',
            'series' => 'PRS',
            'series_level' => $m['series_level'],
            'season' => '2026',
            'province_id' => $provinces->get(strtolower($m['province']))?->id,
            'venue_name' => ($m['venue_name'] ?? '') ?: null,
            'match_director' => $director,
            'match_director_contact' => $contact,
            'match_date' => $m['match_date'],
            'match_end_date' => $endDate,
            'status' => $status,
            'created_by' => $creatorId,
            'published' => true,
            'division_awards_enabled' => true,
            'also_counts_for_provincial' => false,
            'description' => trim(sprintf(
                "Imported from precisionrifle.co.za event #%s\nMatch Director: %s\nContact: %s",
                $m['source_id'] ?? '?', ($m['match_director'] ?? '') ?: '—', ($m['contact'] ?? '') ?: '—'
            )),
        ]);

        return [$match, true];
    }

    private function createScores(MatchEvent $match, array $rows, array $userIdByCanon, $divisions, $divisionsByName): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $name = $this->cleanName($row['shooter_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $userId = $userIdByCanon[strtolower($name)] ?? null;
            if (! $userId) {
                $this->warn("  ! Shooter not in roster (skipped): {$name} in {$match->name}");

                continue;
            }

            $rawScore = trim((string) ($row['raw_score'] ?? ''));
            if ($rawScore === '' || ! is_numeric($rawScore)) {
                continue;
            }

            $divRaw = strtolower(trim((string) ($row['division'] ?? '')));
            $divisionId = $divisions->get($divRaw)?->id ?? $divisionsByName->get($divRaw)?->id;

            $placement = null;
            if (isset($row['placement']) && $row['placement'] !== '' && is_numeric($row['placement'])) {
                $placement = (int) $row['placement'];
            }

            Score::create([
                'match_id' => $match->id,
                'user_id' => $userId,
                'shooter_name' => $name,
                'division_id' => $divisionId,
                'raw_score' => (float) $rawScore,
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
        $this->newLine();
        $this->info('--- Summary ---');
        $this->line('Users (total):             '.User::query()->count());
        $this->line('PRS 2026 matches:          '.MatchEvent::where('match_type', 'PRS')->where('season', '2026')->count());
        $this->line('  completed:               '.MatchEvent::where('match_type', 'PRS')->where('season', '2026')->where('status', 'completed')->count());
        $this->line('  upcoming/other:          '.MatchEvent::where('match_type', 'PRS')->where('season', '2026')->where('status', '!=', 'completed')->count());
        $this->line('PRS 2026 score rows:       '.Score::whereHas('match', fn ($q) => $q->where('match_type', 'PRS')->where('season', '2026'))->count());
        $this->line('PRS 2026 standings rows:   '.Standing::where('series', 'PRS')->where('season', '2026')->count());
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if (! $handle) {
            return [];
        }

        $bom = pack('H*', 'EFBBBF');
        $first = fread($handle, 3);
        if ($first !== $bom) {
            rewind($handle);
        }

        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);

            return [];
        }
        $headers = array_map(fn ($h) => trim(strtolower((string) $h)), $headers);

        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) === 1 && trim((string) $line[0]) === '') {
                continue;
            }
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
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitNameForEmail(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name));
        $first = Str::slug($parts[0] ?? 'shooter');
        $last = Str::slug(end($parts) ?: '');
        if (count($parts) < 2) {
            $last = '';
        }

        return [$first ?: 'shooter', $last];
    }
}
