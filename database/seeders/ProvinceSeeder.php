<?php

namespace Database\Seeders;

use App\Models\Province;
use Illuminate\Database\Seeder;

class ProvinceSeeder extends Seeder
{
    public function run(): void
    {
        $provinces = [
            ['name' => 'Eastern Cape', 'abbreviation' => 'EC'],
            ['name' => 'Free State', 'abbreviation' => 'FS'],
            ['name' => 'Gauteng', 'abbreviation' => 'GP'],
            ['name' => 'KwaZulu-Natal', 'abbreviation' => 'KZN'],
            ['name' => 'Limpopo', 'abbreviation' => 'LP'],
            ['name' => 'Mpumalanga', 'abbreviation' => 'MP'],
            ['name' => 'North West', 'abbreviation' => 'NW'],
            ['name' => 'Northern Cape', 'abbreviation' => 'NC'],
            ['name' => 'Western Cape', 'abbreviation' => 'WC'],
        ];

        foreach ($provinces as $province) {
            Province::firstOrCreate(
                ['name' => $province['name']],
                ['abbreviation' => $province['abbreviation']],
            );
        }
    }
}
