<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ProvinceSeeder::class,
            SettingsSeeder::class,
            RolesAndUsersSeeder::class,
            SponsorTierSeeder::class,
            FirearmReferenceSeeder::class,
            OpticReferenceSeeder::class,
            DivisionCategorySeeder::class,
            FederationDemoSeeder::class,
        ]);
    }
}
