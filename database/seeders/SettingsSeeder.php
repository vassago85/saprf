<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::firstOrCreate(
            ['key' => 'annual_membership_fee'],
            ['value' => '500.00', 'description' => 'Annual membership fee (ZAR)'],
        );

        Setting::firstOrCreate(
            ['key' => 'non_member_surcharge'],
            ['value' => '250.00', 'description' => 'Extra fee for non-members per match (ZAR)'],
        );

        Setting::firstOrCreate(
            ['key' => 'lapsed_member_surcharge'],
            ['value' => '150.00', 'description' => 'Extra fee for lapsed members per match (ZAR)'],
        );
    }
}
