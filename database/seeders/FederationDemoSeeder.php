<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\User;
use App\Services\StandingsCalculationService;
use Database\Seeders\Concerns\ResolvesSeedPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FederationDemoSeeder extends Seeder
{
    use ResolvesSeedPassword;

    public function run(): void
    {
        DB::transaction(function () {
            $provinces = Province::all()->keyBy('abbreviation');
            $divisions = Division::all()->keyBy('slug');

            // Under the flat-division model, Ladies/Junior/Senior are divisions.
            // The old shooter data still carries `cats => ['junior','ladies'...]`
            // — resolve each shooter into a single division. Demographic tags
            // trump equipment class (a junior shoots in the Junior division
            // rather than in Open).
            $resolveDivision = function (string $equipmentDiv, array $cats): string {
                if (in_array('junior', $cats, true)) {
                    return 'junior';
                }
                if (in_array('ladies', $cats, true)) {
                    return 'ladies';
                }
                if (in_array('senior', $cats, true)) {
                    return 'senior';
                }
                return $equipmentDiv;
            };

            $director = User::where('email', 'director@saprf.co.za')->first();
            $admin = User::where('email', 'admin@saprf.co.za')->first();
            $owner = User::where('email', 'owner@saprf.co.za')->first();
            $existingMember = User::where('email', 'member@saprf.co.za')->first();

            $owner?->update(['date_of_birth' => '1980-01-10']);
            $existingMember?->update(['date_of_birth' => '1988-05-20', 'province_id' => $provinces['FS']->id]);

            // ── Shooters (real names from precisionrifle.co.za standings) ──
            // Demo data only. Provinces and DOBs are approximations to populate
            // categories (senior / junior / ladies) correctly.
            $shooterData = [
                // ── Open Division — Top tier ──
                ['name' => 'Warren Britnell',        'email' => 'warren.britnell@example.co.za',     'prov' => 'GP',  'dob' => '1982-04-12', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Donovan Cook',           'email' => 'donovan.cook@example.co.za',        'prov' => 'GP',  'dob' => '1985-08-19', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Malcolm Coetzee',        'email' => 'malcolm.coetzee@example.co.za',     'prov' => 'GP',  'dob' => '1979-11-06', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Kevin Goncalves',        'email' => 'kevin.goncalves@example.co.za',     'prov' => 'GP',  'dob' => '1983-02-23', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Willem Van Biljon',      'email' => 'willem.vanbiljon@example.co.za',    'prov' => 'GP',  'dob' => '1986-07-15', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Jason Marais',           'email' => 'jason.marais@example.co.za',        'prov' => 'GP',  'dob' => '1988-09-28', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Leon Goosen',            'email' => 'leon.goosen@example.co.za',         'prov' => 'GP',  'dob' => '1984-03-09', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Marcel Steyn',           'email' => 'marcel.steyn@example.co.za',        'prov' => 'GP',  'dob' => '1990-12-04', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Dirk Pio',               'email' => 'dirk.pio@example.co.za',            'prov' => 'GP',  'dob' => '1987-05-21', 'div' => 'open',    'cats' => [],         'member' => true],

                // ── Western Cape Open ──
                ['name' => 'Johan Nel',              'email' => 'johan.nel@example.co.za',           'prov' => 'WC',  'dob' => '1981-06-14', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Dennis Van der Merwe',   'email' => 'dennis.vdmerwe@example.co.za',      'prov' => 'WC',  'dob' => '1980-10-02', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Ruan Zeeman',            'email' => 'ruan.zeeman@example.co.za',         'prov' => 'WC',  'dob' => '1989-01-17', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Sean Graham',            'email' => 'sean.graham@example.co.za',         'prov' => 'WC',  'dob' => '1985-04-25', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Gerhard Slabbert',       'email' => 'gerhard.slabbert@example.co.za',    'prov' => 'WC',  'dob' => '1983-08-30', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Angelo Van Zyl',         'email' => 'angelo.vanzyl@example.co.za',       'prov' => 'WC',  'dob' => '1987-11-12', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Clive Mey',              'email' => 'clive.mey@example.co.za',           'prov' => 'WC',  'dob' => '1976-02-08', 'div' => 'open',    'cats' => [],         'member' => true],

                // ── Senior — Centrefire (50+) ──
                ['name' => 'Le Riche Coetzer Snr',   'email' => 'leriche.coetzer@example.co.za',     'prov' => 'WC',  'dob' => '1968-09-04', 'div' => 'open',    'cats' => ['senior'], 'member' => true],
                ['name' => 'Greg Sykes',             'email' => 'greg.sykes@example.co.za',          'prov' => 'GP',  'dob' => '1965-03-22', 'div' => 'open',    'cats' => ['senior'], 'member' => true],
                ['name' => 'Glen Clark',             'email' => 'glen.clark@example.co.za',          'prov' => 'GP',  'dob' => '1967-07-11', 'div' => 'open',    'cats' => ['senior'], 'member' => true],
                ['name' => 'Andries Lategan',        'email' => 'andries.lategan@example.co.za',     'prov' => 'KZN', 'dob' => '1963-05-29', 'div' => 'open',    'cats' => ['senior'], 'member' => true],
                ['name' => 'Riaan Kunneke',          'email' => 'riaan.kunneke@example.co.za',       'prov' => 'LP',  'dob' => '1969-12-18', 'div' => 'open',    'cats' => ['senior'], 'member' => true],
                ['name' => 'Etienne De Waal',        'email' => 'etienne.dewaal@example.co.za',      'prov' => 'WC',  'dob' => '1966-10-07', 'div' => 'open',    'cats' => ['senior'], 'member' => true],

                // ── Factory ──
                ['name' => 'Russell Ferreira',       'email' => 'russell.ferreira@example.co.za',    'prov' => 'GP',  'dob' => '1986-03-08', 'div' => 'factory', 'cats' => [],         'member' => true],
                ['name' => 'Desmond Wellen',         'email' => 'desmond.wellen@example.co.za',      'prov' => 'GP',  'dob' => '1982-09-21', 'div' => 'factory', 'cats' => [],         'member' => true],
                ['name' => 'Hanno Van Niekerk',      'email' => 'hanno.vanniekerk@example.co.za',    'prov' => 'WC',  'dob' => '1988-04-16', 'div' => 'factory', 'cats' => [],         'member' => true],
                ['name' => 'Chris Leeson',           'email' => 'chris.leeson@example.co.za',        'prov' => 'GP',  'dob' => '1984-08-03', 'div' => 'factory', 'cats' => [],         'member' => true],
                ['name' => 'Patrick Capes',          'email' => 'patrick.capes@example.co.za',       'prov' => 'WC',  'dob' => '1987-01-26', 'div' => 'factory', 'cats' => [],         'member' => true],
                ['name' => 'Stelios Christofi',      'email' => 'stelios.christofi@example.co.za',   'prov' => 'KZN', 'dob' => '1985-11-09', 'div' => 'factory', 'cats' => [],         'member' => true],
                ['name' => 'Handre van Niekerk',     'email' => 'handre.vanniekerk@example.co.za',   'prov' => 'WC',  'dob' => '1989-06-30', 'div' => 'factory', 'cats' => [],         'member' => true],
                ['name' => 'AJ Deysel',              'email' => 'aj.deysel@example.co.za',           'prov' => 'FS',  'dob' => '1990-02-13', 'div' => 'factory', 'cats' => [],         'member' => true],
                ['name' => 'Alwyn van Graan',        'email' => 'alwyn.vangraan@example.co.za',      'prov' => 'GP',  'dob' => '1981-07-05', 'div' => 'factory', 'cats' => [],         'member' => true],
                ['name' => 'Dumisani Shabangu',      'email' => 'dumisani.shabangu@example.co.za',   'prov' => 'MP',  'dob' => '1986-10-22', 'div' => 'factory', 'cats' => [],         'member' => true],

                // ── Limited ──
                ['name' => 'Jc Robertson',           'email' => 'jc.robertson@example.co.za',        'prov' => 'GP',  'dob' => '1983-12-11', 'div' => 'limited', 'cats' => [],         'member' => true],
                ['name' => 'Mohsin Tajbhai',         'email' => 'mohsin.tajbhai@example.co.za',      'prov' => 'GP',  'dob' => '1987-05-04', 'div' => 'limited', 'cats' => [],         'member' => true],
                ['name' => 'Devan Nell',             'email' => 'devan.nell@example.co.za',          'prov' => 'GP',  'dob' => '1991-08-19', 'div' => 'limited', 'cats' => [],         'member' => true],
                ['name' => 'Edward Henwood',         'email' => 'edward.henwood@example.co.za',      'prov' => 'WC',  'dob' => '1984-02-27', 'div' => 'limited', 'cats' => [],         'member' => true],
                ['name' => 'Jozef Kriek',            'email' => 'jozef.kriek@example.co.za',         'prov' => 'GP',  'dob' => '1988-11-15', 'div' => 'limited', 'cats' => [],         'member' => true],

                // ── Ladies — GP ──
                ['name' => 'Kim-Leigh Ferreira',     'email' => 'kim.ferreira@example.co.za',        'prov' => 'GP',  'dob' => '1990-06-08', 'div' => 'open',    'cats' => ['ladies'], 'member' => true],
                ['name' => 'Monica Le Roux',         'email' => 'monica.leroux@example.co.za',       'prov' => 'GP',  'dob' => '1988-03-17', 'div' => 'factory', 'cats' => ['ladies'], 'member' => true],
                ['name' => 'Natasha Britnell',       'email' => 'natasha.britnell@example.co.za',    'prov' => 'GP',  'dob' => '1985-10-29', 'div' => 'open',    'cats' => ['ladies'], 'member' => true],
                ['name' => 'Belinda Botha',          'email' => 'belinda.botha@example.co.za',       'prov' => 'GP',  'dob' => '1992-01-14', 'div' => 'limited', 'cats' => ['ladies'], 'member' => true],

                // ── Ladies — WC ──
                ['name' => 'Jeanette Coetzee',       'email' => 'jeanette.coetzee@example.co.za',    'prov' => 'WC',  'dob' => '1987-07-23', 'div' => 'open',    'cats' => ['ladies'], 'member' => true],
                ['name' => 'Liezel Foot',            'email' => 'liezel.foot@example.co.za',         'prov' => 'WC',  'dob' => '1989-04-30', 'div' => 'open',    'cats' => ['ladies'], 'member' => true],
                ['name' => 'Aliza Mey',              'email' => 'aliza.mey@example.co.za',           'prov' => 'WC',  'dob' => '1986-09-18', 'div' => 'factory', 'cats' => ['ladies'], 'member' => true],
                ['name' => 'Liné de Witt',           'email' => 'line.dewitt@example.co.za',         'prov' => 'WC',  'dob' => '1993-12-02', 'div' => 'open',    'cats' => ['ladies'], 'member' => true],
                ['name' => 'Marieke Van Rooyen',     'email' => 'marieke.vanrooyen@example.co.za',   'prov' => 'WC',  'dob' => '1991-05-26', 'div' => 'factory', 'cats' => ['ladies'], 'member' => true],

                // ── Juniors (under 21 on 1 Jan 2026) ──
                ['name' => 'Tinus Cronje',           'email' => 'tinus.cronje@example.co.za',        'prov' => 'WC',  'dob' => '2007-09-12', 'div' => 'open',    'cats' => ['junior'], 'member' => true],
                ['name' => 'Conner Britnell',        'email' => 'conner.britnell@example.co.za',     'prov' => 'GP',  'dob' => '2008-02-25', 'div' => 'open',    'cats' => ['junior'], 'member' => true],
                ['name' => 'Catelynn Britnell',      'email' => 'catelynn.britnell@example.co.za',   'prov' => 'GP',  'dob' => '2010-06-14', 'div' => 'open',    'cats' => ['junior', 'ladies'], 'member' => true],
                ['name' => 'Johan Symington',        'email' => 'johan.symington@example.co.za',     'prov' => 'WC',  'dob' => '2006-11-08', 'div' => 'open',    'cats' => ['junior'], 'member' => true],
                ['name' => 'Lian Van der Merwe',     'email' => 'lian.vdmerwe@example.co.za',        'prov' => 'FS',  'dob' => '2007-08-30', 'div' => 'open',    'cats' => ['junior'], 'member' => true],
                ['name' => 'Erich Van der Merwe',    'email' => 'erich.vdmerwe@example.co.za',       'prov' => 'FS',  'dob' => '2009-04-17', 'div' => 'open',    'cats' => ['junior'], 'member' => true],
                ['name' => 'MC Van Tonder',          'email' => 'mc.vantonder@example.co.za',        'prov' => 'GP',  'dob' => '2011-01-22', 'div' => 'factory', 'cats' => ['junior'], 'member' => true],

                // ── Other provinces ──
                ['name' => 'Terblanche De Jager',    'email' => 'terblanche.dejager@example.co.za',  'prov' => 'FS',  'dob' => '1984-07-19', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Schalk van der Merwe',   'email' => 'schalk.vdmerwe@example.co.za',      'prov' => 'NW',  'dob' => '1986-12-08', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Asharuf Moorad',         'email' => 'asharuf.moorad@example.co.za',      'prov' => 'NC',  'dob' => '1989-03-25', 'div' => 'open',    'cats' => [],         'member' => true],
                ['name' => 'Pieter le Roux',         'email' => 'pieter.leroux@example.co.za',       'prov' => 'MP',  'dob' => '1985-10-11', 'div' => 'factory', 'cats' => [],         'member' => true],

                // ── Non-members ──
                ['name' => 'Trevor Graham',          'email' => 'trevor.graham@example.co.za',       'prov' => 'GP',  'dob' => '1972-08-15', 'div' => 'open',    'cats' => ['senior'], 'member' => false],
                ['name' => 'Danie du Preez',         'email' => 'danie.dupreez@example.co.za',       'prov' => 'WC',  'dob' => '1971-04-03', 'div' => 'open',    'cats' => ['senior'], 'member' => false],
                ['name' => 'Grant Bower',            'email' => 'grant.bower@example.co.za',         'prov' => 'WC',  'dob' => '1983-11-28', 'div' => 'factory', 'cats' => [],         'member' => false],

                // ── Lapsed members ──
                ['name' => 'Jaco Bosman',            'email' => 'jaco.bosman@example.co.za',         'prov' => 'GP',  'dob' => '1980-06-20', 'div' => 'open',    'cats' => [],         'member' => 'lapsed'],
                ['name' => 'Derek Reyneke',          'email' => 'derek.reyneke@example.co.za',       'prov' => 'GP',  'dob' => '1966-02-09', 'div' => 'open',    'cats' => ['senior'], 'member' => 'lapsed'],
            ];

            $allShooters = [];
            $memberCounter = 1001;

            foreach ($shooterData as $data) {
                $user = User::updateOrCreate(
                    ['email' => $data['email']],
                    [
                        'name' => $data['name'],
                        'password' => Hash::make($this->seedPassword($data['email'])),
                        'province_id' => $provinces[$data['prov']]->id,
                        'date_of_birth' => $data['dob'],
                        'email_verified_at' => now(),
                    ],
                );
                $user->assignRole('member');

                $divCode = $resolveDivision($data['div'], $data['cats']);
                $divisionId = $divisions[$divCode]?->id;
                $user->update(['division_id' => $divisionId]);

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
                    'div_code' => $divCode,
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
                    'is_member' => true,
                ];
            }

            // ── 2026 Match Calendar (today: 21 May 2026) ──
            $matchData = [
                // ─────────── PAST PRS Nationals (completed) ───────────
                ['name' => 'Centrefire NW 2-Day National',           'type' => 'PRS', 'level' => 'national', 'prov' => 'NW', 'date' => '2026-02-14', 'end' => '2026-02-15', 'status' => 'completed', 'venue' => 'NW Klerksdorp',              'city' => 'Klerksdorp',   'max' => 40, 'dual_provincial' => true],
                ['name' => 'Centrefire WC 2-Day National — Wolseley','type' => 'PRS', 'level' => 'national', 'prov' => 'WC', 'date' => '2026-03-07', 'end' => '2026-03-08', 'status' => 'completed', 'venue' => 'Romansrivier Wolseley',     'city' => 'Wolseley',     'max' => 40, 'dual_provincial' => true],
                ['name' => 'Centrefire GP 2-Day National',           'type' => 'PRS', 'level' => 'national', 'prov' => 'GP', 'date' => '2026-04-11', 'end' => '2026-04-12', 'status' => 'completed', 'venue' => 'Legends Adventure Farm',   'city' => 'Gauteng',      'max' => 40, 'dual_provincial' => true],
                ['name' => 'Centrefire FS 2-Day National',           'type' => 'PRS', 'level' => 'national', 'prov' => 'FS', 'date' => '2026-05-02', 'end' => '2026-05-03', 'status' => 'completed', 'venue' => 'Bloemfontein Range',        'city' => 'Bloemfontein', 'max' => 36, 'dual_provincial' => true],

                // ─────────── PAST PR22 Nationals (completed) ───────────
                ['name' => 'Rimfire PR22 GP 2-Day National',         'type' => 'PR22','level' => 'national', 'prov' => 'GP', 'date' => '2026-02-28', 'end' => '2026-03-01', 'status' => 'completed', 'venue' => 'Hippo Creek',              'city' => 'Gauteng',      'max' => 30, 'dual_provincial' => true],
                ['name' => 'Rimfire PR22 WC 2-Day National',         'type' => 'PR22','level' => 'national', 'prov' => 'WC', 'date' => '2026-04-04', 'end' => '2026-04-05', 'status' => 'completed', 'venue' => 'Atlantis Shooting Range',  'city' => 'Atlantis',     'max' => 30, 'dual_provincial' => true],

                // ─────────── PAST Provincial (completed) ───────────
                ['name' => 'Centrefire MP Provincial',               'type' => 'PRS', 'level' => 'provincial', 'prov' => 'MP', 'date' => '2026-03-21', 'end' => null,        'status' => 'completed', 'venue' => 'Lydenburg Range',          'city' => 'Lydenburg',    'max' => 25],
                ['name' => 'Rimfire PR22 MP Provincial',             'type' => 'PR22','level' => 'provincial', 'prov' => 'MP', 'date' => '2026-04-11', 'end' => null,        'status' => 'completed', 'venue' => 'Balmoral Hunting Farm',    'city' => 'Balmoral',     'max' => 20],

                // ─────────── UPCOMING — Registration open ───────────
                ['name' => 'Rimfire PR22 GP Provincial — May',       'type' => 'PR22','level' => 'provincial', 'prov' => 'GP', 'date' => '2026-05-30', 'end' => null,        'status' => 'open',      'venue' => "Leopard's Valley",        'city' => 'Gauteng',      'max' => 20],
                ['name' => 'Rimfire PR22 WC Provincial — Jun',       'type' => 'PR22','level' => 'provincial', 'prov' => 'WC', 'date' => '2026-06-14', 'end' => null,        'status' => 'open',      'venue' => 'Atlantis Shooting Range',  'city' => 'Atlantis',     'max' => 20],
                ['name' => 'Centrefire GP Provincial — Jun',         'type' => 'PRS', 'level' => 'provincial', 'prov' => 'GP', 'date' => '2026-06-21', 'end' => null,        'status' => 'open',      'venue' => 'Legends Adventure Farm',  'city' => 'Gauteng',      'max' => 25],
                ['name' => 'Centrefire MP 2-Day National',           'type' => 'PRS', 'level' => 'national',   'prov' => 'MP', 'date' => '2026-07-11', 'end' => '2026-07-12','status' => 'open',      'venue' => 'Lydenburg Range',          'city' => 'Lydenburg',    'max' => 40, 'dual_provincial' => true],
                ['name' => 'Rimfire PR22 LP Provincial',             'type' => 'PR22','level' => 'provincial', 'prov' => 'LP', 'date' => '2026-07-18', 'end' => null,        'status' => 'open',      'venue' => 'Risla Range',              'city' => 'Limpopo',      'max' => 20],

                // ─────────── UPCOMING — Draft (announced, not open) ───────────
                ['name' => 'Centrefire LP 2-Day National',           'type' => 'PRS', 'level' => 'national',   'prov' => 'LP', 'date' => '2026-08-08', 'end' => '2026-08-09','status' => 'draft',     'venue' => 'Marble Hall Range',        'city' => 'Marble Hall',  'max' => 40, 'dual_provincial' => true],
                ['name' => 'Rimfire PR22 GP Provincial — Aug',       'type' => 'PR22','level' => 'provincial', 'prov' => 'GP', 'date' => '2026-08-15', 'end' => null,        'status' => 'draft',     'venue' => 'Legends Adventure Farm',  'city' => 'Gauteng',      'max' => 20],
                ['name' => 'Rimfire PR22 MP 2-Day National',         'type' => 'PR22','level' => 'national',   'prov' => 'MP', 'date' => '2026-08-29', 'end' => '2026-08-30','status' => 'draft',     'venue' => 'Lydenburg Range',          'city' => 'Lydenburg',    'max' => 30, 'dual_provincial' => true],
                ['name' => 'Centrefire WC Provincial',               'type' => 'PRS', 'level' => 'provincial', 'prov' => 'WC', 'date' => '2026-09-05', 'end' => null,        'status' => 'draft',     'venue' => 'Romansrivier Wolseley',    'city' => 'Wolseley',     'max' => 25],
                ['name' => 'Centrefire WC 2-Day National — Darling', 'type' => 'PRS', 'level' => 'national',   'prov' => 'WC', 'date' => '2026-10-24', 'end' => '2026-10-25','status' => 'draft',     'venue' => 'Darling Steel Valley',     'city' => 'Darling',      'max' => 40],
                ['name' => 'Rimfire PR22 GP Championship',           'type' => 'PR22','level' => 'final',      'prov' => 'GP', 'date' => '2026-11-07', 'end' => '2026-11-08','status' => 'draft',     'venue' => 'Hippo Creek',              'city' => 'Gauteng',      'max' => 24],
                ['name' => 'Centrefire GP 2-Day Championship',       'type' => 'PRS', 'level' => 'final',      'prov' => 'GP', 'date' => '2026-11-21', 'end' => '2026-11-22','status' => 'draft',     'venue' => 'Legends Adventure Farm',  'city' => 'Gauteng',      'max' => 30],
            ];

            $completedPrsMatches = [];
            $completedPr22Matches = [];

            foreach ($matchData as $data) {
                $regOpen = date('Y-m-d', strtotime($data['date'] . ' -30 days'));
                $regClose = date('Y-m-d', strtotime($data['date'] . ' -5 days'));
                $fee = $data['level'] === 'provincial' ? 350.00 : 500.00;
                $nonMemberSurcharge = (float) app(\App\Services\SettingsService::class)->get('non_member_surcharge', 250);
                $lapsedSurcharge = (float) app(\App\Services\SettingsService::class)->get('lapsed_member_surcharge', 150);

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
                    'non_member_fee' => $fee + $nonMemberSurcharge,
                    'lapsed_member_fee' => $fee + $lapsedSurcharge,
                    'max_competitors' => $data['max'],
                    'waitlist_enabled' => $data['max'] <= 30,
                    'published' => true,
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
            // PRS annual "national log": best 3 regular (national) match %s plus
            // a fixed, non-droppable SA Champs (final) %. Max = 400. Only
            // national + final matches count; no provincial dimension.
            QualificationRule::firstOrCreate(
                ['series' => 'PRS', 'season' => '2026'],
                [
                    'scoring_mode' => 'best_n_plus_champs',
                    'min_out_of_province_matches' => 0,
                    'best_of_count' => 3,
                    'total_qualifying_matches' => 4,
                    'created_by' => $admin->id,
                ],
            );

            // PR22 uses the weighted 3-pool model as decided by the chair:
            //   Best 3 provincial (30%) + Best 2 nationals (40%) + SA Champs (30%) = /100
            // National pool: best 2 nationals are summed (no drop-one). A single
            // national still counts as the shooter's best-1 (scored out of best_of),
            // i.e. it is not dropped — hence national_pool_min_matches = 1.
            QualificationRule::firstOrCreate(
                ['series' => 'PR22', 'season' => '2026'],
                [
                    'scoring_mode' => 'weighted_pools',
                    'min_out_of_province_matches' => 1,
                    'best_of_count' => null,
                    'total_qualifying_matches' => 6,
                    'provincial_pool_best_of' => 3,
                    'provincial_pool_weight_pct' => 30.00,
                    'national_pool_best_of' => 2,
                    'national_pool_weight_pct' => 40.00,
                    'national_pool_min_matches' => 1,
                    'champs_pool_best_of' => 1,
                    'champs_pool_weight_pct' => 30.00,
                    'created_by' => $admin->id,
                ],
            );

            // ── Generate Scores for Completed Matches ──
            $prsShooters = collect($allShooters);
            $pr22Shooters = collect($allShooters);

            $standingsService = app(StandingsCalculationService::class);
            $seedIndex = 42;

            foreach ($completedPrsMatches as $match) {
                $this->seedMatchScores($match, $prsShooters->values(), $divisions, $seedIndex);
                $standingsService->recalculateForMatch($match);
                $seedIndex += 7;
            }

            foreach ($completedPr22Matches as $match) {
                $this->seedMatchScores($match, $pr22Shooters->values(), $divisions, $seedIndex);
                $standingsService->recalculateForMatch($match);
                $seedIndex += 7;
            }
        });
    }

    private function seedMatchScores($match, $shooters, $divisions, int $seed): void
    {
        $rng = mt_rand(0, 100);
        mt_srand($seed);

        $isTwoDay = $match->isMultiDay();

        foreach ($shooters as $idx => $shooter) {
            $user = $shooter['user'];
            $divCode = $shooter['div_code'];
            $divisionId = $divisions[$divCode]?->id;

            $baseScore = 55 - ($idx * 0.8);

            // For 2-day matches, generate independent day 1 + day 2 totals so
            // seeded data exercises the new day1/day2 columns and the
            // provincial-credit-from-day-1 logic under PR22 pooled scoring.
            if ($isTwoDay) {
                $variance1 = (mt_rand(-20, 20)) / 10;
                $variance2 = (mt_rand(-20, 20)) / 10;
                $day1 = max(2.5, min(30, round(($baseScore / 2) + $variance1, 1)));
                $day2 = max(2.5, min(30, round(($baseScore / 2) + $variance2, 1)));

                $score = Score::create([
                    'match_id' => $match->id,
                    'shooter_name' => $user->name,
                    'user_id' => $user->id,
                    'day1_raw_score' => $day1,
                    'day2_raw_score' => $day2,
                    // raw_score + provincial_raw_score auto-computed by the Score booted() hook.
                    'placement' => null,
                    'division_id' => $divisionId,
                    'is_member' => $shooter['is_member'],
                    'status' => 'valid',
                    'match_date' => $match->match_date,
                    'counts_for_season' => true,
                ]);
            } else {
                $variance = (mt_rand(-30, 30)) / 10;
                $rawScore = max(5, min(60, round($baseScore + $variance, 1)));

                $score = Score::create([
                    'match_id' => $match->id,
                    'shooter_name' => $user->name,
                    'user_id' => $user->id,
                    'day1_raw_score' => $rawScore,
                    // 1-day match: only day1, so raw_score = day1 via the hook.
                    'placement' => null,
                    'division_id' => $divisionId,
                    'is_member' => $shooter['is_member'],
                    'status' => 'valid',
                    'match_date' => $match->match_date,
                    'counts_for_season' => true,
                ]);
            }
        }

        mt_srand();
    }
}
