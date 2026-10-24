<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\StandingsCalculationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Realistic local-only demo data for the public standings pages. All
 * shooter names are prefixed with "PW" (per the test-data convention) and
 * none are real humans. The seeder is idempotent and safe to re-run — it
 * does not touch the real `FederationDemoSeeder` data.
 *
 * What the seeder sets up (2026 season):
 *   - 24 PW-prefix shooters spread across every active division
 *     (Open, Factory, Limited, Production, Ladies, Junior, Senior) and
 *     six provinces (GP, WC, FS, KZN, EC, MP).
 *   - 3 completed PRS national matches (one 2-day), 2 completed PR22
 *     national matches, 2 completed PRS provincials, 2 completed PR22
 *     provincials — enough to populate every surface the standings page
 *     renders (podium, division leaders, movement arrows after a
 *     snapshot backfill).
 *   - 1 match on today (status=open), 3 upcoming matches (status=open)
 *     so the "In progress", "Remaining" and "Still to shoot" cards all
 *     have realistic numbers.
 *   - A deliberate tie on PRS national (shooters #1 and #2 finish on
 *     identical totals) so the T1 chip renders on the public page.
 *
 * Run with:
 *   php artisan db:seed --class=StandingsDemoSeeder
 */
class StandingsDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $divisions = Division::query()->get()->keyBy('slug');
            $provinces = Province::query()->get()->keyBy('abbreviation');

            if ($divisions->isEmpty() || $provinces->isEmpty()) {
                $this->command?->warn('StandingsDemoSeeder: divisions or provinces missing — run DivisionCategorySeeder and ProvinceSeeder first.');
                return;
            }

            $password = Hash::make('password');
            $creator = User::where('email', 'owner@saprf.co.za')->first()
                ?? User::query()->whereNotNull('id')->orderBy('id')->first();

            // Shooter roster — 24 PW-prefix members spread across every
            // division and six provinces. The "pair" column marks the two
            // shooters that end up tied on PRS national (see score offsets
            // below): both finish on identical day totals so T1 renders.
            $roster = [
                // ── Open ──
                ['name' => 'PW Open Alpha',     'prov' => 'GP',  'div' => 'open',       'dob' => '1985-04-01', 'pair' => null],
                ['name' => 'PW Open Bravo',     'prov' => 'WC',  'div' => 'open',       'dob' => '1982-07-12', 'pair' => 'tie'],
                ['name' => 'PW Open Charlie',   'prov' => 'KZN', 'div' => 'open',       'dob' => '1988-11-23', 'pair' => 'tie'],
                ['name' => 'PW Open Delta',     'prov' => 'FS',  'div' => 'open',       'dob' => '1983-01-30', 'pair' => null],
                ['name' => 'PW Open Echo',      'prov' => 'EC',  'div' => 'open',       'dob' => '1990-02-14', 'pair' => null],

                // ── Factory ──
                ['name' => 'PW Factory Alpha',  'prov' => 'GP',  'div' => 'factory',    'dob' => '1984-05-05', 'pair' => null],
                ['name' => 'PW Factory Bravo',  'prov' => 'WC',  'div' => 'factory',    'dob' => '1986-09-19', 'pair' => null],
                ['name' => 'PW Factory Charlie','prov' => 'MP',  'div' => 'factory',    'dob' => '1987-12-06', 'pair' => null],

                // ── Limited ──
                ['name' => 'PW Limited Alpha',  'prov' => 'GP',  'div' => 'limited',    'dob' => '1981-03-18', 'pair' => null],
                ['name' => 'PW Limited Bravo',  'prov' => 'FS',  'div' => 'limited',    'dob' => '1989-08-11', 'pair' => null],

                // ── Production ──
                ['name' => 'PW Production Alpha','prov' => 'KZN','div' => 'production', 'dob' => '1986-06-02', 'pair' => null],
                ['name' => 'PW Production Bravo','prov' => 'WC', 'div' => 'production', 'dob' => '1991-10-25', 'pair' => null],

                // ── Ladies ──
                ['name' => 'PW Ladies Alpha',   'prov' => 'GP',  'div' => 'ladies',     'dob' => '1990-04-07', 'pair' => null],
                ['name' => 'PW Ladies Bravo',   'prov' => 'WC',  'div' => 'ladies',     'dob' => '1992-11-16', 'pair' => null],
                ['name' => 'PW Ladies Charlie', 'prov' => 'EC',  'div' => 'ladies',     'dob' => '1988-01-22', 'pair' => null],

                // ── Junior ──
                ['name' => 'PW Junior Alpha',   'prov' => 'GP',  'div' => 'junior',     'dob' => '2008-05-13', 'pair' => null],
                ['name' => 'PW Junior Bravo',   'prov' => 'KZN', 'div' => 'junior',     'dob' => '2007-09-04', 'pair' => null],

                // ── Senior ──
                ['name' => 'PW Senior Alpha',   'prov' => 'GP',  'div' => 'senior',     'dob' => '1962-02-11', 'pair' => null],
                ['name' => 'PW Senior Bravo',   'prov' => 'WC',  'div' => 'senior',     'dob' => '1965-07-28', 'pair' => null],
                ['name' => 'PW Senior Charlie', 'prov' => 'FS',  'div' => 'senior',     'dob' => '1968-12-09', 'pair' => null],
                ['name' => 'PW Senior Delta',   'prov' => 'MP',  'div' => 'senior',     'dob' => '1970-05-17', 'pair' => null],
                ['name' => 'PW Senior Echo',    'prov' => 'EC',  'div' => 'senior',     'dob' => '1967-10-03', 'pair' => null],

                // ── Open filler so "Show all" trips the 10-row cutoff ──
                ['name' => 'PW Open Foxtrot',   'prov' => 'MP',  'div' => 'open',       'dob' => '1993-03-21', 'pair' => null],
                ['name' => 'PW Open Golf',      'prov' => 'FS',  'div' => 'open',       'dob' => '1979-08-02', 'pair' => null],
            ];

            $shooters = [];
            foreach ($roster as $row) {
                $prov = $provinces[$row['prov']] ?? null;
                $div = $divisions[$row['div']] ?? null;
                if (! $prov || ! $div) {
                    continue;
                }

                $email = 'pw.'.str_replace(' ', '.', strtolower($row['name'])).'@example.co.za';
                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $row['name'],
                        'password' => $password,
                        'province_id' => $prov->id,
                        'division_id' => $div->id,
                        'date_of_birth' => $row['dob'],
                        'email_verified_at' => now(),
                    ],
                );

                // Make sure existing PW demo accounts have their province/
                // division patched if the seeder is re-run after a schema
                // change. User-level attrs are what the standings page reads.
                $user->forceFill([
                    'province_id' => $prov->id,
                    'division_id' => $div->id,
                ])->save();

                Membership::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'saprf_number' => 'PW-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
                        'membership_type' => 'paid',
                        'status' => 'active',
                        'payment_status' => 'paid',
                        'start_date' => now()->subMonths(2)->toDateString(),
                        'expiry_date' => now()->addMonths(10)->toDateString(),
                    ],
                );

                $shooters[] = [
                    'user' => $user,
                    'division_id' => $div->id,
                    'div_slug' => $row['div'],
                    'pair' => $row['pair'],
                ];
            }

            $season = '2026';
            $today = Carbon::today();

            // Match plan. The seeder deliberately drops a match on today
            // so the "In progress" counter is non-zero and the user can
            // visually confirm the three-bucket sum identity.
            $matchPlan = [
                // ── PRS Nationals (completed) ──
                ['name' => 'PW Demo PRS National Jan GP',   'type' => 'PRS',  'level' => 'national',   'prov' => 'GP',  'date' => $today->copy()->subMonths(9)->toDateString(), 'end' => $today->copy()->subMonths(9)->addDay()->toDateString(), 'status' => 'completed', 'tie' => true],
                ['name' => 'PW Demo PRS National Mar WC',   'type' => 'PRS',  'level' => 'national',   'prov' => 'WC',  'date' => $today->copy()->subMonths(7)->toDateString(), 'end' => null,                                                     'status' => 'completed', 'tie' => false],
                ['name' => 'PW Demo PRS National Jun FS',   'type' => 'PRS',  'level' => 'national',   'prov' => 'FS',  'date' => $today->copy()->subMonths(4)->toDateString(), 'end' => null,                                                     'status' => 'completed', 'tie' => false],

                // ── PR22 Nationals (completed) ──
                ['name' => 'PW Demo PR22 National Feb GP',  'type' => 'PR22', 'level' => 'national',   'prov' => 'GP',  'date' => $today->copy()->subMonths(8)->toDateString(), 'end' => $today->copy()->subMonths(8)->addDay()->toDateString(), 'status' => 'completed', 'tie' => false],
                ['name' => 'PW Demo PR22 National Apr WC',  'type' => 'PR22', 'level' => 'national',   'prov' => 'WC',  'date' => $today->copy()->subMonths(6)->toDateString(), 'end' => null,                                                     'status' => 'completed', 'tie' => false],

                // ── Provincial (completed) ──
                ['name' => 'PW Demo PRS Provincial GP',     'type' => 'PRS',  'level' => 'provincial', 'prov' => 'GP',  'date' => $today->copy()->subMonths(5)->toDateString(), 'end' => null, 'status' => 'completed', 'tie' => false],
                ['name' => 'PW Demo PRS Provincial KZN',    'type' => 'PRS',  'level' => 'provincial', 'prov' => 'KZN', 'date' => $today->copy()->subMonths(3)->toDateString(), 'end' => null, 'status' => 'completed', 'tie' => false],
                ['name' => 'PW Demo PR22 Provincial WC',    'type' => 'PR22', 'level' => 'provincial', 'prov' => 'WC',  'date' => $today->copy()->subMonths(2)->toDateString(), 'end' => null, 'status' => 'completed', 'tie' => false],
                ['name' => 'PW Demo PR22 Provincial FS',    'type' => 'PR22', 'level' => 'provincial', 'prov' => 'FS',  'date' => $today->copy()->subMonths(1)->toDateString(), 'end' => null, 'status' => 'completed', 'tie' => false],

                // ── TODAY (in progress) — the critical "match on today" case ──
                ['name' => 'PW Demo PRS National TODAY',    'type' => 'PRS',  'level' => 'national',   'prov' => 'MP',  'date' => $today->toDateString(),                       'end' => null, 'status' => 'open',      'tie' => false],

                // ── Upcoming (still to shoot) ──
                ['name' => 'PW Demo PRS National Next',     'type' => 'PRS',  'level' => 'national',   'prov' => 'LP',  'date' => $today->copy()->addDays(14)->toDateString(),  'end' => null, 'status' => 'open',      'tie' => false],
                ['name' => 'PW Demo PR22 National Next',    'type' => 'PR22', 'level' => 'national',   'prov' => 'GP',  'date' => $today->copy()->addDays(21)->toDateString(),  'end' => null, 'status' => 'open',      'tie' => false],
                ['name' => 'PW Demo PRS Provincial Next',   'type' => 'PRS',  'level' => 'provincial', 'prov' => 'EC',  'date' => $today->copy()->addDays(28)->toDateString(),  'end' => null, 'status' => 'open',      'tie' => false],
            ];

            $completedMatches = [];
            foreach ($matchPlan as $data) {
                $prov = $provinces[$data['prov']] ?? null;
                if (! $prov) {
                    continue;
                }

                $match = MatchEvent::updateOrCreate(
                    ['name' => $data['name']],
                    [
                        'match_type' => $data['type'],
                        'series' => $data['type'],
                        'series_level' => $data['level'],
                        'season' => $season,
                        'province_id' => $prov->id,
                        'venue_name' => 'PW Demo Range',
                        'venue_location' => $prov->name.' Demo Site',
                        'city' => $prov->name,
                        'match_date' => $data['date'],
                        'match_end_date' => $data['end'],
                        'registration_open_date' => Carbon::parse($data['date'])->subDays(30)->toDateString(),
                        'registration_close_date' => Carbon::parse($data['date'])->subDays(2)->toDateString(),
                        'status' => $data['status'],
                        'created_by' => $creator?->id,
                        'active_member_fee' => $data['level'] === 'provincial' ? 350.00 : 500.00,
                        'non_member_fee' => $data['level'] === 'provincial' ? 600.00 : 750.00,
                        'lapsed_member_fee' => $data['level'] === 'provincial' ? 500.00 : 650.00,
                        'max_competitors' => 40,
                        'published' => true,
                        'also_counts_for_provincial' => $data['level'] === 'national',
                    ],
                );

                $match->divisions()->syncWithoutDetaching($divisions->pluck('id'));

                if ($data['status'] === 'completed') {
                    $completedMatches[] = ['match' => $match, 'tie' => $data['tie']];
                }
            }

            // Qualification rules — updateOrCreate so a half-populated row
            // from an earlier seed run gets fully patched. A missing
            // weight_pct silently zeroes the whole pool contribution in
            // StandingsCalculationService::aggregateWeightedPools, which
            // was the root cause of the PR22 national = 0.0 bug reported
            // in the QA audit.
            QualificationRule::updateOrCreate(
                ['series' => 'PRS', 'season' => $season],
                [
                    'scoring_mode' => 'best_n_plus_champs',
                    'min_out_of_province_matches' => 0,
                    'best_of_count' => 3,
                    'total_qualifying_matches' => 4,
                    'created_by' => $creator?->id,
                ],
            );
            QualificationRule::updateOrCreate(
                ['series' => 'PR22', 'season' => $season],
                [
                    'scoring_mode' => 'weighted_pools',
                    'min_out_of_province_matches' => 1,
                    'total_qualifying_matches' => 6,
                    'provincial_pool_best_of' => 3,
                    'provincial_pool_weight_pct' => 30.00,
                    'national_pool_best_of' => 2,
                    'national_pool_weight_pct' => 40.00,
                    'national_pool_min_matches' => 1,
                    'champs_pool_best_of' => 1,
                    'champs_pool_weight_pct' => 30.00,
                    'created_by' => $creator?->id,
                ],
            );

            $standingsService = app(StandingsCalculationService::class);

            // Clean out any pre-existing PW demo scores so the seeder is
            // idempotent — otherwise re-running stacks duplicate scores
            // and the sum breaks.
            foreach ($completedMatches as $entry) {
                Score::where('match_id', $entry['match']->id)
                    ->whereIn('user_id', collect($shooters)->pluck('user.id'))
                    ->delete();
            }

            foreach ($completedMatches as $entry) {
                /** @var MatchEvent $match */
                $match = $entry['match'];
                $isTie = $entry['tie'];
                $isTwoDay = $match->isMultiDay();

                foreach ($shooters as $idx => $shooter) {
                    /** @var User $user */
                    $user = $shooter['user'];

                    // Base profile: top shooters score high, bottom low.
                    // Discipline-specific jitter so PRS and PR22 rankings
                    // don't look identical.
                    $base = 92 - ($idx * 2.5);
                    $jitter = (($match->id * 17 + $user->id * 11) % 7) - 3;
                    $raw = max(10, min(100, $base + $jitter));

                    // Deliberate PRS tie: the two shooters marked `pair =>
                    // 'tie'` get identical scores on EVERY PRS national,
                    // so after best_n_plus_champs normalisation they land
                    // on the same /400 total and the public page renders
                    // T1 on both rows. The tie-flag is still honoured so
                    // seeded single-match PBs match the podium story.
                    if ($shooter['pair'] === 'tie' && $match->match_type === 'PRS' && $match->series_level === 'national') {
                        $raw = 88.5;
                    }

                    if ($isTwoDay) {
                        $day1 = round(($raw / 2) + (($match->id + $idx) % 3) / 10, 1);
                        $day2 = round(($raw / 2) - (($match->id + $idx) % 3) / 10, 1);

                        Score::create([
                            'match_id' => $match->id,
                            'shooter_name' => $user->name,
                            'user_id' => $user->id,
                            'day1_raw_score' => $day1,
                            'day2_raw_score' => $day2,
                            'placement' => null,
                            'division_id' => $shooter['division_id'],
                            'is_member' => true,
                            'status' => 'valid',
                            'match_date' => $match->match_date,
                            'counts_for_season' => true,
                        ]);
                    } else {
                        Score::create([
                            'match_id' => $match->id,
                            'shooter_name' => $user->name,
                            'user_id' => $user->id,
                            'day1_raw_score' => $raw,
                            'placement' => null,
                            'division_id' => $shooter['division_id'],
                            'is_member' => true,
                            'status' => 'valid',
                            'match_date' => $match->match_date,
                            'counts_for_season' => true,
                        ]);
                    }
                }

                // Finalise the match so the standings calc + snapshot hook
                // both fire. If an admin later edits a score the service
                // will handle the recalc + re-snapshot on its own.
                $match->forceFill([
                    'status' => 'completed',
                    'scores_finalized_at' => $match->match_date,
                ])->save();

                $standingsService->recalculateForMatch($match->fresh());
            }

            $this->command?->info('StandingsDemoSeeder: '.count($shooters).' PW shooters, '.count($matchPlan).' matches ('.count($completedMatches).' completed, 1 on today) across PRS + PR22.');
        });
    }
}
