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
 * Seed the 2025 IPRF PR22lr World Championships roster into the
 * national_team_appearances table.
 *
 * Championship context — Great Britain Precision Rifle Association (GBPRA),
 * West Midlands Shooting Centre, United Kingdom, 17–24 August 2025. 22
 * shooters across five divisions (Open, Ladies, Factory, Seniors, Juniors).
 *
 * Source roster: the official Team South Africa infographic published by
 * SAPRF/IPRF (dates, venue, divisions, full first names) cross-referenced
 * with the Precision Rifle - South Africa Facebook announcement of 8 June
 * 2025. Where the two disagree, the infographic wins (e.g. "Desmond Wellen"
 * not "Des", "Luane Fick" not "Luané", "Le Riche Coetzer" not "Le Riche
 * Coetzer Snr", "Glen Clark" not "Glen M Clark").
 *
 * Mirrors SeedNationalTeam2026Command (the Centrefire Worlds seeder) in
 * every structural respect — same resolution order, same idempotency
 * guard, same AMBIGUOUS/UNRESOLVED reporting. The only intentional
 * divergences are:
 *
 *   - `discipline` is 'rimfire' instead of 'centrefire'.
 *   - `host_country` is 'GB' instead of 'US'.
 *   - `awarded_colours` is forced to FALSE on every row. Representing SA at
 *     this championship is a national-team selection, not a Protea Colours
 *     award. Exco will award Protea Colours separately via the admin UI
 *     (NationalTeamAppearanceController's "promote on toggle" path).
 *   - No captain or manager roles — the Facebook announcement doesn't call
 *     out a captain or team manager, so every row is role=shooter.
 *   - starts_on / ends_on / venue / city are left NULL so Exco can set
 *     the exact dates and venue via the admin UI once they're confirmed.
 *
 * Shooter resolution order (identical to SeedNationalTeam2026Command):
 *   1. SAPRF number from `SAPRF_HINTS` if the roster name needs pinning.
 *   2. Case-insensitive, whitespace-collapsed name match on users.name.
 *   3. If a name resolves to 2+ users, the row is reported and skipped
 *      so a human can pin it with the SAPRF hint.
 *
 * Idempotent: re-running does not create duplicate rows (match keyed on
 * user + year + championship), so Exco can safely rerun after fixing any
 * unresolved names.
 */
class SeedNationalTeam2025Command extends Command
{
    protected $signature = 'saprf:seed-national-team-2025
        {--dry-run : Report what would change without writing}';

    protected $description = 'Seed the 2025 IPRF Rimfire PRS .22 World Championship SA team into national_team_appearances';

    private const YEAR = 2025;

    private const CHAMPIONSHIP = '2025 IPRF PR22lr World Championships';

    private const DISCIPLINE = 'rimfire';

    private const HOST_COUNTRY = 'GB';

    private const VENUE = 'West Midlands Shooting Centre';

    private const CITY = 'West Midlands';

    private const STARTS_ON = '2025-08-17';

    private const ENDS_ON = '2025-08-24';

    /** First day of competition — anchor for the per-appearance date. */
    private const APPEARED_AT = self::STARTS_ON;

    /**
     * Division label resolution — attaches the matching Division row (so
     * standings grouping works) and falls back to the free-text
     * division_label when the slug doesn't exist.
     *
     * Order within the roster is preserved from the source Facebook post.
     */
    private const ROSTER = [
        // Open division (6)
        ['name' => 'Willem Van Biljon',     'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Warren Britnell',       'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Malcolm Coetzee',       'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Kevin Goncalves',       'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Henna du Plessis',      'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Jason Marais',          'division_slug' => 'open',    'division_label' => 'Open'],

        // Ladies division (4)
        ['name' => 'Kim-Leigh Ferreira',    'division_slug' => 'ladies',  'division_label' => 'Ladies'],
        ['name' => 'Jeanette Coetzee',      'division_slug' => 'ladies',  'division_label' => 'Ladies'],
        ['name' => 'Liezel Foot',           'division_slug' => 'ladies',  'division_label' => 'Ladies'],
        ['name' => 'Diana Zoccola',         'division_slug' => 'ladies',  'division_label' => 'Ladies'],

        // Factory division (4)
        ['name' => 'Desmond Wellen',        'division_slug' => 'factory', 'division_label' => 'Factory'],
        ['name' => 'Russell Ferreira',      'division_slug' => 'factory', 'division_label' => 'Factory'],
        ['name' => 'Dumisani Shabangu',     'division_slug' => 'factory', 'division_label' => 'Factory'],
        ['name' => 'Hanno Van Niekerk',     'division_slug' => 'factory', 'division_label' => 'Factory'],

        // Seniors division (4)
        ['name' => 'Greg Sykes',            'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Glen Clark',            'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Le Riche Coetzer',      'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Andries Lategan',       'division_slug' => 'senior',  'division_label' => 'Seniors'],

        // Juniors division (4)
        ['name' => 'Tinus Cronje',          'division_slug' => 'junior',  'division_label' => 'Juniors'],
        ['name' => 'Zenique De Klerk',      'division_slug' => 'junior',  'division_label' => 'Juniors'],
        ['name' => 'Johan Symington',       'division_slug' => 'junior',  'division_label' => 'Juniors'],
        ['name' => 'Luane Fick',            'division_slug' => 'junior',  'division_label' => 'Juniors'],
    ];

    /**
     * Reserves — named on the official SAPRF/IPRF infographic but not
     * part of the shooting squad. They render in a dedicated Reserves
     * strip beneath the main division cards on /sa-team/2025, and are
     * excluded from the "22 shooters" squad-size stat and from Protea
     * Colours (enforced by NationalTeamAppearanceController).
     *
     * Aliza Mey has a known SAPRF-roll spelling difference ("Aliza" in
     * SAPRF, "Eliza" in some press copy) — pinned by SAPRF #427 so the
     * 2025 reserve row resolves to the same person as the 2026
     * Centrefire Ladies main-squad row.
     */
    private const RESERVES = [
        ['name' => 'Anthony Barraball',  'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Aliza Mey',          'division_slug' => 'ladies',  'division_label' => 'Ladies'],
        ['name' => 'Catelynn Britnell',  'division_slug' => 'junior',  'division_label' => 'Juniors'],
        ['name' => 'Heinrich Strauss',   'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Handre van Niekerk', 'division_slug' => 'factory', 'division_label' => 'Factory'],
    ];

    /**
     * Optional SAPRF-number overrides for roster rows whose names collide
     * or whose magazine spelling differs from the SAPRF membership roll.
     * Keyed on the roster `name` so the resolution pins without renaming
     * users in the DB.
     *
     * Shared pins with SeedNationalTeam2026Command are intentional — both
     * seeders see the same real-world people, so they need the same hints.
     */
    private const SAPRF_HINTS = [
        // Infographic drops the "Snr" suffix on the 2025 roster; SAPRF
        // membership is "LeRiche Coetzer Snr", #16, Western Cape. Pin
        // the number so the lookup still succeeds even without the suffix.
        'Le Riche Coetzer' => '16',

        // Two users share the name "Tinus Cronje" — the paid Mpumalanga
        // member (#1083) and a managed shell account auto-created from
        // a match entry. Pin the real one.
        'Tinus Cronje' => '1083',

        // Aliza / Eliza Mey spelling difference — same person as the
        // 2026 Centrefire Ladies main-squad row.
        'Aliza Mey' => '427',

        // Two users share the name "Zenique De Klerk" — the paid Gauteng
        // member (#1229) and a managed shell auto-created from a match
        // entry. Pin the real one.
        'Zenique De Klerk' => '1229',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== saprf:seed-national-team-2025 ===');
        $this->line('Championship:  '.self::CHAMPIONSHIP);
        $this->line('Discipline:    '.self::DISCIPLINE);
        $this->line('Host:          Great Britain');
        $this->line('Venue:         '.self::VENUE.', '.self::CITY);
        $this->line('Dates:         '.self::STARTS_ON.' → '.self::ENDS_ON);
        $this->line('Dry run:       '.($dryRun ? 'YES' : 'no'));
        $this->newLine();

        $divisionsBySlug = Division::all()->keyBy('slug');
        $recordedBy = User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))
            ->orderBy('id')
            ->value('id') ?? User::query()->min('id');

        // Find-or-create the shared NationalTeam header. Dates and venue
        // come from the official SAPRF/IPRF 2025 infographic (GBPRA, West
        // Midlands Shooting Centre, 17–24 August 2025). firstOrCreate
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
            'created_shooters' => 0,
            'created_reserves' => 0,
            'already_present' => 0,
            'ambiguous' => [],
            'unresolved' => [],
        ];

        $tx = $dryRun ? null : DB::beginTransaction();

        try {
            $this->line('— Shooting squad —');
            foreach (self::ROSTER as $entry) {
                $this->processEntry(
                    entry: $entry,
                    role: NationalTeamAppearance::ROLE_SHOOTER,
                    team: $team,
                    divisionsBySlug: $divisionsBySlug,
                    usersByStrict: $usersByStrict,
                    usersByLoose: $usersByLoose,
                    recordedBy: $recordedBy,
                    dryRun: $dryRun,
                    report: $report,
                );
            }

            $this->newLine();
            $this->line('— Reserves —');
            foreach (self::RESERVES as $entry) {
                $this->processEntry(
                    entry: $entry,
                    role: NationalTeamAppearance::ROLE_RESERVE,
                    team: $team,
                    divisionsBySlug: $divisionsBySlug,
                    usersByStrict: $usersByStrict,
                    usersByLoose: $usersByLoose,
                    recordedBy: $recordedBy,
                    dryRun: $dryRun,
                    report: $report,
                );
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
        $this->line('Shooters created:   '.$report['created_shooters']);
        $this->line('Reserves created:   '.$report['created_reserves']);
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
     * Resolve one roster/reserve entry, apply the idempotency guard, and
     * create the appearance row (unless dry-run). Mutates $report in-place.
     *
     * @param  array{name: string, division_slug: string|null, division_label: string}  $entry
     * @param  array<string, array<int, User>>  $usersByStrict
     * @param  array<string, array<int, User>>  $usersByLoose
     * @param  array{created_shooters:int, created_reserves:int, already_present:int, ambiguous:array, unresolved:array}  $report
     */
    private function processEntry(
        array $entry,
        string $role,
        NationalTeam $team,
        \Illuminate\Support\Collection $divisionsBySlug,
        array $usersByStrict,
        array $usersByLoose,
        ?int $recordedBy,
        bool $dryRun,
        array &$report,
    ): void {
        $resolution = $this->resolveShooter($entry['name'], $usersByStrict, $usersByLoose);

        if ($resolution['status'] === 'unresolved') {
            $report['unresolved'][] = $entry['name'];
            $this->warn("  UNRESOLVED  {$entry['name']} — no matching user");
            return;
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
            return;
        }

        $user = $resolution['user'];

        // Idempotency guard — a row keyed on (user, year, championship)
        // already exists. We DON'T upgrade an existing shooter row to a
        // reserve role (that would be a semantic downgrade); likewise
        // we don't downgrade reserve to shooter. Both are left as-is
        // and reported so the operator can resolve mismatches manually.
        $existing = NationalTeamAppearance::query()
            ->where('user_id', $user->id)
            ->where('year', self::YEAR)
            ->where('championship_name', self::CHAMPIONSHIP)
            ->first();

        if ($existing !== null) {
            $report['already_present']++;
            $this->line("  SKIP        {$entry['name']} — appearance already recorded (id={$existing->id}, role={$existing->role})");
            return;
        }

        $divisionId = $entry['division_slug']
            ? $divisionsBySlug->get($entry['division_slug'])?->id
            : null;

        $this->line(sprintf(
            '  CREATE %-7s %-28s  div=%-8s',
            strtoupper($role),
            $entry['name'],
            $entry['division_label'],
        ));

        if (! $dryRun) {
            NationalTeamAppearance::create([
                'user_id' => $user->id,
                'year' => self::YEAR,
                'division_id' => $divisionId,
                'division_label' => $entry['division_label'],
                'role' => $role,
                'national_team_id' => $team->id,
                'championship_name' => self::CHAMPIONSHIP,
                'host_country' => self::HOST_COUNTRY,
                'placing' => null,
                'selection_cycle_id' => null,
                // Protea Colours intentionally NOT awarded by this seeder —
                // Exco awards them separately via the admin UI. Reserves
                // never get Colours anyway (enforced by the admin controller).
                'awarded_colours' => false,
                'appeared_at' => Carbon::parse(self::APPEARED_AT),
                'recorded_by' => $recordedBy,
                'notes' => null,
            ]);
        }

        if ($role === NationalTeamAppearance::ROLE_RESERVE) {
            $report['created_reserves']++;
        } else {
            $report['created_shooters']++;
        }
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

        // 2. Strict normalisation: lowercase + collapse whitespace. Keeps
        // punctuation so hyphenated surnames don't accidentally match
        // unrelated users.
        $strict = self::normaliseStrict($name);
        $matches = $usersByStrict[$strict] ?? [];

        // 3. Loose fallback: strip punctuation + diacritics on both sides
        // for cases like "Luané Fick" vs "Luane Fick", or "J.C. Robertson"
        // vs "JC Robertson".
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
        // Transliterate common diacritics to ASCII so "Luané" matches
        // "Luane" via the loose index.
        $ascii = str_replace(
            ['á', 'à', 'â', 'ä', 'ã', 'å', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï',
             'ó', 'ò', 'ô', 'ö', 'õ', 'ú', 'ù', 'û', 'ü', 'ý', 'ÿ', 'ñ', 'ç',
             'Á', 'À', 'Â', 'Ä', 'Ã', 'Å', 'É', 'È', 'Ê', 'Ë', 'Í', 'Ì', 'Î', 'Ï',
             'Ó', 'Ò', 'Ô', 'Ö', 'Õ', 'Ú', 'Ù', 'Û', 'Ü', 'Ý', 'Ñ', 'Ç'],
            ['a', 'a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i',
             'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'y', 'n', 'c',
             'A', 'A', 'A', 'A', 'A', 'A', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I',
             'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'Y', 'N', 'C'],
            $name,
        );

        $stripped = (string) preg_replace('/[^A-Za-z0-9 ]/', '', $ascii);

        return strtolower((string) preg_replace('/\s+/', ' ', trim($stripped)));
    }
}
