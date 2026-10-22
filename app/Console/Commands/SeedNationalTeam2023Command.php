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
 * Seed the 2023 IPRF PR22lr World Championships roster into the
 * national_team_appearances table.
 *
 * Championship context — the inaugural IPRF PR22 (22LR) World Championship,
 * Collazzone (PR), Umbria, Italy, 28 August – 3 September 2023. 12 team
 * members: 11 shooters plus Gregory Sykes who travelled in a dual
 * shooter + team-manager capacity, and Glen Clark as tour team captain.
 *
 * Source roster: the On Target Africa magazine congratulations page for
 * the SAPRF PR22 National Team (image supplied by Exco, Oct 2026). The
 * published roster is a flat list — no per-division breakdown — so every
 * appearance is recorded under the Open division, matching the way the
 * magazine presented the squad.
 *
 * The 2023 NationalTeam header row already exists (created by
 * ImportTeamSa2023PhotoCommand to attach the team-portrait album) with
 * only `discipline` set. This seeder backfills the missing venue/city/
 * dates on first run and leaves them alone on subsequent runs so any
 * admin-UI tweaks are preserved.
 *
 *   - `awarded_colours` is forced to FALSE on every row. Per Exco: this
 *     team "just represented South Africa" — no Protea Colours were
 *     awarded for the inaugural PR22 Worlds. The seeder does not call
 *     `User::hasProteaColours()` on these rows because we never want to
 *     promote colours out of this cohort.
 *
 *   - Glen Clark is recorded with role=captain (Tour Team Captain per
 *     the magazine caption).
 *
 *   - Gregory Sykes is recorded TWICE: once as role=shooter in Open, and
 *     once as role=manager in a Team Manager slot. This is why the
 *     idempotency guard keys on (user, year, championship, role) rather
 *     than the 3-tuple used by the 2024/2025/2026 seeders — Greg's two
 *     rows must both survive a rerun.
 *
 * Shooter resolution order (identical to the 2024/2025/2026 seeders):
 *   1. SAPRF number from `SAPRF_HINTS` if the roster name needs pinning.
 *   2. Case-insensitive, whitespace-collapsed name match on users.name.
 *   3. Loose fallback that strips punctuation + diacritics on both sides
 *      so "Corné van As" matches "Corne van As" and vice versa.
 *   4. If a name resolves to 2+ users, the row is reported and skipped
 *      so a human can pin it with the SAPRF hint.
 *
 * Idempotent: re-running does not create duplicate rows (match keyed on
 * user + year + championship + role), so Exco can safely rerun after
 * fixing any unresolved names.
 */
class SeedNationalTeam2023Command extends Command
{
    protected $signature = 'saprf:seed-national-team-2023
        {--dry-run : Report what would change without writing}';

    protected $description = 'Seed the 2023 IPRF PR22lr World Championships SA team (Italy) into national_team_appearances';

    private const YEAR = 2023;

    private const CHAMPIONSHIP = '2023 IPRF PR22lr World Championships';

    private const DISCIPLINE = 'rimfire';

    private const HOST_COUNTRY = 'IT';

    private const CITY = 'Collazzone, Umbria';

    private const STARTS_ON = '2023-08-28';

    private const ENDS_ON = '2023-09-03';

    /** First day of competition — anchor for the per-appearance date. */
    private const APPEARED_AT = self::STARTS_ON;

    /**
     * Division breakdown comes from Greg Sykes's Oct 2026 email to Exco
     * (the magazine's announcement was a flat list). Roles come from the
     * magazine captions ("Tour Team Captain" → Glen Clark; "PR22 Italy
     * Tour Team Manager" → Greg Sykes).
     *
     * Names here match the SAPRF membership roll (DB spellings), not the
     * magazine spellings, so strict name resolution succeeds without
     * leaning on SAPRF_HINTS:
     *
     *   - "Gregory Sykes" (magazine) → "Greg Sykes" (DB, #83)
     *   - "Corné van As"  (magazine) → "Cornelis van As" (DB, #1175)
     *
     * `role` defaults to shooter. Glen Clark gets captain. Greg Sykes
     * gets TWO rows: one shooter row in Open and one manager row with
     * no shooting division — he travelled in both capacities.
     *
     * Row count: 5 Open + 4 Senior + 2 Factory + 1 Ladies + 1 Greg-as-
     * manager = 13 appearance rows for 12 real people.
     */
    private const ROSTER = [
        // Open (5) — shooters.
        ['name' => 'Warren Britnell',       'role' => NationalTeamAppearance::ROLE_SHOOTER, 'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Jason Marais',          'role' => NationalTeamAppearance::ROLE_SHOOTER, 'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Greg Sykes',            'role' => NationalTeamAppearance::ROLE_SHOOTER, 'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Francois Stoltz',       'role' => NationalTeamAppearance::ROLE_SHOOTER, 'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Cornelis van As',       'role' => NationalTeamAppearance::ROLE_SHOOTER, 'division_slug' => 'open',    'division_label' => 'Open'],

        // Senior (4) — Glen Clark is Tour Team Captain per the magazine.
        ['name' => 'Glen Clark',            'role' => NationalTeamAppearance::ROLE_CAPTAIN, 'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Andries Lategan',       'role' => NationalTeamAppearance::ROLE_SHOOTER, 'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Le Riche Coetzer',      'role' => NationalTeamAppearance::ROLE_SHOOTER, 'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Grant Anderson',        'role' => NationalTeamAppearance::ROLE_SHOOTER, 'division_slug' => 'senior',  'division_label' => 'Seniors'],

        // Factory (2) — shooters.
        ['name' => 'Desmond Wellen',        'role' => NationalTeamAppearance::ROLE_SHOOTER, 'division_slug' => 'factory', 'division_label' => 'Factory'],
        ['name' => 'Alwyn van Graan',       'role' => NationalTeamAppearance::ROLE_SHOOTER, 'division_slug' => 'factory', 'division_label' => 'Factory'],

        // Ladies (1) — shooter.
        ['name' => 'Cheryl Wellen',         'role' => NationalTeamAppearance::ROLE_SHOOTER, 'division_slug' => 'ladies',  'division_label' => 'Ladies'],

        // Greg Sykes's manager row — the idempotency guard includes
        // role so this row and his Open-shooter row above are both
        // written on first run and both skipped on subsequent runs.
        ['name' => 'Greg Sykes',            'role' => NationalTeamAppearance::ROLE_MANAGER, 'division_slug' => null,      'division_label' => 'Team Manager'],
    ];

    /**
     * Optional SAPRF-number overrides for roster rows whose names collide
     * or whose magazine spelling differs from the SAPRF membership roll.
     * Shared pins with the 2024/2025/2026 seeders are intentional — all
     * four seeders see the same real-world people and need the same hints.
     */
    private const SAPRF_HINTS = [
        // Magazine drops the "Snr" suffix; SAPRF membership is
        // "LeRiche Coetzer Snr", #16, Western Cape.
        'Le Riche Coetzer' => '16',

        // Greg Sykes — SAPRF #83, email 308wingreg@gmail.com. Pinned
        // defensively so his two rows (Open shooter + manager) both
        // resolve regardless of any future "Greg Sykes" collision.
        'Greg Sykes' => '83',

        // Cornelis van As — SAPRF #1175, email muisvanas@gmail.com.
        // The magazine wrote "Corné van As" but the SAPRF membership
        // is formal "Cornelis van As"; the ROSTER uses the DB spelling
        // so strict resolution works, and the hint is belt-and-braces.
        'Cornelis van As' => '1175',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== saprf:seed-national-team-2023 ===');
        $this->line('Championship:  '.self::CHAMPIONSHIP);
        $this->line('Discipline:    '.self::DISCIPLINE);
        $this->line('Host:          Italy');
        $this->line('Venue:         '.self::CITY);
        $this->line('Dates:         '.self::STARTS_ON.' → '.self::ENDS_ON);
        $this->line('Dry run:       '.($dryRun ? 'YES' : 'no'));
        $this->newLine();

        $divisionsBySlug = Division::all()->keyBy('slug');
        $recordedBy = User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))
            ->orderBy('id')
            ->value('id') ?? User::query()->min('id');

        // Find-or-create the shared NationalTeam header. The row may
        // already exist (ImportTeamSa2023PhotoCommand creates it to
        // attach the team-portrait album). firstOrCreate only writes
        // the second-arg attributes on insert, so when the row already
        // exists we backfill the missing metadata below.
        $team = NationalTeam::firstOrCreate(
            ['year' => self::YEAR, 'championship_name' => self::CHAMPIONSHIP],
            [
                'discipline' => self::DISCIPLINE,
                'host_country' => self::HOST_COUNTRY,
                'city' => self::CITY,
                'starts_on' => self::STARTS_ON,
                'ends_on' => self::ENDS_ON,
            ],
        );

        // Backfill championship metadata on a pre-existing row without
        // clobbering any venue/dates/city an admin may have already
        // tweaked via the UI.
        $team->fill([
            'discipline' => $team->discipline ?: self::DISCIPLINE,
            'host_country' => $team->host_country ?: self::HOST_COUNTRY,
            'city' => $team->city ?: self::CITY,
            'starts_on' => $team->starts_on ?: self::STARTS_ON,
            'ends_on' => $team->ends_on ?: self::ENDS_ON,
        ]);
        if ($team->isDirty() && ! $dryRun) {
            $team->save();
        }

        // Build an in-PHP name index so resolution works identically on
        // MySQL (prod) and SQLite (tests). Covers both strict and
        // punctuation + diacritic-stripped forms so "Corné van As"
        // matches a user stored as "Corne van As".
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
                $role = $entry['role'];

                // Idempotency guard — a row keyed on
                // (user, year, championship, role) already exists.
                // Role is part of the key so Gregory Sykes's two rows
                // (shooter + manager) each pass the guard independently.
                $existing = NationalTeamAppearance::query()
                    ->where('user_id', $user->id)
                    ->where('year', self::YEAR)
                    ->where('championship_name', self::CHAMPIONSHIP)
                    ->where('role', $role)
                    ->first();

                if ($existing !== null) {
                    $report['already_present']++;
                    $this->line("  SKIP        {$entry['name']} [{$role}] — appearance already recorded (id={$existing->id})");

                    continue;
                }

                $divisionId = $entry['division_slug']
                    ? $divisionsBySlug->get($entry['division_slug'])?->id
                    : null;

                $roleLabel = match ($role) {
                    NationalTeamAppearance::ROLE_CAPTAIN => '  (Captain)',
                    NationalTeamAppearance::ROLE_MANAGER => '  (Team Manager)',
                    default => '',
                };

                $this->line(sprintf(
                    '  CREATE      %-28s  div=%-14s%s',
                    $entry['name'],
                    $entry['division_label'],
                    $roleLabel,
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
                        // Protea Colours intentionally NOT awarded. Per
                        // Exco: this team just represented South Africa —
                        // no colours ceremony for the inaugural PR22 Worlds.
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
        $this->line('Colours awarded:    0   (no Protea Colours awarded for 2023 — team representation only)');

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

        // 3. Loose fallback: strip punctuation + diacritics on both sides
        // so "Corné van As" matches "Corne van As".
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
        // Transliterate common diacritics to ASCII so "Corné" matches
        // "Corne" via the loose index.
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
