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
     *
     * `is_manager` marks non-shooting support staff (team manager, coach,
     * armourer, etc). Their appearance is still recorded so the badge
     * shows next to their name — but `awarded_colours` is forced to
     * FALSE because Protea Colours are a shooter-only honour.
     */
    private const ROSTER = [
        // Open division (6) — Warren is captain (role=captain).
        ['name' => 'Warren Britnell',       'division_slug' => 'open',    'division_label' => 'Open',                'is_manager' => false, 'is_captain' => true],
        ['name' => 'Leon Goosen',           'division_slug' => 'open',    'division_label' => 'Open',                'is_manager' => false],
        ['name' => 'Ruan Zeeman',           'division_slug' => 'open',    'division_label' => 'Open',                'is_manager' => false],
        ['name' => 'Dennis van der Merwe',  'division_slug' => 'open',    'division_label' => 'Open',                'is_manager' => false],
        ['name' => 'Terblanche de Jager',   'division_slug' => 'open',    'division_label' => 'Open',                'is_manager' => false],
        ['name' => 'Marcel Steyn',          'division_slug' => 'open',    'division_label' => 'Open',                'is_manager' => false],

        // Ladies division (4) — "Eliza Mey" on the On Target Africa page
        // is "Aliza Mey" in the SAPRF membership roll; SAPRF_HINTS pins
        // the lookup by number so the magazine spelling still works.
        ['name' => 'Eliza Mey',             'division_slug' => 'ladies',  'division_label' => 'Ladies',              'is_manager' => false],
        ['name' => 'Monica le Roux',        'division_slug' => 'ladies',  'division_label' => 'Ladies',              'is_manager' => false],
        ['name' => 'Kim-Leigh Ferreira',    'division_slug' => 'ladies',  'division_label' => 'Ladies',              'is_manager' => false],
        ['name' => 'Natasha Britnell',      'division_slug' => 'ladies',  'division_label' => 'Ladies',              'is_manager' => false],

        // Seniors division (4)
        ['name' => 'Etienne de Waal',       'division_slug' => 'senior',  'division_label' => 'Seniors',             'is_manager' => false],
        ['name' => 'Andries Lategan',       'division_slug' => 'senior',  'division_label' => 'Seniors',             'is_manager' => false],
        ['name' => 'Le Riche Coetzer Snr',  'division_slug' => 'senior',  'division_label' => 'Seniors',             'is_manager' => false],
        ['name' => 'Trevor Graham',         'division_slug' => 'senior',  'division_label' => 'Seniors',             'is_manager' => false],

        // Juniors division (4)
        ['name' => 'Conner Britnell',       'division_slug' => 'junior',  'division_label' => 'Juniors',             'is_manager' => false],
        ['name' => 'Tinus Cronje',          'division_slug' => 'junior',  'division_label' => 'Juniors',             'is_manager' => false],
        ['name' => 'Johan Nel',             'division_slug' => 'junior',  'division_label' => 'Juniors',             'is_manager' => false],
        ['name' => 'Donovan Cook',          'division_slug' => 'junior',  'division_label' => 'Juniors',             'is_manager' => false],

        // Limited division (4)
        ['name' => 'Pieter Cronje',         'division_slug' => 'limited', 'division_label' => 'Limited',             'is_manager' => false],
        ['name' => 'Willem van Biljon',     'division_slug' => 'limited', 'division_label' => 'Limited',             'is_manager' => false],
        ['name' => 'Mohsin Tajbhai',        'division_slug' => 'limited', 'division_label' => 'Limited',             'is_manager' => false],
        ['name' => 'J.C. Robertson',        'division_slug' => 'limited', 'division_label' => 'Limited',             'is_manager' => false],

        // Factory division (4)
        ['name' => 'Russell Ferreira',      'division_slug' => 'factory', 'division_label' => 'Factory',             'is_manager' => false],
        ['name' => 'Hanno van Niekerk',     'division_slug' => 'factory', 'division_label' => 'Factory',             'is_manager' => false],
        ['name' => 'Chris Leeson',          'division_slug' => 'factory', 'division_label' => 'Factory',             'is_manager' => false],
        ['name' => 'Patrick Capes',         'division_slug' => 'factory', 'division_label' => 'Factory',             'is_manager' => false],

        // Team manager — non-shooting support role. No shooting division
        // and no Protea Colours (enforced below by is_manager).
        ['name' => 'Clive Mey',             'division_slug' => null,      'division_label' => 'Team Manager',        'is_manager' => true],
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
        // The On Target Africa team page lists "Eliza Mey", but her SAPRF
        // membership is in the name "Aliza Mey" (SAPRF #427, Gauteng).
        'Eliza Mey' => '427',

        // Two users share the name "Tinus Cronje" — the paid Mpumalanga
        // member (#1083) and a managed shell account auto-created from
        // a match entry. Pin the real one.
        'Tinus Cronje' => '1083',

        // Magazine spells it "Le Riche Coetzer Snr" (two words); the SAPRF
        // membership is "LeRiche Coetzer Snr" (one word), #16, Western Cape.
        'Le Riche Coetzer Snr' => '16',
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

        // Find-or-create the shared NationalTeam header so every row in
        // this roster links back to the same team-year record.
        $team = NationalTeam::firstOrCreate(
            ['year' => self::YEAR, 'championship_name' => self::CHAMPIONSHIP],
            [
                'discipline' => 'centrefire',
                'host_country' => self::HOST_COUNTRY,
                'city' => 'Lipan, Texas',
                'starts_on' => self::APPEARED_AT,
                'ends_on' => '2026-09-28',
            ],
        );

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

                // Team managers / non-shooting support staff never
                // receive Protea Colours. Everyone else gets colours
                // only when they don't already have a colours-awarding
                // row somewhere in their history.
                $isManager = ! empty($entry['is_manager']);
                $isCaptain = ! empty($entry['is_captain']);
                $role = $isManager
                    ? NationalTeamAppearance::ROLE_MANAGER
                    : ($isCaptain
                        ? NationalTeamAppearance::ROLE_CAPTAIN
                        : NationalTeamAppearance::ROLE_SHOOTER);
                $awardColours = ! $isManager && ! $user->hasProteaColours();

                // Use ->get() rather than array access so a missing slug
                // (e.g. seeded DB that hasn't yet run DivisionCategorySeeder)
                // yields null rather than an "undefined array key" error.
                $divisionId = $entry['division_slug']
                    ? $divisionsBySlug->get($entry['division_slug'])?->id
                    : null;

                if ($isManager) {
                    $noteSuffix = '(team manager — no colours)';
                    $label = 'CREATE-M';
                } elseif ($isCaptain) {
                    $noteSuffix = $awardColours ? '(captain + Protea Colours)' : '(captain)';
                    $label = 'CREATE+K';
                } elseif ($awardColours) {
                    $noteSuffix = '(awards Protea Colours)';
                    $label = 'CREATE+C';
                } else {
                    $noteSuffix = '(already has colours)';
                    $label = 'CREATE';
                }

                $this->line(sprintf(
                    '  %-11s %-28s  div=%-8s  %s',
                    $label,
                    $entry['name'],
                    $entry['division_label'],
                    $noteSuffix,
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
