<?php

namespace Database\Seeders;

use App\Models\OpticMake;
use App\Models\OpticModel;
use Illuminate\Database\Seeder;

class OpticReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $brands = [
            [
                'name' => 'Nightforce',
                'country' => 'USA',
                'models' => [
                    'ATACR 1-8x24 F1',
                    'ATACR 4-16x42 F1',
                    'ATACR 4-16x50 F1',
                    'ATACR 5-25x56 F1',
                    'ATACR 7-35x56 F1',
                    'NX8 1-8x24 F1',
                    'NX8 2.5-20x50 F1',
                    'NX8 2.5-20x50 F2',
                    'NX8 4-32x50 F1',
                    'NX8 4-32x50 F2',
                    'NXS 2.5-10x42',
                    'NXS 3.5-15x50',
                    'NXS 5.5-22x50',
                    'NXS 5.5-22x56',
                    'NXS 8-32x56',
                    'Competition 15-55x52',
                    'Benchrest 8-32x56',
                    'Benchrest 12-42x56',
                    'SHV 4-14x50 F1',
                    'SHV 4-14x56',
                    'SHV 5-20x56',
                ],
            ],
            [
                'name' => 'Vortex',
                'country' => 'USA',
                'models' => [
                    'Razor HD Gen III 6-36x56 FFP',
                    'Razor HD Gen III 4.5-27x56 FFP',
                    'Razor HD Gen III 3-18x50 FFP',
                    'Razor HD Gen II 4.5-27x56 FFP',
                    'Razor HD Gen II-E 1-6x24',
                    'Razor HD LHT 3-15x42',
                    'Razor HD LHT 4.5-22x50',
                    'Viper PST Gen II 1-6x24 FFP',
                    'Viper PST Gen II 2-10x32 FFP',
                    'Viper PST Gen II 3-15x44 FFP',
                    'Viper PST Gen II 5-25x50 FFP',
                    'Viper HD 5-25x50 FFP',
                    'Strike Eagle 5-25x56 FFP',
                    'Diamondback Tactical 6-24x50 FFP',
                ],
            ],
            [
                'name' => 'Zero Compromise (ZCO)',
                'country' => 'Austria',
                'models' => [
                    'ZC210 2-10x30',
                    'ZC420 4-20x50',
                    'ZC527 5-27x56',
                    'ZC840 8-40x56',
                ],
            ],
            [
                'name' => 'Arken Optics',
                'country' => 'USA',
                'models' => [
                    'EP-5 5-25x56 Gen II',
                    'EP-5 7-35x56 Gen II',
                    'SH-4 4-16x50 Gen II',
                    'SH-4J 6-24x50',
                ],
            ],
            [
                'name' => 'Athlon Optics',
                'country' => 'USA',
                'models' => [
                    'Ares ETR UHD 1-10x24',
                    'Ares ETR UHD 3-18x50',
                    'Ares ETR UHD 4.5-30x56',
                    'Ares BTR Gen III 2.5-15x50',
                    'Ares BTR Gen III 4.5-27x50',
                    'Cronus BTR Gen II 1-6x24',
                    'Cronus BTR Gen II 4.5-29x56',
                    'Helos BTR Gen II 2-12x42',
                    'Helos BTR Gen II 4-20x50',
                    'Helos BTR Gen II 6-24x56',
                    'Midas TAC Gen II 4-16x44',
                    'Midas TAC Gen II 6-24x50',
                    'Midas TAC Gen II 5-30x56',
                ],
            ],
            [
                'name' => 'Apex Optics',
                'country' => 'Canada',
                'models' => [
                    'RIVALX 4-32x56 CLR',
                    'RIVAL 4-32x56 CLR',
                    'Hunter 3-15x44',
                    'Hunter 3-15x44 Illuminated',
                    'EDGE 1-10x24',
                ],
            ],
            [
                'name' => 'Swarovski',
                'country' => 'Austria',
                'models' => [
                    'X5(i) 3.5-18x50',
                    'X5(i) 5-25x56',
                    'Z8i 1-8x24',
                    'Z8i 1.7-13.3x42 P',
                    'Z8i 2-16x50 P',
                    'Z8i 2.3-18x56 P',
                    'Z8i 3.5-28x50 P',
                    'Z8i+ 0.75-6x20',
                    'Z8i+ 1-8x24',
                    'Z8i+ 5-40x56 P',
                    'Z5 3.5-18x44',
                    'Z5 5-25x52 P',
                ],
            ],
            [
                'name' => 'Burris',
                'country' => 'USA',
                'models' => [
                    'XTR III 3.3-18x50',
                    'XTR III 5.5-30x56',
                    'Veracity PH 2.5-12x42',
                    'Veracity PH 3-15x44',
                    'Veracity PH 4-20x50',
                    'Veracity 2.5-12x42',
                    'Veracity 3-15x44',
                    'Veracity 5-25x50',
                ],
            ],
            [
                'name' => 'Kahles',
                'country' => 'Austria',
                'models' => [
                    'K525i 5-25x56',
                    'K525i DLR 5-25x56',
                    'K525i Refined 5-25x56',
                    'K540i DLR 5-40x56',
                    'K328i DLR 3.5-28x50',
                    'K318i 3.5-18x50',
                    'K624i 6-24x56',
                ],
            ],
            [
                'name' => 'Leupold',
                'country' => 'USA',
                'models' => [
                    'Mark 5HD 5-25x56',
                    'Mark 5HD 3.6-18x44',
                    'Mark 5HD 7-35x56',
                    'Mark 4HD 4.5-18x52',
                    'Mark 4HD 6-24x52',
                    'VX-6HD 3-18x50',
                    'VX-5HD 3-15x44',
                ],
            ],
            [
                'name' => 'Schmidt & Bender',
                'country' => 'Germany',
                'models' => [
                    'PM II 5-25x56',
                    'PM II 5-45x56',
                    'PM II 3-27x56',
                    'PM II 1-8x24',
                    'PM II Ultra Short 3-20x50',
                    'Exos 1-8x24',
                ],
            ],
            [
                'name' => 'Tangent Theta',
                'country' => 'Canada',
                'models' => [
                    'TT525P 5-25x56',
                    'TT315M 3-15x50',
                    'TT315P 3-15x50',
                ],
            ],
            [
                'name' => 'Maven',
                'country' => 'USA',
                'models' => [
                    'RS.5 5-30x56 FFP',
                    'RS.4 5-30x56 FFP',
                ],
            ],
            [
                'name' => 'Bushnell',
                'country' => 'USA',
                'models' => [
                    'Match Pro ED 5-30x56 FFP',
                    'Match Pro 6-24x50 FFP',
                    'XRS3 4.5-30x56',
                ],
            ],
            [
                'name' => 'Primary Arms',
                'country' => 'USA',
                'models' => [
                    'GLx 4-16x50 FFP',
                    'GLx 6-24x50 FFP',
                    'PLx 6-30x56 FFP',
                ],
            ],
            [
                'name' => 'Steiner',
                'country' => 'Germany',
                'models' => [
                    'M7Xi 4-28x56',
                    'T6Xi 5-30x56',
                    'M5Xi 5-25x56',
                ],
            ],
            [
                'name' => 'Tract Optics',
                'country' => 'USA',
                'models' => [
                    'TORIC UHD 4.5-30x56',
                    'TORIC UHD 3-18x50',
                ],
            ],
        ];

        $makeCount = 0;
        $modelCount = 0;

        foreach ($brands as $brand) {
            $make = OpticMake::firstOrCreate(
                ['name' => $brand['name']],
                ['country' => $brand['country'], 'is_active' => true],
            );
            $makeCount++;

            foreach ($brand['models'] as $modelName) {
                OpticModel::firstOrCreate(
                    ['optic_make_id' => $make->id, 'name' => $modelName],
                    ['is_active' => true],
                );
                $modelCount++;
            }
        }

        $this->command->info("Seeded {$makeCount} optic brands with {$modelCount} models.");
    }
}
