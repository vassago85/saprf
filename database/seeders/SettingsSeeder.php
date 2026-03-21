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

        // Season & Classification Settings
        Setting::firstOrCreate(
            ['key' => 'season_locked_age_categories'],
            ['value' => '1', 'description' => 'Lock age-based categories for the full season (1=yes, 0=no)'],
        );

        Setting::firstOrCreate(
            ['key' => 'age_classification_date_mode'],
            ['value' => 'first_day_of_calendar_year', 'description' => 'How to determine the age classification date (first_day_of_calendar_year, season_start_date, custom_date)'],
        );

        Setting::firstOrCreate(
            ['key' => 'age_classification_custom_date'],
            ['value' => '', 'description' => 'Custom classification date (YYYY-MM-DD) used when mode is custom_date'],
        );

        Setting::firstOrCreate(
            ['key' => 'prs_junior_max_age'],
            ['value' => '21', 'description' => 'PRS junior category: maximum age (shooter must be below this on classification date)'],
        );

        Setting::firstOrCreate(
            ['key' => 'pr22_junior_max_age'],
            ['value' => '18', 'description' => 'PR22 junior category: maximum age (shooter must be below this on classification date)'],
        );

        Setting::firstOrCreate(
            ['key' => 'senior_min_age'],
            ['value' => '55', 'description' => 'Senior category: minimum age on classification date'],
        );

        Setting::firstOrCreate(
            ['key' => 'super_senior_min_age'],
            ['value' => '65', 'description' => 'Super Senior category: minimum age on classification date'],
        );

        Setting::firstOrCreate(
            ['key' => 'sub_junior_max_age'],
            ['value' => '14', 'description' => 'Sub-Junior category: maximum age on classification date'],
        );

        // Division & Category Rules
        Setting::firstOrCreate(
            ['key' => 'category_multi_select'],
            ['value' => '1', 'description' => 'Allow shooters to have multiple categories per match (1=yes, 0=no)'],
        );

        Setting::firstOrCreate(
            ['key' => 'division_single_select_per_discipline'],
            ['value' => '1', 'description' => 'Restrict shooter to one division per discipline per match (1=yes, 0=no)'],
        );

        Setting::firstOrCreate(
            ['key' => 'category_rankings_enabled'],
            ['value' => '1', 'description' => 'Enable category-based standings and rankings (1=yes, 0=no)'],
        );

        Setting::firstOrCreate(
            ['key' => 'division_awards_enabled'],
            ['value' => '1', 'description' => 'Enable division awards and placements (1=yes, 0=no)'],
        );

        Setting::firstOrCreate(
            ['key' => 'category_awards_enabled'],
            ['value' => '0', 'description' => 'Enable category awards and placements (1=yes, 0=no)'],
        );
    }
}
