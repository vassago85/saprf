<?php

namespace Database\Seeders;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\ShooterLog;
use App\Models\Standing;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FederationDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $provinces = Province::all()->keyBy('abbreviation');
            $director = User::where('email', 'director@saprf.co.za')->first();
            $admin = User::where('email', 'admin@saprf.co.za')->first();
            $existingMember = User::where('email', 'member@saprf.co.za')->first();
            $owner = User::where('email', 'owner@saprf.co.za')->first();

            $newUsers = [
                ['name' => 'Jan van der Berg',    'email' => 'jan@example.co.za',      'province' => 'GP'],
                ['name' => 'Pieter Joubert',      'email' => 'pieter@example.co.za',    'province' => 'GP'],
                ['name' => 'Andre Visser',        'email' => 'andre@example.co.za',     'province' => 'WC'],
                ['name' => 'Christo Muller',       'email' => 'christo@example.co.za',   'province' => 'KZN'],
                ['name' => 'Willem Botha',        'email' => 'willem@example.co.za',    'province' => 'FS'],
                ['name' => 'Hennie Pretorius',    'email' => 'hennie@example.co.za',    'province' => 'LP'],
                ['name' => 'Francois du Plessis', 'email' => 'francois@example.co.za',  'province' => 'MP'],
                ['name' => 'Danie Swanepoel',     'email' => 'danie@example.co.za',     'province' => 'NW'],
            ];

            $members = [];
            foreach ($newUsers as $data) {
                $user = User::firstOrCreate(
                    ['email' => $data['email']],
                    [
                        'name' => $data['name'],
                        'password' => Hash::make('password'),
                        'province_id' => $provinces[$data['province']]->id,
                    ],
                );
                $user->assignRole('member');
                $members[] = $user;
            }

            // ── Active paid members ──────────────────────────────────
            $activeUsers = collect([
                $existingMember,             // member@saprf.co.za (FS)
                $members[0],                 // Jan (GP)
                $members[1],                 // Pieter (GP)
                $members[2],                 // Andre (WC)
                $members[3],                 // Christo (KZN)
                $members[4],                 // Willem (FS)
                $members[5],                 // Hennie (LP)
                $members[6],                 // Francois (MP)
            ]);

            $counter = 1001;
            foreach ($activeUsers as $user) {
                $membership = Membership::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'saprf_number' => 'SAPRF-2026-' . str_pad($counter, 4, '0', STR_PAD_LEFT),
                        'membership_type' => 'paid',
                        'status' => 'active',
                        'payment_status' => 'paid',
                        'start_date' => '2026-01-01',
                        'expiry_date' => '2026-12-31',
                    ],
                );

                MembershipPayment::firstOrCreate(
                    ['membership_id' => $membership->id, 'payment_reference' => 'SEED-PAY-' . $counter],
                    [
                        'amount' => 350.00,
                        'payment_date' => '2026-01-05',
                        'payment_method' => 'eft',
                        'status' => 'confirmed',
                    ],
                );

                $counter++;
            }

            // Lapsed member (Danie)
            Membership::firstOrCreate(
                ['user_id' => $members[7]->id],
                [
                    'saprf_number' => 'SAPRF-2025-2001',
                    'membership_type' => 'paid',
                    'status' => 'lapsed',
                    'payment_status' => 'paid',
                    'start_date' => '2025-01-01',
                    'expiry_date' => '2025-12-31',
                ],
            );

            // Owner - recently lapsed (grace period testing)
            Membership::firstOrCreate(
                ['user_id' => $owner->id],
                [
                    'saprf_number' => 'SAPRF-2026-0099',
                    'membership_type' => 'paid',
                    'status' => 'lapsed',
                    'payment_status' => 'paid',
                    'start_date' => '2025-04-01',
                    'expiry_date' => now()->subDays(3)->toDateString(),
                ],
            );

            // ── 2026 Match Calendar ──────────────────────────────────
            $matchData = [
                // PRS National (7 matches)
                ['name' => 'Gauteng PRS Open 2026',         'type' => 'PRS', 'level' => 'national',   'prov' => 'GP',  'date' => '2026-01-25', 'status' => 'completed', 'venue' => 'Pretoria Shooting Range',        'city' => 'Pretoria',        'max' => 40, 'featured' => true],
                ['name' => 'Western Cape PRS Championship',  'type' => 'PRS', 'level' => 'national',   'prov' => 'WC',  'date' => '2026-02-22', 'status' => 'completed', 'venue' => 'False Bay Rifle Club',          'city' => 'Cape Town',       'max' => 40, 'featured' => true],
                ['name' => 'KZN PRS Invitational 2026',     'type' => 'PRS', 'level' => 'national',   'prov' => 'KZN', 'date' => '2026-03-15', 'status' => 'completed', 'venue' => 'Durban Precision Rifle Club',   'city' => 'Durban',          'max' => 36, 'featured' => false],
                ['name' => 'Mpumalanga PRS Open 2026',       'type' => 'PRS', 'level' => 'national',   'prov' => 'MP',  'date' => '2026-04-12', 'status' => 'open',      'venue' => 'Nelspruit Long Range',          'city' => 'Nelspruit',       'max' => 40, 'featured' => true],
                ['name' => 'Free State PRS National 2026',   'type' => 'PRS', 'level' => 'national',   'prov' => 'FS',  'date' => '2026-05-24', 'status' => 'open',      'venue' => 'Bloemfontein Shooting Complex', 'city' => 'Bloemfontein',    'max' => 36, 'featured' => false],
                ['name' => 'Limpopo PRS Shootout 2026',      'type' => 'PRS', 'level' => 'national',   'prov' => 'LP',  'date' => '2026-07-19', 'status' => 'draft',     'venue' => 'Polokwane Rifle Range',         'city' => 'Polokwane',       'max' => 30, 'featured' => false],
                ['name' => 'PRS National Final 2026',        'type' => 'PRS', 'level' => 'final',      'prov' => 'GP',  'date' => '2026-09-20', 'status' => 'draft',     'venue' => 'Centurion Rifle Range',         'city' => 'Centurion',       'max' => 30, 'featured' => true],

                // PR22 National (5 matches)
                ['name' => 'Gauteng PR22 National 2026',     'type' => 'PR22', 'level' => 'national',  'prov' => 'GP',  'date' => '2026-02-01', 'status' => 'completed', 'venue' => 'Johannesburg Rimfire Centre',   'city' => 'Johannesburg',    'max' => 30, 'featured' => false],
                ['name' => 'Western Cape PR22 Open 2026',    'type' => 'PR22', 'level' => 'national',  'prov' => 'WC',  'date' => '2026-03-08', 'status' => 'completed', 'venue' => 'Stellenbosch Shooting Club',    'city' => 'Stellenbosch',    'max' => 30, 'featured' => false],
                ['name' => 'KZN PR22 Championship 2026',     'type' => 'PR22', 'level' => 'national',  'prov' => 'KZN', 'date' => '2026-04-26', 'status' => 'open',      'venue' => 'Pietermaritzburg Rifle Club',   'city' => 'Pietermaritzburg','max' => 30, 'featured' => false],
                ['name' => 'Free State PR22 Open 2026',      'type' => 'PR22', 'level' => 'national',  'prov' => 'FS',  'date' => '2026-06-14', 'status' => 'open',      'venue' => 'Bloemfontein Shooting Complex', 'city' => 'Bloemfontein',    'max' => 28, 'featured' => false],
                ['name' => 'PR22 National Final 2026',       'type' => 'PR22', 'level' => 'final',     'prov' => 'GP',  'date' => '2026-08-23', 'status' => 'draft',     'venue' => 'Johannesburg Rimfire Centre',   'city' => 'Johannesburg',    'max' => 24, 'featured' => true],

                // PRS Provincial (4 matches)
                ['name' => 'GP PRS Provincial Jan 2026',     'type' => 'PRS', 'level' => 'provincial', 'prov' => 'GP',  'date' => '2026-01-18', 'status' => 'completed', 'venue' => 'Centurion Rifle Range',         'city' => 'Centurion',       'max' => 25, 'featured' => false],
                ['name' => 'WC PRS Provincial 2026',         'type' => 'PRS', 'level' => 'provincial', 'prov' => 'WC',  'date' => '2026-02-08', 'status' => 'completed', 'venue' => 'Paarl Target Club',             'city' => 'Paarl',           'max' => 20, 'featured' => false],
                ['name' => 'KZN PRS Provincial 2026',        'type' => 'PRS', 'level' => 'provincial', 'prov' => 'KZN', 'date' => '2026-03-08', 'status' => 'completed', 'venue' => 'Hilton Shooting Club',          'city' => 'Hilton',          'max' => 20, 'featured' => false],
                ['name' => 'GP PRS Provincial Apr 2026',     'type' => 'PRS', 'level' => 'provincial', 'prov' => 'GP',  'date' => '2026-04-19', 'status' => 'open',      'venue' => 'Pretoria Shooting Range',       'city' => 'Pretoria',        'max' => 25, 'featured' => false],

                // PR22 Provincial (3 matches)
                ['name' => 'GP PR22 Provincial 2026',        'type' => 'PR22', 'level' => 'provincial', 'prov' => 'GP', 'date' => '2026-02-15', 'status' => 'completed', 'venue' => 'Johannesburg Rimfire Centre',   'city' => 'Johannesburg',    'max' => 20, 'featured' => false],
                ['name' => 'WC PR22 Provincial 2026',        'type' => 'PR22', 'level' => 'provincial', 'prov' => 'WC', 'date' => '2026-03-01', 'status' => 'completed', 'venue' => 'Stellenbosch Shooting Club',    'city' => 'Stellenbosch',    'max' => 20, 'featured' => false],
                ['name' => 'WC PR22 Provincial Apr 2026',    'type' => 'PR22', 'level' => 'provincial', 'prov' => 'WC', 'date' => '2026-04-05', 'status' => 'open',      'venue' => 'Paarl Target Club',             'city' => 'Paarl',           'max' => 20, 'featured' => false],
            ];

            $matches = [];
            foreach ($matchData as $data) {
                $regOpen = date('Y-m-d', strtotime($data['date'] . ' -30 days'));
                $regClose = date('Y-m-d', strtotime($data['date'] . ' -5 days'));

                $fee = $data['level'] === 'provincial' ? 350.00 : 500.00;

                $match = MatchEvent::firstOrCreate(
                    ['name' => $data['name']],
                    [
                        'match_type' => $data['type'],
                        'series_level' => $data['level'],
                        'series' => $data['type'],
                        'season' => '2026',
                        'province_id' => $provinces[$data['prov']]->id,
                        'venue_name' => $data['venue'],
                        'venue_location' => $data['city'] . ', ' . $provinces[$data['prov']]->name,
                        'city' => $data['city'],
                        'match_date' => $data['date'],
                        'registration_open_date' => $regOpen,
                        'registration_close_date' => $regClose,
                        'status' => $data['status'],
                        'created_by' => $director->id,
                        'active_member_fee' => $fee,
                        'non_member_fee' => $fee + 250,
                        'lapsed_member_fee' => $fee + 150,
                        'max_competitors' => $data['max'],
                        'waitlist_enabled' => $data['max'] <= 30,
                        'is_featured' => $data['featured'],
                        'published' => $data['status'] !== 'draft' || $data['featured'],
                    ],
                );
                $matches[] = $match;
            }

            // ── Match registrations ──────────────────────────────────
            $registrableMatches = collect($matches)->filter(
                fn ($m) => in_array($m->status, ['completed', 'open']),
            );

            foreach ($registrableMatches as $match) {
                foreach ($activeUsers as $user) {
                    MatchRegistration::firstOrCreate(
                        ['match_id' => $match->id, 'user_id' => $user->id],
                        [
                            'shooter_name' => $user->name,
                            'email' => $user->email,
                            'membership_fee_category' => 'active',
                            'fee_amount' => $match->active_member_fee,
                            'payment_status' => 'paid',
                            'registration_status' => 'confirmed',
                            'registered_at' => $match->registration_open_date?->addDays(rand(1, 10)),
                        ],
                    );
                }

                // Lapsed (Danie) - registered for completed matches
                if ($match->status === 'completed') {
                    MatchRegistration::firstOrCreate(
                        ['match_id' => $match->id, 'user_id' => $members[7]->id],
                        [
                            'shooter_name' => $members[7]->name,
                            'email' => $members[7]->email,
                            'membership_fee_category' => 'lapsed',
                            'fee_amount' => $match->lapsed_member_fee,
                            'payment_status' => 'paid',
                            'registration_status' => 'confirmed',
                            'registered_at' => $match->registration_open_date?->addDays(rand(1, 5)),
                        ],
                    );
                }

                // Admin (non-member) on some completed PRS matches
                if ($match->match_type === 'PRS' && $match->status === 'completed') {
                    MatchRegistration::firstOrCreate(
                        ['match_id' => $match->id, 'user_id' => $admin->id],
                        [
                            'shooter_name' => $admin->name,
                            'email' => $admin->email,
                            'membership_fee_category' => 'non_member',
                            'fee_amount' => $match->non_member_fee,
                            'payment_status' => 'paid',
                            'registration_status' => 'confirmed',
                            'registered_at' => $match->registration_open_date?->addDays(2),
                        ],
                    );
                }
            }

            // ── Scores for completed matches ─────────────────────────
            // Varied performance profiles per shooter across matches
            // Lower index = generally better shooter but with variance
            $performanceSeeds = [
                // [base_skill, consistency] — higher skill = better, higher consistency = less variance
                [95, 85],  // existingMember - solid
                [92, 80],  // Jan - strong
                [90, 90],  // Pieter - very consistent
                [88, 75],  // Andre - good but variable
                [85, 82],  // Christo - mid-strong
                [80, 78],  // Willem - mid
                [76, 70],  // Hennie - developing
                [72, 65],  // Francois - newer
            ];

            $completedMatches = collect($matches)->filter(fn ($m) => $m->status === 'completed');
            $divisions = ['Open', 'Production', 'Heavy'];
            $matchIndex = 0;

            foreach ($completedMatches as $match) {
                $shooterScores = [];

                foreach ($activeUsers as $idx => $user) {
                    [$skill, $consistency] = $performanceSeeds[$idx];
                    $variance = (100 - $consistency) * 0.5;
                    $performanceRoll = $skill + rand((int) -$variance, (int) $variance);
                    $rawScore = round(max(150, min(480, 350 + ($performanceRoll - 75) * 3.5 + rand(-30, 30) / 10)), 3);

                    $shooterScores[] = [
                        'user' => $user,
                        'raw_score' => $rawScore,
                        'division' => $divisions[$idx % count($divisions)],
                        'is_member' => true,
                        'status' => 'valid',
                        'validation_reason' => null,
                    ];
                }

                // Sort by raw_score descending to assign placements
                usort($shooterScores, fn ($a, $b) => $b['raw_score'] <=> $a['raw_score']);

                $placement = 1;
                foreach ($shooterScores as $entry) {
                    Score::firstOrCreate(
                        ['match_id' => $match->id, 'user_id' => $entry['user']->id],
                        [
                            'shooter_name' => $entry['user']->name,
                            'raw_score' => $entry['raw_score'],
                            'placement' => $placement,
                            'division' => $entry['division'],
                            'category' => 'Senior',
                            'is_member' => $entry['is_member'],
                            'status' => $entry['status'],
                            'validation_reason' => $entry['validation_reason'],
                            'match_date' => $match->match_date,
                        ],
                    );
                    $placement++;
                }

                // Non-member (admin) on PRS completed matches
                if ($match->match_type === 'PRS') {
                    Score::firstOrCreate(
                        ['match_id' => $match->id, 'user_id' => $admin->id],
                        [
                            'shooter_name' => $admin->name,
                            'raw_score' => round(280.0 + rand(-50, 50) / 10, 3),
                            'placement' => $placement,
                            'division' => 'Open',
                            'category' => 'Senior',
                            'is_member' => false,
                            'status' => 'invalid',
                            'validation_reason' => 'No active SAPRF membership at time of match.',
                            'match_date' => $match->match_date,
                        ],
                    );
                    $placement++;
                }

                // Lapsed (Danie) - pending score
                Score::firstOrCreate(
                    ['match_id' => $match->id, 'user_id' => $members[7]->id],
                    [
                        'shooter_name' => $members[7]->name,
                        'raw_score' => round(320.0 + rand(-30, 30) / 10, 3),
                        'placement' => $placement,
                        'division' => 'Production',
                        'category' => 'Senior',
                        'is_member' => false,
                        'status' => 'pending',
                        'validation_reason' => 'Membership lapsed — awaiting renewal within 7-day grace period.',
                        'match_date' => $match->match_date,
                    ],
                );

                $matchIndex++;
            }

            // ── Shooter logs ─────────────────────────────────────────
            $validScores = Score::where('status', 'valid')->get();
            foreach ($validScores as $score) {
                ShooterLog::firstOrCreate(
                    ['score_id' => $score->id],
                    [
                        'user_id' => $score->user_id,
                        'match_id' => $score->match_id,
                        'counted' => true,
                        'notes' => "{$score->division} division, placement #{$score->placement}.",
                    ],
                );
            }

            // ── Qualification rules ──────────────────────────────────
            QualificationRule::firstOrCreate(
                ['series' => 'PRS', 'season' => '2026'],
                ['min_out_of_province_matches' => 2, 'created_by' => $admin->id],
            );
            QualificationRule::firstOrCreate(
                ['series' => 'PR22', 'season' => '2026'],
                ['min_out_of_province_matches' => 1, 'created_by' => $admin->id],
            );

            // ── Standings ────────────────────────────────────────────
            // National standings (province_id = null) from national matches
            $this->seedStandings('PRS', '2026', 'national', null);
            $this->seedStandings('PR22', '2026', 'national', null);

            // Provincial standings per province that had provincial matches
            foreach (['GP', 'WC', 'KZN'] as $abbr) {
                $prov = $provinces[$abbr];
                $this->seedStandings('PRS', '2026', 'provincial', $prov->id);
            }
            foreach (['GP', 'WC'] as $abbr) {
                $prov = $provinces[$abbr];
                $this->seedStandings('PR22', '2026', 'provincial', $prov->id);
            }
        });
    }

    private function seedStandings(string $series, string $season, string $level, ?int $provinceId): void
    {
        $scores = Score::where('status', 'valid')
            ->whereNotNull('user_id')
            ->whereHas('match', function ($q) use ($series, $season, $level, $provinceId) {
                $q->where('series', $series)
                    ->where('season', $season)
                    ->where('series_level', $level);

                if ($provinceId !== null) {
                    $q->where('province_id', $provinceId);
                }
            })
            ->get()
            ->groupBy('user_id');

        $standings = [];
        foreach ($scores as $userId => $userScores) {
            $points = $userScores->sum(function ($score) {
                return $score->placement ? max(0, 101 - $score->placement) : 0;
            });

            $standings[] = [
                'user_id' => $userId,
                'points' => $points,
            ];
        }

        usort($standings, fn ($a, $b) => $b['points'] <=> $a['points']);

        Standing::where('series', $series)
            ->where('season', $season)
            ->where('province_id', $provinceId)
            ->delete();

        $rank = 1;
        foreach ($standings as $entry) {
            Standing::create([
                'user_id' => $entry['user_id'],
                'series' => $series,
                'season' => $season,
                'province_id' => $provinceId,
                'points' => $entry['points'],
                'rank' => $rank++,
            ]);
        }
    }
}
