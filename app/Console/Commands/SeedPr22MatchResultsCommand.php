<?php

namespace App\Console\Commands;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Score;
use App\Models\User;
use App\Services\StandingsCalculationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seed PR22 or PRS match scores from a Practiscore-style CSV export into an
 * existing MatchEvent. Complements ImportScrapedPr22Command / ImportScrapedPrsCommand
 * (which handle the scraped precisionrifle.co.za batches). Use this when a
 * match director sends you a one-off results CSV and you already know which
 * match_id it belongs to.
 *
 * Expected CSV columns (header row required, exact case-insensitive names):
 *   Rank, Competitor, Username, Impacts, Success Rate, Dropped, Score,
 *   Division, Stages Completed, Shots Taken
 *
 * Column semantics:
 *   Rank       -> Score.placement
 *   Competitor -> match against User.name (case-insensitive)
 *   Impacts    -> Score.raw_score and Score.day1_raw_score (integer hits)
 *   Division   -> mapped to Division.slug (Open/Factory/Ladies/Senior/Junior/Limited/Production)
 *   Success Rate, Dropped, Score, Stages Completed, Shots Taken -> Score.raw_meta
 *
 * By default the command REFUSES to run if scores already exist on the match
 * (safety against double-imports). Pass --force-replace to wipe existing
 * scores on that match first. Unmatched shooter names are warned-and-skipped
 * unless --create-stubs is set (which mirrors ImportScrapedPr22Command's stub
 * user + waived-membership behavior).
 *
 * After all scores are loaded, StandingsCalculationService::recalculateForMatch
 * runs to populate normalized_score / division_normalized_score / overall_rank
 * / division_rank and refresh season standings.
 */
class SeedPr22MatchResultsCommand extends Command
{
    protected $signature = 'pr22:seed-match-results
        {match : ID of the target MatchEvent (must exist and be series=PR22 or PRS)}
        {csv : Path to the Practiscore-style CSV (absolute, or relative to base_path)}
        {--dry-run : Parse and validate the CSV but write nothing}
        {--force-replace : Wipe existing scores on the match before importing (default: refuse if scores exist)}
        {--create-stubs : Create stub users + waived memberships for names that do not match a real user (default: warn and skip)}';

    protected $description = 'Seed PR22 or PRS scores from a Practiscore-style CSV into a specific MatchEvent';

    /**
     * Both PR22 (rimfire) and PRS (centrefire) match directors export from
     * the same Practiscore backend and their CSV columns are identical.
     * Reject anything else so a wrong match_id doesn't silently seed the
     * wrong series' log.
     */
    private const SUPPORTED_SERIES = ['PR22', 'PRS'];

    /**
     * Practiscore exports from different match directors spell divisions
     * inconsistently (Seniors vs Senior, Juniors vs Junior, Stock vs Factory,
     * etc.). Normalize to the canonical DB slug before lookup so the CSV
     * never has to be hand-cleaned.
     *
     * @var array<string, string>
     */
    private const DIVISION_ALIASES = [
        'seniors' => 'senior',
        'juniors' => 'junior',
        'lady' => 'ladies',
        'female' => 'ladies',
        'stock' => 'factory',
    ];

    public function handle(StandingsCalculationService $standings): int
    {
        $matchId = (int) $this->argument('match');
        $csvArg = (string) $this->argument('csv');
        $csvPath = $this->resolveCsvPath($csvArg);
        $dryRun = (bool) $this->option('dry-run');
        $forceReplace = (bool) $this->option('force-replace');
        $createStubs = (bool) $this->option('create-stubs');

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
        if (! in_array($match->series, self::SUPPORTED_SERIES, true)) {
            $this->error("MatchEvent #{$matchId} has series='{$match->series}'. This command only handles ".implode(' or ', self::SUPPORTED_SERIES)." matches.");
            return self::FAILURE;
        }

        $this->info("=== PR22 match results seed ===");
        $this->line("Match:       #{$match->id}  {$match->name}");
        $this->line("Date:        {$match->match_date->toDateString()}   Level: {$match->series_level}");
        $this->line("CSV:         {$csvPath}");
        $this->line("Mode:        ".($dryRun ? 'DRY RUN (no writes)' : 'WRITE'));
        $this->line("Unmatched:   ".($createStubs ? 'create stub users + waived memberships' : 'warn and skip'));

        $rows = $this->readCsv($csvPath);
        if ($rows === []) {
            $this->error("CSV parsed to zero rows. Aborting.");
            return self::FAILURE;
        }
        $this->line("Rows read:   ".count($rows));

        $existingScoreCount = Score::where('match_id', $match->id)->count();
        if ($existingScoreCount > 0 && ! $forceReplace) {
            $this->error("Match #{$match->id} already has {$existingScoreCount} score(s). Pass --force-replace to wipe and re-import, or delete the existing scores first.");
            return self::FAILURE;
        }

        $divisions = Division::all()->keyBy(fn ($d) => strtolower($d->slug));
        $divisionsByName = Division::all()->keyBy(fn ($d) => strtolower($d->name));

        // Pre-scan the CSV so we can report unmatched names before we start
        // writing anything, even outside dry-run mode. This makes accidental
        // "no shooters matched" runs obvious instead of silently loading 0
        // scores and marking the match as "imported".
        [$matched, $unmatched, $missingDivisions] = $this->preflight($rows, $divisions, $divisionsByName);

        $this->newLine();
        $this->line("Matched to existing users:  ".count($matched));
        $this->line("Unmatched names:            ".count($unmatched));
        if (count($unmatched) > 0) {
            $this->warn('  '.collect($unmatched)->pluck('name')->implode(', '));
        }
        if (! empty($missingDivisions)) {
            $this->error('Unknown division name(s) in CSV: '.implode(', ', array_unique($missingDivisions)));
            $this->error('Fix the CSV or seed the missing division(s). Aborting.');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->newLine();
            $this->info("[dry-run] Would create ".(count($matched) + ($createStubs ? count($unmatched) : 0))." score row(s).");
            if ($existingScoreCount > 0) {
                $this->line("[dry-run] Would first delete {$existingScoreCount} existing score(s) on this match.");
            }
            return self::SUCCESS;
        }

        DB::transaction(function () use ($match, $rows, $divisions, $divisionsByName, $forceReplace, $createStubs, $unmatched) {
            if ($forceReplace) {
                Score::where('match_id', $match->id)->delete();
            }

            $stubUserIds = [];
            if ($createStubs && $unmatched !== []) {
                $stubUserIds = $this->createStubs($unmatched, $match);
            }

            $this->writeScores($match, $rows, $divisions, $divisionsByName, $stubUserIds, $createStubs);
        });

        $this->newLine();
        $this->info('Recalculating rankings + season standings for this match...');
        $standings->recalculateForMatch($match->fresh());

        $written = Score::where('match_id', $match->id)->count();
        $this->info("Done. Match #{$match->id} now has {$written} score(s).");

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
     * @return array{0: array<int, array{name:string, user_id:int, division_slug:string|null, row:array<string,string>}>,
     *               1: array<int, array{name:string, division_slug:string|null, row:array<string,string>}>,
     *               2: list<string>}
     */
    private function preflight(array $rows, $divisions, $divisionsByName): array
    {
        $matched = [];
        $unmatched = [];
        $missingDivisions = [];

        foreach ($rows as $row) {
            $name = $this->cleanName((string) ($row['competitor'] ?? ''));
            if ($name === '') {
                continue;
            }
            $divRaw = $this->normalizeDivision((string) ($row['division'] ?? ''));
            if ($divRaw !== '' && ! $divisions->has($divRaw) && ! $divisionsByName->has($divRaw)) {
                $missingDivisions[] = $divRaw;
            }

            $user = User::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
            if ($user) {
                $matched[] = [
                    'name' => $name,
                    'user_id' => $user->id,
                    'division_slug' => $divRaw ?: null,
                    'row' => $row,
                ];
            } else {
                $unmatched[] = [
                    'name' => $name,
                    'division_slug' => $divRaw ?: null,
                    'row' => $row,
                ];
            }
        }

        return [$matched, $unmatched, $missingDivisions];
    }

    /**
     * @param  array<int, array{name:string, division_slug:string|null, row:array<string,string>}>  $unmatched
     * @return array<string, int>  canonical-name => user_id
     */
    private function createStubs(array $unmatched, MatchEvent $match): array
    {
        $out = [];
        $memberNumberSeq = 20001;
        $season = $match->season ?: (string) $match->match_date->year;

        foreach ($unmatched as $entry) {
            $name = $entry['name'];
            [$firstSlug, $lastSlug] = $this->splitNameForEmail($name);
            $emailBase = $firstSlug.($lastSlug ? '.'.$lastSlug : '');
            $email = $emailBase.'@import.saprf.local';
            $n = 0;
            while (User::where('email', $email)->exists()) {
                $n++;
                $email = $emailBase.$n.'@import.saprf.local';
            }

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'is_active' => true,
                'email_verified_at' => null,
                'must_change_password' => false,
            ]);
            $user->assignRole('member');

            $saprfNumber = "SAPRF-{$season}-".str_pad((string) $memberNumberSeq++, 5, '0', STR_PAD_LEFT);
            while (Membership::where('saprf_number', $saprfNumber)->exists()) {
                $saprfNumber = "SAPRF-{$season}-".str_pad((string) $memberNumberSeq++, 5, '0', STR_PAD_LEFT);
            }
            Membership::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'saprf_number' => $saprfNumber,
                    'membership_type' => 'paid',
                    'status' => 'active',
                    'payment_status' => 'waived',
                    'start_date' => $season.'-01-01',
                    'expiry_date' => $season.'-12-31',
                ],
            );

            $out[strtolower($name)] = $user->id;
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $stubUserIds
     */
    private function writeScores(MatchEvent $match, array $rows, $divisions, $divisionsByName, array $stubUserIds, bool $createStubs): void
    {
        $written = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $name = $this->cleanName((string) ($row['competitor'] ?? ''));
            if ($name === '') {
                continue;
            }

            $user = User::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
            $userId = $user?->id ?? ($stubUserIds[strtolower($name)] ?? null);
            if (! $userId) {
                if (! $createStubs) {
                    $this->line("  - skip (no user): {$name}");
                    $skipped++;
                    continue;
                }
                $this->line("  ! stub creation returned no id for {$name}, skipping");
                $skipped++;
                continue;
            }

            $impacts = trim((string) ($row['impacts'] ?? ''));
            if ($impacts === '' || ! is_numeric($impacts)) {
                $this->line("  - skip (no impacts): {$name}");
                $skipped++;
                continue;
            }

            $divRaw = $this->normalizeDivision((string) ($row['division'] ?? ''));
            $divisionId = $divisions->get($divRaw)?->id
                ?? $divisionsByName->get($divRaw)?->id
                ?? null;

            $rank = trim((string) ($row['rank'] ?? ''));
            $placement = ($rank !== '' && is_numeric($rank)) ? (int) $rank : null;

            $rawScore = (float) $impacts;

            Score::create([
                'match_id' => $match->id,
                'user_id' => $userId,
                'shooter_name' => $name,
                'division_id' => $divisionId,
                'raw_score' => $rawScore,
                'day1_raw_score' => $rawScore,
                'placement' => $placement,
                'status' => 'valid',
                'is_member' => (bool) $user,
                'match_date' => $match->match_date,
                'counts_for_log' => true,
                'counts_for_season' => true,
                'raw_meta' => [
                    'source' => 'practiscore_csv',
                    'imported_at' => now()->toIso8601String(),
                    'username' => $row['username'] ?? null,
                    'success_rate' => $row['success rate'] ?? null,
                    'dropped' => $row['dropped'] ?? null,
                    'score_pct' => $row['score'] ?? null,
                    'stages_completed' => $row['stages completed'] ?? null,
                    'shots_taken' => $row['shots taken'] ?? null,
                ],
            ]);
            $written++;
        }

        $this->info("Wrote {$written} score(s), skipped {$skipped}.");
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

    private function normalizeDivision(string $raw): string
    {
        $lower = strtolower(trim($raw));
        return self::DIVISION_ALIASES[$lower] ?? $lower;
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
