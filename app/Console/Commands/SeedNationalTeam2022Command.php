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
 * Seed the 2022 inaugural IPRF World Championship (Centrefire) SA team
 * into the national_team_appearances table.
 *
 * Championship context — the first-ever IPRF World Championship, hosted
 * by France in Bitche, 6–12 August 2022. 21 team members across five
 * divisions.
 *
 * Source roster: the On Target Africa magazine "Protea Colours" article
 * by Grant Anderson (SAPRF Chairperson), June/July 2022, Issue 38, plus
 * Greg Sykes's Oct 2026 email to Exco pinning the per-division layout of
 * the shooters the magazine listed only in the SASSCo-only group.
 *
 *   - `awarded_colours` is forced to FALSE on every row. This seeder
 *     records WHO represented South Africa in 2022 — the Protea Colours
 *     award is a separate SASCOC decision that Exco maintains via the
 *     admin UI (NationalTeamAppearanceController "promote on toggle"
 *     path). Same convention as the 2023, 2024, 2025 and 2026 seeders.
 *
 *   - Divisions follow Greg's email: Open (6), Ladies (3), Senior (4+1),
 *     Factory (2+1), Limited (4). Glen Clark and Russell Ferreira were
 *     the two names Greg didn't call out — they're placed in Senior and
 *     Factory respectively based on their 2024–2026 team records, and
 *     admin can move them via the UI if a more authoritative source
 *     turns up.
 *
 * Shooter resolution order (identical to the 2023/2024/2025/2026 seeders):
 *   1. SAPRF number from `SAPRF_HINTS` if the roster name needs pinning.
 *   2. Case-insensitive, whitespace-collapsed name match on users.name.
 *   3. Loose fallback that strips punctuation + diacritics on both sides
 *      so "Konrad Gräbe" matches "Konrad Grabe" and vice versa.
 *   4. If a name resolves to 2+ users, the row is reported and skipped
 *      so a human can pin it with the SAPRF hint.
 *
 * Idempotent: re-running does not create duplicate rows (match keyed on
 * user + year + championship), so Exco can safely rerun after fixing
 * unresolved names or after adding SAPRF hints for ambiguous matches.
 */
class SeedNationalTeam2022Command extends Command
{
    protected $signature = 'saprf:seed-national-team-2022
        {--dry-run : Report what would change without writing}';

    protected $description = 'Seed the 2022 inaugural IPRF Centrefire World Championship SA team (Bitche, France) into national_team_appearances';

    private const YEAR = 2022;

    private const CHAMPIONSHIP = '2022 IPRF World Championship (Centrefire)';

    private const DISCIPLINE = 'centrefire';

    private const HOST_COUNTRY = 'FR';

    private const CITY = 'Bitche';

    private const STARTS_ON = '2022-08-06';

    private const ENDS_ON = '2022-08-12';

    /** First day of competition — anchor for the per-appearance date. */
    private const APPEARED_AT = self::STARTS_ON;

    /**
     * Division layout per Greg Sykes's Oct 2026 email, cross-referenced
     * against the On Target Africa magazine. Every row is `role=shooter`
     * and `awarded_colours=false` — this seeder is strictly a historical
     * record of who represented South Africa at the 2022 Championship.
     * Protea Colours awards are an admin-UI concern.
     *
     * Glen Clark and Russell Ferreira are placed in Senior and Factory
     * respectively — a best guess from their 2024–2026 team records,
     * since Greg's email didn't call out their 2022 division.
     */
    private const ROSTER = [
        // Open (6)
        ['name' => 'Warren Britnell',       'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Garth Olivier',         'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Justin Louw',           'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Johan Lottering',       'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Hendrik Nel',           'division_slug' => 'open',    'division_label' => 'Open'],
        ['name' => 'Jason Marais',          'division_slug' => 'open',    'division_label' => 'Open'],

        // Ladies (3)
        ['name' => 'Liesl Baben',           'division_slug' => 'ladies',  'division_label' => 'Ladies'],
        ['name' => 'Natasha Britnell',      'division_slug' => 'ladies',  'division_label' => 'Ladies'],
        ['name' => 'Kim-Leigh Ferreira',    'division_slug' => 'ladies',  'division_label' => 'Ladies'],

        // Senior (5) — includes Glen Clark (best-guess division; Greg
        // didn't call out Glen's 2022 division in his email).
        ['name' => 'Grant Anderson',        'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Le Riche Coetzer',      'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Andries Lategan',       'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Derek Reyneke',         'division_slug' => 'senior',  'division_label' => 'Seniors'],
        ['name' => 'Glen Clark',            'division_slug' => 'senior',  'division_label' => 'Seniors'],

        // Factory (3) — includes Russell Ferreira (best-guess division;
        // Greg didn't call out Russell's 2022 division in his email).
        ['name' => 'Perry De Gouveia',      'division_slug' => 'factory', 'division_label' => 'Factory'],
        ['name' => 'Rob Ramsden',           'division_slug' => 'factory', 'division_label' => 'Factory'],
        ['name' => 'Russell Ferreira',      'division_slug' => 'factory', 'division_label' => 'Factory'],

        // Limited (4)
        ['name' => 'Greg Sykes',            'division_slug' => 'limited', 'division_label' => 'Limited'],
        ['name' => 'Konrad Gräbe',          'division_slug' => 'limited', 'division_label' => 'Limited'],
        ['name' => 'Willem van Biljon',     'division_slug' => 'limited', 'division_label' => 'Limited'],
        ['name' => 'Arnold Van Zyl',        'division_slug' => 'limited', 'division_label' => 'Limited'],
    ];

    /**
     * Optional SAPRF-number overrides for roster rows whose names collide
     * or whose magazine spelling differs from the SAPRF membership roll.
     * Shared pins with the 2023/2024/2025/2026 seeders are intentional —
     * all five seeders see the same real-world people and need the same
     * hints.
     */
    private const SAPRF_HINTS = [
        // Magazine drops the "Snr" suffix; SAPRF membership is
        // "LeRiche Coetzer Snr", #16, Western Cape.
        'Le Riche Coetzer' => '16',

        // Greg Sykes — SAPRF #83, email 308wingreg@gmail.com. The hint
        // is belt-and-braces: his DB name is "Greg Sykes" so strict
        // matching already works, but the hint protects against any
        // future namespace collision with a different "Greg Sykes".
        'Greg Sykes' => '83',

        // Perry De Gouveia — TODO: add SAPRF number once Exco confirms
        // whether Perry's user account exists in the system (he does
        // not resolve as of Oct 2026). Until then this row logs as
        // UNRESOLVED on each run.
        // 'Perry De Gouveia' => '???',

        // Rob Ramsden — TODO: as above. Rob is the SAPRF founder (per
        // the June/July 2022 Chairperson's article) so a membership
        // likely exists; just needs pinning by number.
        // 'Rob Ramsden' => '???',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== saprf:seed-national-team-2022 ===');
        $this->line('Championship:  '.self::CHAMPIONSHIP);
        $this->line('Discipline:    '.self::DISCIPLINE);
        $this->line('Host:          France');
        $this->line('Venue:         '.self::CITY);
        $this->line('Dates:         '.self::STARTS_ON.' → '.self::ENDS_ON);
        $this->line('Dry run:       '.($dryRun ? 'YES' : 'no'));
        $this->newLine();

        $divisionsBySlug = Division::all()->keyBy('slug');
        $recordedBy = User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))
            ->orderBy('id')
            ->value('id') ?? User::query()->min('id');

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

        // Backfill missing metadata on an already-existing row without
        // clobbering admin-UI tweaks.
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
        // punctuation + diacritic-stripped forms so "Konrad Gräbe"
        // matches a user stored as "Konrad Grabe".
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
                    '  CREATE      %-28s  div=%s',
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
                        // Protea Colours intentionally NOT awarded. This
                        // seeder records WHO represented SA at the 2022
                        // Worlds — Protea Colours awards are an Exco
                        // decision maintained via the admin UI.
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
        $this->line('Colours awarded:    0   (team representation only — award separately via admin UI)');

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
        // so "Konrad Gräbe" matches "Konrad Grabe".
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
        // Transliterate common diacritics to ASCII so "Gräbe" matches
        // "Grabe" via the loose index.
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
