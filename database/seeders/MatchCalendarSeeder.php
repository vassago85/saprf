<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Idempotent seeder for the 2026 demo match calendar.
 *
 * Carved out of FederationDemoSeeder so the match data can be seeded on prod
 * without needing the (collision-prone) membership/score demo data.
 */
class MatchCalendarSeeder extends Seeder
{
    public function run(): void
    {
        $provinces = Province::all()->keyBy('abbreviation');
        $divisions = Division::all();

        $director = User::where('email', 'director@saprf.co.za')->first();
        if (! $director) {
            $this->command?->warn('director@saprf.co.za not found — run RolesAndUsersSeeder first.');
            return;
        }

        $nonMemberSurcharge = (float) app(SettingsService::class)->get('non_member_surcharge', 250);
        $lapsedSurcharge = (float) app(SettingsService::class)->get('lapsed_member_surcharge', 150);

        $matchData = [
            ['name' => 'Centrefire NW 2-Day National',           'type' => 'PRS', 'level' => 'national',   'prov' => 'NW', 'date' => '2026-02-14', 'end' => '2026-02-15', 'status' => 'completed', 'venue' => 'NW Klerksdorp',              'city' => 'Klerksdorp',   'max' => 40, 'dual_provincial' => true],
            ['name' => 'Centrefire WC 2-Day National — Wolseley','type' => 'PRS', 'level' => 'national',   'prov' => 'WC', 'date' => '2026-03-07', 'end' => '2026-03-08', 'status' => 'completed', 'venue' => 'Romansrivier Wolseley',     'city' => 'Wolseley',     'max' => 40, 'dual_provincial' => true],
            ['name' => 'Centrefire GP 2-Day National',           'type' => 'PRS', 'level' => 'national',   'prov' => 'GP', 'date' => '2026-04-11', 'end' => '2026-04-12', 'status' => 'completed', 'venue' => 'Legends Adventure Farm',   'city' => 'Gauteng',      'max' => 40, 'dual_provincial' => true],
            ['name' => 'Centrefire FS 2-Day National',           'type' => 'PRS', 'level' => 'national',   'prov' => 'FS', 'date' => '2026-05-02', 'end' => '2026-05-03', 'status' => 'completed', 'venue' => 'Bloemfontein Range',        'city' => 'Bloemfontein', 'max' => 36, 'dual_provincial' => true],
            ['name' => 'Rimfire PR22 GP 2-Day National',         'type' => 'PR22','level' => 'national',   'prov' => 'GP', 'date' => '2026-02-28', 'end' => '2026-03-01', 'status' => 'completed', 'venue' => 'Hippo Creek',              'city' => 'Gauteng',      'max' => 30],
            ['name' => 'Rimfire PR22 WC 2-Day National',         'type' => 'PR22','level' => 'national',   'prov' => 'WC', 'date' => '2026-04-04', 'end' => '2026-04-05', 'status' => 'completed', 'venue' => 'Atlantis Shooting Range',  'city' => 'Atlantis',     'max' => 30],
            ['name' => 'Centrefire MP Provincial',               'type' => 'PRS', 'level' => 'provincial', 'prov' => 'MP', 'date' => '2026-03-21', 'end' => null,        'status' => 'completed', 'venue' => 'Lydenburg Range',          'city' => 'Lydenburg',    'max' => 25],
            ['name' => 'Rimfire PR22 MP Provincial',             'type' => 'PR22','level' => 'provincial', 'prov' => 'MP', 'date' => '2026-04-11', 'end' => null,        'status' => 'completed', 'venue' => 'Balmoral Hunting Farm',    'city' => 'Balmoral',     'max' => 20],
            ['name' => 'Rimfire PR22 GP Provincial — May',       'type' => 'PR22','level' => 'provincial', 'prov' => 'GP', 'date' => '2026-05-30', 'end' => null,        'status' => 'open',      'venue' => "Leopard's Valley",        'city' => 'Gauteng',      'max' => 20],
            ['name' => 'Rimfire PR22 WC Provincial — Jun',       'type' => 'PR22','level' => 'provincial', 'prov' => 'WC', 'date' => '2026-06-14', 'end' => null,        'status' => 'open',      'venue' => 'Atlantis Shooting Range',  'city' => 'Atlantis',     'max' => 20],
            ['name' => 'Centrefire GP Provincial — Jun',         'type' => 'PRS', 'level' => 'provincial', 'prov' => 'GP', 'date' => '2026-06-21', 'end' => null,        'status' => 'open',      'venue' => 'Legends Adventure Farm',  'city' => 'Gauteng',      'max' => 25],
            ['name' => 'Centrefire MP 2-Day National',           'type' => 'PRS', 'level' => 'national',   'prov' => 'MP', 'date' => '2026-07-11', 'end' => '2026-07-12','status' => 'open',      'venue' => 'Lydenburg Range',          'city' => 'Lydenburg',    'max' => 40, 'dual_provincial' => true],
            ['name' => 'Rimfire PR22 LP Provincial',             'type' => 'PR22','level' => 'provincial', 'prov' => 'LP', 'date' => '2026-07-18', 'end' => null,        'status' => 'open',      'venue' => 'Risla Range',              'city' => 'Limpopo',      'max' => 20],
            ['name' => 'Centrefire LP 2-Day National',           'type' => 'PRS', 'level' => 'national',   'prov' => 'LP', 'date' => '2026-08-08', 'end' => '2026-08-09','status' => 'draft',     'venue' => 'Marble Hall Range',        'city' => 'Marble Hall',  'max' => 40, 'dual_provincial' => true],
            ['name' => 'Rimfire PR22 GP Provincial — Aug',       'type' => 'PR22','level' => 'provincial', 'prov' => 'GP', 'date' => '2026-08-15', 'end' => null,        'status' => 'draft',     'venue' => 'Legends Adventure Farm',  'city' => 'Gauteng',      'max' => 20],
            ['name' => 'Rimfire PR22 MP 2-Day National',         'type' => 'PR22','level' => 'national',   'prov' => 'MP', 'date' => '2026-08-29', 'end' => '2026-08-30','status' => 'draft',     'venue' => 'Lydenburg Range',          'city' => 'Lydenburg',    'max' => 30],
            ['name' => 'Centrefire WC Provincial',               'type' => 'PRS', 'level' => 'provincial', 'prov' => 'WC', 'date' => '2026-09-05', 'end' => null,        'status' => 'draft',     'venue' => 'Romansrivier Wolseley',    'city' => 'Wolseley',     'max' => 25],
            ['name' => 'Centrefire WC 2-Day National — Darling', 'type' => 'PRS', 'level' => 'national',   'prov' => 'WC', 'date' => '2026-10-24', 'end' => '2026-10-25','status' => 'draft',     'venue' => 'Darling Steel Valley',     'city' => 'Darling',      'max' => 40],
            ['name' => 'Rimfire PR22 GP Championship',           'type' => 'PR22','level' => 'final',      'prov' => 'GP', 'date' => '2026-11-07', 'end' => '2026-11-08','status' => 'draft',     'venue' => 'Hippo Creek',              'city' => 'Gauteng',      'max' => 24],
            ['name' => 'Centrefire GP 2-Day Championship',       'type' => 'PRS', 'level' => 'final',      'prov' => 'GP', 'date' => '2026-11-21', 'end' => '2026-11-22','status' => 'draft',     'venue' => 'Legends Adventure Farm',  'city' => 'Gauteng',      'max' => 30],
        ];

        $created = 0;
        $existing = 0;

        foreach ($matchData as $data) {
            $province = $provinces[$data['prov']] ?? null;
            if (! $province) {
                continue;
            }

            $regOpen = date('Y-m-d', strtotime($data['date'] . ' -30 days'));
            $regClose = date('Y-m-d', strtotime($data['date'] . ' -5 days'));
            $fee = $data['level'] === 'provincial' ? 350.00 : 500.00;

            $matchAttrs = [
                'match_type' => $data['type'],
                'series_level' => $data['level'],
                'series' => $data['type'],
                'season' => '2026',
                'province_id' => $province->id,
                'venue_name' => $data['venue'],
                'venue_location' => $data['city'] . ', ' . $province->name,
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
                'category_rankings_enabled' => true,
                'division_awards_enabled' => true,
            ];

            if (! empty($data['dual_provincial'])) {
                $matchAttrs['also_counts_for_provincial'] = true;
                $matchAttrs['provincial_stage_columns'] = 'stage_1,stage_2,stage_3,stage_4,stage_5,stage_6,stage_7,stage_8,stage_9,stage_10';
            }

            $match = MatchEvent::firstOrCreate(['name' => $data['name']], $matchAttrs);

            if ($match->wasRecentlyCreated) {
                $created++;
            } else {
                $existing++;
            }

            if ($divisions->isNotEmpty()) {
                $match->divisions()->syncWithoutDetaching($divisions->pluck('id'));
            }
        }

        $this->command?->info("MatchCalendarSeeder: {$created} created, {$existing} already present.");
    }
}
