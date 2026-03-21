<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\User;
use App\Services\StandingsCalculationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FederationDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $provinces = Province::all()->keyBy('abbreviation');
            $divisions = Division::all()->keyBy('slug');
            $categories = Category::all()->keyBy('slug');

            $director = User::where('email', 'director@saprf.co.za')->first();
            $admin = User::where('email', 'admin@saprf.co.za')->first();
            $owner = User::where('email', 'owner@saprf.co.za')->first();
            $existingMember = User::where('email', 'member@saprf.co.za')->first();

            $owner?->update(['date_of_birth' => '1980-01-10']);
            $existingMember?->update(['date_of_birth' => '1988-05-20', 'province_id' => $provinces['FS']->id]);

            // ── 45 Shooters ──
            // Mix: members/non-members, ladies, juniors, seniors, across provinces and divisions
            $shooterData = [
                // GP — Open division shooters (strong field)
                ['name' => 'Jan van der Berg',       'email' => 'jan@example.co.za',         'prov' => 'GP',  'dob' => '1985-03-15', 'div' => 'open',       'cats' => [],             'member' => true],
                ['name' => 'Pieter Joubert',         'email' => 'pieter@example.co.za',       'prov' => 'GP',  'dob' => '1990-07-22', 'div' => 'open',       'cats' => [],             'member' => true],
                ['name' => 'Kobus Venter',           'email' => 'kobus@example.co.za',        'prov' => 'GP',  'dob' => '1987-09-03', 'div' => 'open',       'cats' => [],             'member' => true],
                ['name' => 'Louis Potgieter',        'email' => 'louis@example.co.za',        'prov' => 'GP',  'dob' => '1983-11-28', 'div' => 'factory', 'cats' => [],             'member' => true],
                ['name' => 'Werner Steyn',           'email' => 'werner@example.co.za',       'prov' => 'GP',  'dob' => '1991-02-14', 'div' => 'factory', 'cats' => [],             'member' => true],

                // GP — Ladies
                ['name' => 'Chantel van der Merwe',   'email' => 'chantel@example.co.za',      'prov' => 'GP',  'dob' => '1992-01-18', 'div' => 'open',       'cats' => ['ladies'],       'member' => true],
                ['name' => 'Leandri Kruger',         'email' => 'leandri@example.co.za',      'prov' => 'GP',  'dob' => '1995-06-10', 'div' => 'factory', 'cats' => ['ladies'],       'member' => true],
                ['name' => 'Mienkie du Preez',       'email' => 'mienkie@example.co.za',      'prov' => 'GP',  'dob' => '1989-04-22', 'div' => 'limited',    'cats' => ['ladies'],       'member' => true],

                // WC — Mixed field
                ['name' => 'Andre Visser',           'email' => 'andre@example.co.za',        'prov' => 'WC',  'dob' => '1978-11-08', 'div' => 'open',       'cats' => [],             'member' => true],
                ['name' => 'Rudi Erasmus',           'email' => 'rudi@example.co.za',         'prov' => 'WC',  'dob' => '1975-08-05', 'div' => 'open',       'cats' => [],             'member' => true],
                ['name' => 'Gerhard Cilliers',       'email' => 'gerhard@example.co.za',      'prov' => 'WC',  'dob' => '1982-05-19', 'div' => 'factory', 'cats' => [],             'member' => true],
                ['name' => 'Jacques Engelbrecht',    'email' => 'jacques@example.co.za',      'prov' => 'WC',  'dob' => '1994-12-01', 'div' => 'limited',    'cats' => [],             'member' => true],
                ['name' => 'Suné Rossouw',           'email' => 'sune@example.co.za',         'prov' => 'WC',  'dob' => '1997-03-30', 'div' => 'factory', 'cats' => ['ladies'],       'member' => true],

                // KZN
                ['name' => 'Christo Muller',         'email' => 'christo@example.co.za',      'prov' => 'KZN', 'dob' => '1968-02-14', 'div' => 'open',       'cats' => ['senior'],     'member' => true],
                ['name' => 'Thabo Mkhize',           'email' => 'thabo@example.co.za',        'prov' => 'KZN', 'dob' => '1986-10-11', 'div' => 'factory', 'cats' => [],             'member' => true],
                ['name' => 'Johan Greyling',         'email' => 'johan@example.co.za',        'prov' => 'KZN', 'dob' => '1979-07-25', 'div' => 'open',       'cats' => [],             'member' => true],

                // FS
                ['name' => 'Francois du Plessis',    'email' => 'francois@example.co.za',     'prov' => 'FS',  'dob' => '1995-04-25', 'div' => 'open',       'cats' => [],             'member' => true],
                ['name' => 'Gert Coetzee',           'email' => 'gert@example.co.za',         'prov' => 'FS',  'dob' => '1988-08-16', 'div' => 'factory', 'cats' => [],             'member' => true],

                // MP
                ['name' => 'Dewald Botha',           'email' => 'dewald@example.co.za',       'prov' => 'MP',  'dob' => '1993-01-07', 'div' => 'open',       'cats' => [],             'member' => true],
                ['name' => 'Pieter-Louis Marais',    'email' => 'pl.marais@example.co.za',    'prov' => 'MP',  'dob' => '1981-06-19', 'div' => 'limited',    'cats' => [],             'member' => true],

                // LP
                ['name' => 'Hennie Pretorius',       'email' => 'hennie@example.co.za',       'prov' => 'LP',  'dob' => '1960-09-12', 'div' => 'open',       'cats' => ['senior'],     'member' => true],
                ['name' => 'Sakkie van Wyk',         'email' => 'sakkie@example.co.za',       'prov' => 'LP',  'dob' => '1958-04-03', 'div' => 'factory', 'cats' => ['senior'], 'member' => true],

                // NW
                ['name' => 'Danie Swanepoel',        'email' => 'danie@example.co.za',        'prov' => 'NW',  'dob' => '1982-12-01', 'div' => 'open',       'cats' => [],             'member' => true],
                ['name' => 'Frikkie Bothma',         'email' => 'frikkie@example.co.za',      'prov' => 'NW',  'dob' => '1977-09-28', 'div' => 'factory',    'cats' => [],             'member' => true],

                // NC
                ['name' => 'Gideon Louw',            'email' => 'gideon@example.co.za',       'prov' => 'NC',  'dob' => '1984-02-11', 'div' => 'open',       'cats' => [],             'member' => true],

                // Juniors (under 21 on 1 Jan 2026)
                ['name' => 'Willem Botha',           'email' => 'willem@example.co.za',       'prov' => 'FS',  'dob' => '2007-06-30', 'div' => 'factory', 'cats' => ['junior'],     'member' => true],
                ['name' => 'Ethan Steenkamp',        'email' => 'ethan@example.co.za',        'prov' => 'GP',  'dob' => '2008-03-14', 'div' => 'factory', 'cats' => ['junior'],     'member' => true],
                ['name' => 'Marco van Rensburg',     'email' => 'marco@example.co.za',        'prov' => 'WC',  'dob' => '2006-11-22', 'div' => 'limited',    'cats' => ['junior'],     'member' => true],
                ['name' => 'Nico Jacobs',            'email' => 'nico@example.co.za',         'prov' => 'GP',  'dob' => '2009-08-05', 'div' => 'factory', 'cats' => ['junior'],     'member' => true],

                // Younger junior (under 14)
                ['name' => 'Liam du Toit',           'email' => 'liam@example.co.za',         'prov' => 'GP',  'dob' => '2012-05-18', 'div' => 'factory', 'cats' => ['junior'], 'member' => true],

                // Non-members (no membership — shoot as non-member)
                ['name' => 'Tommy Wilson',           'email' => 'tommy@example.co.za',        'prov' => 'WC',  'dob' => '1990-10-03', 'div' => 'open',       'cats' => [],             'member' => false],
                ['name' => 'Craig Adams',            'email' => 'craig@example.co.za',        'prov' => 'GP',  'dob' => '1986-02-28', 'div' => 'factory', 'cats' => [],             'member' => false],
                ['name' => 'Neville Harris',         'email' => 'neville@example.co.za',      'prov' => 'KZN', 'dob' => '1991-07-14', 'div' => 'open',       'cats' => [],             'member' => false],
                ['name' => 'Mike Pienaar',           'email' => 'mike.p@example.co.za',       'prov' => 'FS',  'dob' => '1985-12-09', 'div' => 'limited',    'cats' => [],             'member' => false],
                ['name' => 'James Scott',            'email' => 'james.s@example.co.za',      'prov' => 'GP',  'dob' => '1993-05-16', 'div' => 'factory',    'cats' => [], 'member' => false],

                // Lapsed members
                ['name' => 'Boeta Kruger',           'email' => 'boeta@example.co.za',        'prov' => 'NW',  'dob' => '1976-08-22', 'div' => 'open',       'cats' => [],             'member' => 'lapsed'],
                ['name' => 'Hein van Niekerk',       'email' => 'hein@example.co.za',         'prov' => 'MP',  'dob' => '1980-03-17', 'div' => 'factory', 'cats' => [],             'member' => 'lapsed'],

                // Former PR22 rimfire demo shooters (same divisions as centrefire)
                ['name' => 'Riaan Booysen',          'email' => 'riaan@example.co.za',        'prov' => 'GP',  'dob' => '1989-09-30', 'div' => 'open',       'cats' => [],             'member' => true],
                ['name' => 'Stefan Maritz',          'email' => 'stefan@example.co.za',       'prov' => 'WC',  'dob' => '1996-01-12', 'div' => 'open',       'cats' => [],             'member' => true],
                ['name' => 'Jaco de Beer',           'email' => 'jaco@example.co.za',         'prov' => 'GP',  'dob' => '1984-06-05', 'div' => 'factory',  'cats' => [],             'member' => true],
                ['name' => 'Zelda Kotzé',            'email' => 'zelda@example.co.za',        'prov' => 'WC',  'dob' => '1998-11-14', 'div' => 'open',       'cats' => ['ladies'],       'member' => true],
                ['name' => 'Ernst Schoeman',         'email' => 'ernst@example.co.za',        'prov' => 'FS',  'dob' => '1963-04-20', 'div' => 'factory',  'cats' => ['senior'],     'member' => true],
            ];

            $allShooters = [];
            $memberCounter = 1001;

            foreach ($shooterData as $data) {
                $user = User::updateOrCreate(
                    ['email' => $data['email']],
                    [
                        'name' => $data['name'],
                        'password' => Hash::make('password'),
                        'province_id' => $provinces[$data['prov']]->id,
                        'date_of_birth' => $data['dob'],
                    ],
                );
                $user->assignRole('member');

                $divisionId = $divisions[$data['div']]?->id;
                $user->update(['division_id' => $divisionId]);

                $catIds = collect($data['cats'])
                    ->map(fn ($code) => $categories[$code]?->id)
                    ->filter()
                    ->values()
                    ->toArray();
                if (! empty($catIds)) {
                    $user->categories()->syncWithoutDetaching($catIds);
                }

                if ($data['member'] === true) {
                    $membership = Membership::firstOrCreate(
                        ['user_id' => $user->id],
                        [
                            'saprf_number' => 'SAPRF-2026-' . str_pad($memberCounter, 4, '0', STR_PAD_LEFT),
                            'membership_type' => 'paid',
                            'status' => 'active',
                            'payment_status' => 'paid',
                            'start_date' => now()->subMonths(rand(0, 6))->toDateString(),
                            'expiry_date' => now()->addMonths(rand(3, 12))->toDateString(),
                        ],
                    );
                    MembershipPayment::firstOrCreate(
                        ['membership_id' => $membership->id, 'payment_reference' => 'SEED-PAY-' . $memberCounter],
                        ['amount' => 350.00, 'payment_date' => '2026-01-05', 'payment_method' => 'eft', 'status' => 'confirmed'],
                    );
                    $memberCounter++;
                } elseif ($data['member'] === 'lapsed') {
                    Membership::firstOrCreate(
                        ['user_id' => $user->id],
                        [
                            'saprf_number' => 'SAPRF-2025-' . str_pad($memberCounter, 4, '0', STR_PAD_LEFT),
                            'membership_type' => 'paid',
                            'status' => 'lapsed',
                            'payment_status' => 'paid',
                            'start_date' => now()->subYear()->subMonths(rand(0, 6))->toDateString(),
                            'expiry_date' => now()->subMonths(rand(1, 6))->toDateString(),
                        ],
                    );
                    $memberCounter++;
                }

                $allShooters[] = [
                    'user' => $user,
                    'div_code' => $data['div'],
                    'cat_codes' => $data['cats'],
                    'is_member' => $data['member'] === true,
                ];
            }

            // Also include existing member user
            if ($existingMember) {
                Membership::firstOrCreate(
                    ['user_id' => $existingMember->id],
                    [
                        'saprf_number' => 'SAPRF-2026-0500',
                        'membership_type' => 'paid',
                        'status' => 'active',
                        'payment_status' => 'paid',
                        'start_date' => now()->subMonths(rand(0, 6))->toDateString(),
                        'expiry_date' => now()->addMonths(rand(3, 12))->toDateString(),
                    ],
                );
                $allShooters[] = [
                    'user' => $existingMember,
                    'div_code' => 'open',
                    'cat_codes' => [],
                    'is_member' => true,
                ];
            }

            // ── 2026 Match Calendar ──
            $matchData = [
                ['name' => 'Centrefire NW 2-Day National', 'type' => 'PRS', 'level' => 'national', 'prov' => 'NW', 'date' => '2026-02-14', 'end' => '2026-02-15', 'status' => 'completed', 'venue' => 'NW Klerksdorp', 'city' => 'Klerksdorp', 'max' => 40, 'featured' => true, 'dual_provincial' => true],
                ['name' => 'Centrefire WC 2-Day National — Wolseley', 'type' => 'PRS', 'level' => 'national', 'prov' => 'WC', 'date' => '2026-03-07', 'end' => '2026-03-08', 'status' => 'completed', 'venue' => 'Romansrivier Wolseley', 'city' => 'Wolseley', 'max' => 40, 'featured' => true, 'dual_provincial' => true],
                ['name' => 'Rimfire PR22 GP 2-Day National', 'type' => 'PR22', 'level' => 'national', 'prov' => 'GP', 'date' => '2026-02-28', 'end' => '2026-03-01', 'status' => 'completed', 'venue' => 'Hippo Creek', 'city' => 'Gauteng', 'max' => 30, 'featured' => false, 'dual_provincial' => false],

                ['name' => 'Centrefire GP 2-Day National', 'type' => 'PRS', 'level' => 'national', 'prov' => 'GP', 'date' => '2026-06-20', 'end' => '2026-06-21', 'status' => 'draft', 'venue' => 'Hippo Creek', 'city' => 'Gauteng', 'max' => 40, 'featured' => true],
                ['name' => 'Centrefire MP 2-Day National', 'type' => 'PRS', 'level' => 'national', 'prov' => 'MP', 'date' => '2026-07-11', 'end' => '2026-07-12', 'status' => 'draft', 'venue' => 'Lydenburg', 'city' => 'Lydenburg', 'max' => 40, 'featured' => false],
                ['name' => 'Centrefire LP 2-Day National', 'type' => 'PRS', 'level' => 'national', 'prov' => 'LP', 'date' => '2026-08-08', 'end' => '2026-08-09', 'status' => 'draft', 'venue' => 'Marble Hall', 'city' => 'Marble Hall', 'max' => 40, 'featured' => false],
                ['name' => 'Centrefire WC 2-Day National — Darling', 'type' => 'PRS', 'level' => 'national', 'prov' => 'WC', 'date' => '2026-10-24', 'end' => '2026-10-25', 'status' => 'draft', 'venue' => 'Darling Steel Valley', 'city' => 'Darling', 'max' => 40, 'featured' => false],
                ['name' => 'Centrefire GP 2-Day Championship', 'type' => 'PRS', 'level' => 'final', 'prov' => 'GP', 'date' => '2026-11-21', 'end' => '2026-11-22', 'status' => 'draft', 'venue' => 'Legends Adventure Farm', 'city' => 'Gauteng', 'max' => 30, 'featured' => true],

                ['name' => 'Centrefire FS Provincial', 'type' => 'PRS', 'level' => 'provincial', 'prov' => 'FS', 'date' => '2026-04-18', 'end' => null, 'status' => 'open', 'venue' => 'Bloemfontein', 'city' => 'Bloemfontein', 'max' => 25, 'featured' => false],
                ['name' => 'Centre Fire GP Provincial', 'type' => 'PRS', 'level' => 'provincial', 'prov' => 'GP', 'date' => '2026-05-02', 'end' => null, 'status' => 'open', 'venue' => 'Legends Adventure Farm', 'city' => 'Gauteng', 'max' => 25, 'featured' => false],
                ['name' => 'Centrefire WC Provincial', 'type' => 'PRS', 'level' => 'provincial', 'prov' => 'WC', 'date' => '2026-09-05', 'end' => null, 'status' => 'draft', 'venue' => 'Romansrivier Wolseley', 'city' => 'Wolseley', 'max' => 25, 'featured' => false],

                ['name' => 'Rimfire PR22 MP 2-Day National', 'type' => 'PR22', 'level' => 'national', 'prov' => 'MP', 'date' => '2026-08-29', 'end' => '2026-08-30', 'status' => 'draft', 'venue' => 'TBC - Mpumalanga', 'city' => 'Mpumalanga', 'max' => 30, 'featured' => false],
                ['name' => 'Rimfire PR22 GP Championship', 'type' => 'PR22', 'level' => 'final', 'prov' => 'GP', 'date' => '2026-11-07', 'end' => '2026-11-08', 'status' => 'draft', 'venue' => 'TBC - Gauteng', 'city' => 'Gauteng', 'max' => 24, 'featured' => true],

                ['name' => 'Rimfire PR22 MP Provincial', 'type' => 'PR22', 'level' => 'provincial', 'prov' => 'MP', 'date' => '2026-04-11', 'end' => null, 'status' => 'open', 'venue' => 'Balmoral Hunting Farm', 'city' => 'Balmoral', 'max' => 20, 'featured' => false],
                ['name' => 'Rimfire PR22 GP Provincial May', 'type' => 'PR22', 'level' => 'provincial', 'prov' => 'GP', 'date' => '2026-05-23', 'end' => null, 'status' => 'draft', 'venue' => "Leopard's Valley", 'city' => 'Gauteng', 'max' => 20, 'featured' => false],
                ['name' => 'Rimfire PR22 WC Provincial May', 'type' => 'PR22', 'level' => 'provincial', 'prov' => 'WC', 'date' => '2026-05-30', 'end' => null, 'status' => 'draft', 'venue' => 'TBC - Western Cape', 'city' => 'Western Cape', 'max' => 20, 'featured' => false],
                ['name' => 'Rimfire PR22 WC Provincial Jun', 'type' => 'PR22', 'level' => 'provincial', 'prov' => 'WC', 'date' => '2026-06-14', 'end' => null, 'status' => 'draft', 'venue' => 'Atlantis Shooting Range', 'city' => 'Atlantis', 'max' => 20, 'featured' => false],
                ['name' => 'Rimfire PR22 LP Provincial', 'type' => 'PR22', 'level' => 'provincial', 'prov' => 'LP', 'date' => '2026-07-18', 'end' => null, 'status' => 'draft', 'venue' => 'Risla Range', 'city' => 'Limpopo', 'max' => 20, 'featured' => false],
                ['name' => 'Rimfire PR22 GP Provincial Aug', 'type' => 'PR22', 'level' => 'provincial', 'prov' => 'GP', 'date' => '2026-08-15', 'end' => null, 'status' => 'draft', 'venue' => 'Legends Adventure Farm', 'city' => 'Gauteng', 'max' => 20, 'featured' => false],
            ];

            $completedPrsMatches = [];
            $completedPr22Matches = [];

            foreach ($matchData as $data) {
                $regOpen = date('Y-m-d', strtotime($data['date'] . ' -30 days'));
                $regClose = date('Y-m-d', strtotime($data['date'] . ' -5 days'));
                $fee = $data['level'] === 'provincial' ? 350.00 : 500.00;

                $matchAttrs = [
                    'match_type' => $data['type'],
                    'series_level' => $data['level'],
                    'series' => $data['type'],
                    'season' => '2026',
                    'province_id' => $provinces[$data['prov']]->id,
                    'venue_name' => $data['venue'],
                    'venue_location' => $data['city'] . ', ' . $provinces[$data['prov']]->name,
                    'city' => $data['city'],
                    'match_date' => $data['date'],
                    'match_end_date' => $data['end'],
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
                    'published' => true,
                    'category_rankings_enabled' => true,
                    'division_awards_enabled' => true,
                ];

                if (!empty($data['dual_provincial'])) {
                    $matchAttrs['also_counts_for_provincial'] = true;
                    $matchAttrs['provincial_stage_columns'] = 'stage_1,stage_2,stage_3,stage_4,stage_5,stage_6,stage_7,stage_8,stage_9,stage_10';
                }

                $match = MatchEvent::firstOrCreate(['name' => $data['name']], $matchAttrs);

                $match->divisions()->syncWithoutDetaching($divisions->pluck('id'));

                if ($data['status'] === 'completed' && $data['type'] === 'PRS') {
                    $completedPrsMatches[] = $match;
                }
                if ($data['status'] === 'completed' && $data['type'] === 'PR22') {
                    $completedPr22Matches[] = $match;
                }
            }

            // ── Qualification Rules ──
            QualificationRule::firstOrCreate(
                ['series' => 'PRS', 'season' => '2026'],
                ['min_out_of_province_matches' => 2, 'best_of_count' => 5, 'total_qualifying_matches' => 7, 'created_by' => $admin->id],
            );
            QualificationRule::firstOrCreate(
                ['series' => 'PR22', 'season' => '2026'],
                ['min_out_of_province_matches' => 1, 'best_of_count' => 4, 'total_qualifying_matches' => 6, 'created_by' => $admin->id],
            );

            // ── Generate Scores for Completed Matches ──
            $prsShooters = collect($allShooters);
            $pr22Shooters = collect($allShooters);

            $standingsService = app(StandingsCalculationService::class);
            $seedIndex = 42;

            foreach ($completedPrsMatches as $match) {
                $this->seedMatchScores($match, $prsShooters->values(), $divisions, $categories, $seedIndex);
                $standingsService->recalculateForMatch($match);
                $seedIndex += 7;
            }

            foreach ($completedPr22Matches as $match) {
                $this->seedMatchScores($match, $pr22Shooters->values(), $divisions, $categories, $seedIndex);
                $standingsService->recalculateForMatch($match);
                $seedIndex += 7;
            }
        });
    }

    private function seedMatchScores($match, $shooters, $divisions, $categories, int $seed): void
    {
        $rng = mt_rand(0, 100);
        mt_srand($seed);

        foreach ($shooters as $idx => $shooter) {
            $user = $shooter['user'];
            $divCode = $shooter['div_code'];
            $catCodes = $shooter['cat_codes'];
            $divisionId = $divisions[$divCode]?->id;

            $baseScore = 55 - ($idx * 0.8);
            $variance = (mt_rand(-30, 30)) / 10;
            $rawScore = max(5, min(60, round($baseScore + $variance, 1)));

            $provincialRawScore = null;
            if ($match->also_counts_for_provincial) {
                $provincialRawScore = round($rawScore * (0.45 + (mt_rand(0, 20) / 100)), 1);
            }

            $score = Score::create([
                'match_id' => $match->id,
                'shooter_name' => $user->name,
                'user_id' => $user->id,
                'raw_score' => $rawScore,
                'provincial_raw_score' => $provincialRawScore,
                'placement' => null,
                'division_id' => $divisionId,
                'is_member' => $shooter['is_member'],
                'status' => 'valid',
                'match_date' => $match->match_date,
                'counts_for_season' => true,
            ]);

            foreach ($catCodes as $catCode) {
                if (isset($categories[$catCode])) {
                    $score->categories()->attach($categories[$catCode]->id);
                }
            }
        }

        mt_srand();
    }
}
