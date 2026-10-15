<?php

namespace App\Console\Commands;

use App\Models\Division;
use App\Models\Membership;
use App\Models\NationalTeamAppearance;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seed the 2026 IPRF World Championship (Centrefire) roster into the
 * national_team_appearances table.
 *
 * Championship context — Gravestone Precision Shooting, Weatherford, Texas,
 * USA, 23–28 September 2026. 26 shooters across six divisions, plus Clive
 * Mey as team manager.
 *
 * The command is idempotent: re-running it does not create duplicate rows
 * (match keyed on user + year + championship), so Exco can safely rerun
 * after fixing any unresolved names.
 *
 * Shooter resolution order (mirrors NationalTeamAppearanceController):
 *   1. SAPRF number from `saprf_hint` if set.
 *   2. Case-insensitive, whitespace-collapsed name match on users.name.
 *   3. If a name resolves to 2+ users, the row is reported and skipped
 *      so a human can pin it with the SAPRF hint.
 *
 * `awarded_colours` is set to TRUE only when the matched user has no
 * existing colours-awarding appearance. This preserves the "at most one
 * colours row per user" invariant and lets later backfills of 2022 / 2024
 * promote colours to the correct earlier year via the admin UI
 * (NationalTeamAppearanceController::destroy auto-promotes on delete).
 */
class SeedNationalTeam2026Command extends Command
{
    protected $signature = 'saprf:seed-national-team-2026
        {--dry-run : Report what would change without writing}';

    protected $description = 'Seed the 2026 IPRF Centrefire World Championship SA team into national_team_appearances';

    private const YEAR = 2026;

    private const CHAMPIONSHIP = '2026 IPRF World Championship (Centrefire)';

    private const HOST_COUNTRY = 'US';

    private const APPEARED_AT = '2026-09-23';

    /**
     * Division label resolution — image shows the division in parentheses
     * after each name. The seeder tries to attach the matching Division row
     * (so standings grouping works) and falls back to the free-text
     * division_label when the division slug doesn't exist (e.g. "Team Manager").
     *
     * The label stored in `division_label` is always the free-text form
     * from the image, so the admin index and public roster can show
     * "Open — Team Captain" / "Team Manager" even when a Division row is
     * also attached.
     */
    private const ROSTER = [
        // Open division (6)
        ['name' => 'Warren Britnell',       'division_slug' => 'open',    'division_label' => 'Open — Team Captain'],
        ['name' => 'Leon Goosen',           'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Ruan Zeeman',           'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Dennis van der Merwe',  'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Terblanche de Jager',   'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Marcel Steyn',          'division_slug' => 'open',    'division_label' => 'Open'],

        // Ladies division (4)
        ['name' => 'Eliza Mey',             'division_slug' => 'ladies',  'division_label' => 'Ladies'],
        ['name' => 'Monica le Roux',        'division_slug' => 'ladies',  'division_label' => 'Ladies'],
        ['name' => 'Kim-Leigh Ferreira',    'division_slug' => 'ladies',  'division_label' => 'Ladies'],
        ['name' => 'Natasha Britnell',      'division_slug' => 'ladies',  'division_label' => 'Ladies'],

        // Seniors division (4)
        ['name' => 'Etienne de Waal',       'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Andries Lategan',       'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Le Riche Coetzer Snr',  'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Trevor Graham',         'division_slug' => 'senior',  'division_label' => 'Seniors'],

        // Juniors division (4)
        ['name' => 'Conner Britnell',       'division_slug' => 'junior',  'division_label' => 'Juniors'],
        ['name' => 'Tinus Cronje',          'division_slug' => 'junior',  'division_label' => 'Juniors'],
        ['name' => 'Johan Nel',             'division_slug' => 'junior',  'division_label' => 'Juniors'],
        ['name' => 'Donovan Cook',          'division_slug' => 'junior',  'division_label' => 'Juniors'],

        // Limited division (4)
        ['name' => 'Pieter Cronje',         'division_slug' => 'limited', 'division_label' => 'Limited'],
        ['name' => 'Willem van Biljon',     'division_slug' => 'limited', 'division_label' => 'Limited'],
        ['name' => 'Mohsin Tajbhai',        'division_slug' => 'limited', 'division_label' => 'Limited'],
        ['name' => 'J.C. Robertson',        'division_slug' => 'limited', 'division_label' => 'Limited'],

        // Factory division (4)
        ['name' => 'Russell Ferreira',      'division_slug' => 'factory', 'division_label' => 'Factory'],
        ['name' => 'Hanno van Niekerk',     'division_slug' => 'factory', 'division_label' => 'Factory'],
        ['name' => 'Chris Leeson',          'division_slug' => 'factory', 'division_label' => 'Factory'],
        ['name' => 'Patrick Capes',         'division_slug' => 'factory', 'division_label' => 'Factory'],

        // Team manager — no shooting division, kept as free-text label.
        ['name' => 'Clive Mey',             'division_slug' => null,      'division_label' => 'Team Manager'],
    ];

    /**
     * Optional SAPRF-number overrides for roster rows whose names collide
     * (e.g. a father and son with the same surname, or shooters whose
     * name on the On Target Africa team page differs from how it's
     * recorded in SAPRF memberships). Add rows here keyed on the roster
     * `name` to pin the resolution without renaming users in the DB.
     *
     * Example:
     *   'J.C. Robertson' => '12345',
     */
    private const SAPRF_HINTS = [
        // Populate after a first dry-run if ambiguous names show up.
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== saprf:seed-national-team-2026 ===');
        $this->line('Championship:  '.self::CHAMPIONSHIP);
        $this->line('Host:          United States');
        $this->line('Appeared at:   '.self::APPEARED_AT);
        $this->line('Dry run:       '.($dryRun ? 'YES' : 'no'));
        $this->newLine();

        $divisionsBySlug = Division::all()->keyBy('slug');
        $recordedBy = User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))
            ->orderBy('id')
            ->value('id') ?? User::query()->min('id');

        // Build an in-PHP name index so resolution works identically on
        // MySQL (prod) and SQLite (tests). Covers both strict and
        // punctuation-stripped forms so "J.C. Robertson" matches
        // "JC Robertson" and vice versa.
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
            'colours_awarded' => 0,
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

                $awardColours = ! $user->hasProteaColours();

                // Use ->get() rather than array access so a missing slug
                // (e.g. seeded DB that hasn't yet run DivisionCategorySeeder)
                // yields null rather than an "undefined array key" error.
                $divisionId = $entry['division_slug']
                    ? $divisionsBySlug->get($entry['division_slug'])?->id
                    : null;

                $this->line(sprintf(
                    '  %-11s %-28s  div=%-8s  %s',
                    $awardColours ? 'CREATE+C' : 'CREATE',
                    $entry['name'],
                    $entry['division_label'],
                    $awardColours ? '(awards Protea Colours)' : '(already has colours)',
                ));

                if (! $dryRun) {
                    NationalTeamAppearance::create([
                        'user_id' => $user->id,
                        'year' => self::YEAR,
                        'division_id' => $divisionId,
                        'division_label' => $entry['division_label'],
                        'championship_name' => self::CHAMPIONSHIP,
                        'host_country' => self::HOST_COUNTRY,
                        'placing' => null,
                        'selection_cycle_id' => null,
                        'awarded_colours' => $awardColours,
                        'appeared_at' => Carbon::parse(self::APPEARED_AT),
                        'recorded_by' => $recordedBy,
                        'notes' => null,
                    ]);
                }

                $report['created']++;
                if ($awardColours) {
                    $report['colours_awarded']++;
                }
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
        $this->line('Colours awarded:    '.$report['colours_awarded']);
        $this->line('Already present:    '.$report['already_present']);
        $this->line('Unresolved names:   '.count($report['unresolved']));
        $this->line('Ambiguous names:    '.count($report['ambiguous']));

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

        // 2. Strict normalisation: lowercase + collapse whitespace. Keeps
        // punctuation so "Le Riche Coetzer Snr" doesn't accidentally match
        // some other "Le Riche" who happens to share part of the string.
        $strict = self::normaliseStrict($name);
        $matches = $usersByStrict[$strict] ?? [];

        // 3. Loose fallback: strip punctuation on both sides for cases like
        // "J.C. Robertson" vs "JC Robertson", or "Le Riche Coetzer Snr." vs
        // "Le Riche Coetzer Snr".
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
