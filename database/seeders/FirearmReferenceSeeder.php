<?php

namespace Database\Seeders;

use App\Models\FirearmCalibre;
use App\Models\FirearmMake;
use App\Models\FirearmModel;
use Illuminate\Database\Seeder;

class FirearmReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $calibres = [
            ['name' => '6mm Creedmoor', 'family' => 'Creedmoor', 'bullet_diameter' => 6.170],
            ['name' => '6.5 Creedmoor', 'family' => 'Creedmoor', 'bullet_diameter' => 6.710],
            ['name' => '6.5 PRC', 'family' => 'PRC', 'bullet_diameter' => 6.710],
            ['name' => '6.5x47 Lapua', 'family' => 'Lapua', 'bullet_diameter' => 6.710],
            ['name' => '.308 Winchester', 'family' => 'Winchester', 'bullet_diameter' => 7.820],
            ['name' => '.300 Winchester Magnum', 'family' => 'Winchester Magnum', 'bullet_diameter' => 7.820],
            ['name' => '.300 PRC', 'family' => 'PRC', 'bullet_diameter' => 7.820],
            ['name' => '.338 Lapua Magnum', 'family' => 'Lapua', 'bullet_diameter' => 8.610],
            ['name' => '.223 Remington', 'family' => 'Remington', 'bullet_diameter' => 5.700],
            ['name' => '.22 LR', 'family' => 'Rimfire', 'bullet_diameter' => 5.700],
            ['name' => '.224 Valkyrie', 'family' => 'Federal', 'bullet_diameter' => 5.700],
            ['name' => '6mm BR', 'family' => 'BR', 'bullet_diameter' => 6.170],
            ['name' => '6mm Dasher', 'family' => 'BR', 'bullet_diameter' => 6.170],
            ['name' => '6mm GT', 'family' => 'GT', 'bullet_diameter' => 6.170],
            ['name' => '6.5-284 Norma', 'family' => 'Norma', 'bullet_diameter' => 6.710],
            ['name' => '.260 Remington', 'family' => 'Remington', 'bullet_diameter' => 6.710],
            ['name' => '.243 Winchester', 'family' => 'Winchester', 'bullet_diameter' => 6.170],
            ['name' => '7mm Remington Magnum', 'family' => 'Remington Magnum', 'bullet_diameter' => 7.214],
            ['name' => '.375 CheyTac', 'family' => 'CheyTac', 'bullet_diameter' => 9.550],
        ];

        foreach ($calibres as $c) {
            FirearmCalibre::updateOrCreate(
                ['name' => $c['name']],
                ['category' => 'rifle', 'family' => $c['family'], 'bullet_diameter' => $c['bullet_diameter']],
            );
        }

        $makes = [
            ['name' => 'Tikka', 'country' => 'Finland'],
            ['name' => 'Howa', 'country' => 'Japan'],
            ['name' => 'Bergara', 'country' => 'Spain'],
            ['name' => 'Remington', 'country' => 'USA'],
            ['name' => 'Ruger', 'country' => 'USA'],
            ['name' => 'Savage', 'country' => 'USA'],
            ['name' => 'Weatherby', 'country' => 'USA'],
            ['name' => 'CZ', 'country' => 'Czech Republic'],
            ['name' => 'Sako', 'country' => 'Finland'],
            ['name' => 'Accuracy International', 'country' => 'UK'],
            ['name' => 'Barrett', 'country' => 'USA'],
            ['name' => 'Desert Tech', 'country' => 'USA'],
            ['name' => 'Seekins Precision', 'country' => 'USA'],
            ['name' => 'Christensen Arms', 'country' => 'USA'],
            ['name' => 'Victrix', 'country' => 'Italy'],
            ['name' => 'Cadex', 'country' => 'Canada'],
            ['name' => 'Truvelo', 'country' => 'South Africa'],
            ['name' => 'Musgrave', 'country' => 'South Africa'],
            ['name' => 'Defiance Machine', 'country' => 'USA'],
            ['name' => 'Bighorn Arms', 'country' => 'USA'],
            ['name' => 'Zermatt Arms', 'country' => 'USA'],
            ['name' => 'Impact Precision', 'country' => 'USA'],
            ['name' => 'Terminus Actions', 'country' => 'USA'],
            ['name' => 'CZ (Rimfire)', 'country' => 'Czech Republic'],
            ['name' => 'Vudoo Gun Works', 'country' => 'USA'],
            ['name' => 'Lithgow Arms', 'country' => 'Australia'],
        ];

        foreach ($makes as $m) {
            FirearmMake::updateOrCreate(['name' => $m['name']], ['country' => $m['country']]);
        }

        $models = [
            'Tikka' => ['T3x TAC A1', 'T3x CTR', 'T3x UPR', 'T3x Compact Tactical', 'T3x Lite', 'T3x Varmint'],
            'Howa' => ['1500', '1500 Mini Action', '1500 Barrelled Action', 'M1500 HCR', 'M1500 Oryx'],
            'Bergara' => ['B-14 HMR', 'B-14 BMP', 'B-14 Crest', 'Premier HMR Pro', 'Premier Ridgeback', 'B-14 Squared Crest'],
            'Remington' => ['700', '700 Long Range', '700 PCR', '700 SPS Tactical'],
            'Ruger' => ['Precision Rifle', 'American Predator', 'Hawkeye Long-Range Target', 'Precision Rimfire'],
            'Savage' => ['110 Tactical', '110 Precision', '110 Elite Precision', 'B22 Precision', 'Mark II'],
            'CZ' => ['457', '457 Varmint', '600 Range', '600 Trail'],
            'Accuracy International' => ['AT-X', 'AX', 'AXMC', 'AW'],
            'Desert Tech' => ['SRS A2', 'SRS A2 Covert'],
            'Vudoo Gun Works' => ['V-22', 'Ravage'],
            'Truvelo' => ['CMS', 'Counter Measure Sniper'],
            'Musgrave' => ['RSA Target', 'Musgrave 98'],
        ];

        foreach ($models as $makeName => $modelNames) {
            $make = FirearmMake::where('name', $makeName)->first();
            if (! $make) continue;

            foreach ($modelNames as $modelName) {
                FirearmModel::updateOrCreate(
                    ['firearm_make_id' => $make->id, 'name' => $modelName],
                    ['is_active' => true],
                );
            }
        }
    }
}
