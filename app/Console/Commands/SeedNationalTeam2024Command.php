<?php

namespace App\Console\Commands;

use App\Models\Division;
use App\Models\Membership;
use App\Models\NationalTeam;
use App\Models\NationalTeamAppearance;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seed the 2024 IPRF World Championship (Centrefire) roster into the
 * national_team_appearances table.
 *
 * Championship context — Cameo Shooting Centre, Grand Junction, Colorado,
 * USA, 11–17 September 2024, hosted by the United States Precision Rifle
 * Association (USPRA). 22 shooters across six divisions.
 *
 * Source roster: the official SAPRF/IPRF Team South Africa 2024
 * infographic (dates, venue, divisions, full first names) corroborated
 * by the per-division composite photos published alongside it. No team
 * captain or team manager was called out on the published roster.
 *
 * Mirrors SeedNationalTeam2025Command / SeedNationalTeam2026Command in
 * every structural respect — same resolution order, same idempotency
 * guard, same AMBIGUOUS/UNRESOLVED reporting. The only intentional
 * divergences are:
 *
 *   - Championship-specific metadata (year, discipline, host, dates).
 *   - `awarded_colours` is forced to FALSE on every row. Representing SA
 *     at this championship is a national-team selection, not a Protea
 *     Colours award. Exco awards Colours separately via the admin UI.
 *     This matters especially for pre-2026 teams: without this flag the
 *     seeder would retroactively hand out Colours to anyone who has
 *     since been given them on a later-year row, which the one-colours-
 *     per-user invariant then rejects.
 *   - No captain / manager / reserve roles — the published 2024 roster
 *     doesn't name any of them, so every row is role=shooter.
 *
 * Idempotent: re-running does not create duplicate rows (match keyed on
 * user + year + championship), so Exco can safely rerun after fixing any
 * unresolved names.
 */
class SeedNationalTeam2024Command extends Command
{
    protected $signature = 'saprf:seed-national-team-2024
        {--dry-run : Report what would change without writing}';

    protected $description = 'Seed the 2024 IPRF Centrefire World Championship SA team (Colorado) into national_team_appearances';

    private const YEAR = 2024;

    private const CHAMPIONSHIP = '2024 IPRF World Championship (Centrefire)';

    private const DISCIPLINE = 'centrefire';

    private const HOST_COUNTRY = 'US';

    private const VENUE = 'Cameo Shooting Centre';

    private const CITY = 'Grand Junction, Colorado';

    private const STARTS_ON = '2024-09-11';

    private const ENDS_ON = '2024-09-17';

    /** First day of competition — anchor for the per-appearance date. */
    private const APPEARED_AT = self::STARTS_ON;

    /**
     * Division label resolution — attaches the matching Division row (so
     * standings grouping works) and falls back to the free-text
     * division_label when the slug doesn't exist.
     *
     * Order within the roster matches the SAPRF infographic layout.
     */
    private const ROSTER = [
        // Open division (6)
        ['name' => 'Leon Goosen',           'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Warren Britnell',       'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Ruan Zeeman',           'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Johan Lottering',       'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Marcel Steyn',          'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Hendrik Nel',           'division_slug' => 'open',    'division_label' => 'Open'],

        // Senior division (3)
        ['name' => 'Le Riche Coetzer',      'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Andries Lategan',       'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Glen Clark',            'division_slug' => 'senior',  'division_label' => 'Seniors'],

        // Factory division (4)
        ['name' => 'Russell Ferreira',      'division_slug' => 'factory', 'division_label' => 'Factory'],
        ['name' => 'Hanno van Niekerk',     'division_slug' => 'factory', 'division_label' => 'Factory'],
        ['name' => 'Noel Rowe',             'division_slug' => 'factory', 'division_label' => 'Factory'],
        ['name' => 'Garth Olivier',         'division_slug' => 'factory', 'division_label' => 'Factory'],

        // Ladies division (4)
        ['name' => 'Kim-Leigh Ferreira',    'division_slug' => 'ladies',  'division_label' => 'Ladies'],
        ['name' => 'Liesl Baben',           'division_slug' => 'ladies',  'division_label' => 'Ladies'],
        ['name' => 'Natasha Britnell',      'division_slug' => 'ladies',  'division_label' => 'Ladies'],
        ['name' => 'Monica le Roux',        'division_slug' => 'ladies',  'division_label' => 'Ladies'],

        // Limited division (4)
        ['name' => 'J.C. Robertson',        'division_slug' => 'limited', 'division_label' => 'Limited'],
        ['name' => 'Willem van Biljon',     'division_slug' => 'limited', 'division_label' => 'Limited'],
        ['name' => 'Pieter Cronje',         'division_slug' => 'limited', 'division_label' => 'Limited'],
        ['name' => 'Edward Henwood',        'division_slug' => 'limited', 'division_label' => 'Limited'],

        // Junior division (1)
        ['name' => 'Donovan Cook',          'division_slug' => 'junior',  'division_label' => 'Juniors'],
    ];

    /**
     * Optional SAPRF-number overrides for roster rows whose names collide
     * or whose infographic spelling differs from the SAPRF membership roll.
     * Shared pins with the 2025 and 2026 seeders are intentional — all
     * three seeders see the same real-world people and need the same hints.
     */
    private const SAPRF_HINTS = [
        // Infographic drops the "Snr" suffix; SAPRF membership is
        // "LeRiche Coetzer Snr", #16, Western Cape.
        'Le Riche Coetzer' => '16',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== saprf:seed-national-team-2024 ===');
        $this->line('Championship:  '.self::CHAMPIONSHIP);
        $this->line('Discipline:    '.self::DISCIPLINE);
        $this->line('Host:          United States');
        $this->line('Venue:         '.self::VENUE.', '.self::CITY);
        $this->line('Dates:         '.self::STARTS_ON.' → '.self::ENDS_ON);
        $this->line('Dry run:       '.($dryRun ? 'YES' : 'no'));
        $this->newLine();

        $divisionsBySlug = Division::all()->keyBy('slug');
        $recordedBy = User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))
            ->orderBy('id')
            ->value('id') ?? User::query()->min('id');

        // Find-or-create the shared NationalTeam header. firstOrCreate
        // only writes the second-arg attributes on insert, so re-running
        // after the admin has tweaked the venue/dates in-UI leaves their
        // edits intact.
        $team = NationalTeam::firstOrCreate(
            ['year' => self::YEAR, 'championship_name' => self::CHAMPIONSHIP],
            [
                'discipline' => self::DISCIPLINE,
                'host_country' => self::HOST_COUNTRY,
                'city' => self::CITY,
                'venue' => self::VENUE,
                'starts_on' => self::STARTS_ON,
                'ends_on' => self::ENDS_ON,
            ],
        );

        // Build an in-PHP name index so resolution works identically on
        // MySQL (prod) and SQLite (tests). Covers both strict and
        // punctuation-stripped forms.
        $usersByStrict = [];
        $usersByLoose = [];
        User::query()->with('membership')->chunk(500, function ($chunk) use (&$usersByStrict, &$usersByLoose) {
            foreach ($chunk as $user) {
                $strict = self::normaliseStrict((string) $user->name);
                $loose = self::normaliseLoose((string) $user->name);
                $usersByStrict[$strict][] = $user;
                $usersByLoose[$loose][] = $user;
            }
        });

        $report = [
            'created' => 0,
            'already_present' => 0,
            'ambiguous' => [],
            'unresolved' => [],
        ];

        $tx = $dryRun ? null : DB::beginTransaction();

        try {
            foreach (self::ROSTER as $entry) {
                $resolution = $this->resolveShooter($entry['name'], $usersByStrict, $usersByLoose);

                if ($resolution['status'] === 'unresolved') {
                    $report['unresolved'][] = $entry['name'];
                    $this->warn("  UNRESOLVED  {$entry['name']} — no matching user");
                    continue;
                }

                if ($resolution['status'] === 'ambiguous') {
                    $report['ambiguous'][] = [
                        'name' => $entry['name'],
                        'matches' => $resolution['matches'],
                    ];
                    $this->warn("  AMBIGUOUS   {$entry['name']} — ".count($resolution['matches']).' candidate users; add SAPRF hint');
                    foreach ($resolution['matches'] as $candidate) {
                        $saprf = $candidate->membership?->saprf_number ?? '—';
                        $this->line("                → user id={$candidate->id}  SAPRF={$saprf}  email={$candidate->email}");
                    }
                    continue;
                }

                $user = $resolution['user'];

                // Idempotency guard — a row keyed on
                // (user, year, championship) already exists.
                $existing = NationalTeamAppearance::query()
                    ->where('user_id', $user->id)
                    ->where('year', self::YEAR)
                    ->where('championship_name', self::CHAMPIONSHIP)
                    ->first();

                if ($existing !== null) {
                    $report['already_present']++;
                    $this->line("  SKIP        {$entry['name']} — appearance already recorded (id={$existing->id})");
                    continue;
                }

                $divisionId = $entry['division_slug']
                    ? $divisionsBySlug->get($entry['division_slug'])?->id
                    : null;

                $this->line(sprintf(
                    '  CREATE      %-28s  div=%-8s',
                    $entry['name'],
                    $entry['division_label'],
                ));

                if (! $dryRun) {
                    NationalTeamAppearance::create([
                        'user_id' => $user->id,
                        'year' => self::YEAR,
                        'division_id' => $divisionId,
                        'division_label' => $entry['division_label'],
                        'role' => NationalTeamAppearance::ROLE_SHOOTER,
                        'national_team_id' => $team->id,
                        'championship_name' => self::CHAMPIONSHIP,
                        'host_country' => self::HOST_COUNTRY,
                        'placing' => null,
                        'selection_cycle_id' => null,
                        // Protea Colours intentionally NOT awarded here —
                        // Exco will award them separately via the admin UI,
                        // and should promote 2024 rows over later rows for
                        // shooters whose first SA appearance was 2024.
                        'awarded_colours' => false,
                        'appeared_at' => Carbon::parse(self::APPEARED_AT),
                        'recorded_by' => $recordedBy,
                        'notes' => null,
                    ]);
                }

                $report['created']++;
            }

            if (! $dryRun) {
                DB::commit();
            }
        } catch (\Throwable $e) {
            if (! $dryRun) {
                DB::rollBack();
            }
            $this->error('Seed aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('--- Summary ---');
        $this->line('Created:            '.$report['created']);
        $this->line('Already present:    '.$report['already_present']);
        $this->line('Unresolved names:   '.count($report['unresolved']));
        $this->line('Ambiguous names:    '.count($report['ambiguous']));
        $this->line('Colours awarded:    0   (team selection only — award separately)');

        if ($report['unresolved']) {
            $this->newLine();
            $this->warn('Unresolved — these roster rows did not match any user:');
            foreach ($report['unresolved'] as $name) {
                $this->line('  • '.$name);
            }
            $this->line('Add their SAPRF numbers to SAPRF_HINTS in the command, or create user accounts first, then rerun.');
        }

        if ($report['ambiguous']) {
            $this->newLine();
            $this->warn('Ambiguous — multiple users match these names:');
            foreach ($report['ambiguous'] as $amb) {
                $this->line('  • '.$amb['name']);
            }
            $this->line('Pin each one by adding an entry to SAPRF_HINTS in the command, then rerun.');
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('DRY RUN — no changes were written.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<int, User>>  $usersByStrict
     * @param  array<string, array<int, User>>  $usersByLoose
     * @return array{status: 'resolved'|'ambiguous'|'unresolved', user?: User, matches?: array<int, User>}
     */
    private function resolveShooter(string $name, array $usersByStrict, array $usersByLoose): array
    {
        // 1. Explicit SAPRF-number pin wins.
        $hint = self::SAPRF_HINTS[$name] ?? null;
        if ($hint !== null) {
            $viaSaprf = Membership::where('saprf_number', $hint)->first()?->user;
            if ($viaSaprf !== null) {
                return ['status' => 'resolved', 'user' => $viaSaprf];
            }
        }

        // 2. Strict normalisation: lowercase + collapse whitespace.
        $strict = self::normaliseStrict($name);
        $matches = $usersByStrict[$strict] ?? [];

        // 3. Loose fallback: strip punctuation on both sides for cases like
        // "J.C. Robertson" vs "JC Robertson".
        if ($matches === []) {
            $loose = self::normaliseLoose($name);
            $matches = $usersByLoose[$loose] ?? [];
        }

        if ($matches === []) {
            return ['status' => 'unresolved'];
        }

        if (count($matches) > 1) {
            return ['status' => 'ambiguous', 'matches' => $matches];
        }

        return ['status' => 'resolved', 'user' => $matches[0]];
    }

    private static function normaliseStrict(string $name): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($name)));
    }

    private static function normaliseLoose(string $name): string
    {
        $stripped = (string) preg_replace('/[^A-Za-z0-9 ]/', '', $name);

        return strtolower((string) preg_replace('/\s+/', ' ', trim($stripped)));
    }
}
