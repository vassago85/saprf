<?php

namespace Database\Seeders;

use App\Models\SponsorTier;
use Illuminate\Database\Seeder;

class SponsorTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'name' => 'Platinum',
                'slug' => 'platinum',
                'display_order' => 1,
                'price_per_year' => 25000.00,
                'logo_max_height' => 80,
                'placement' => ['landing_hero', 'landing_section', 'app_sidebar', 'match_pages', 'standings_pages'],
                'is_active' => true,
            ],
            [
                'name' => 'Gold',
                'slug' => 'gold',
                'display_order' => 2,
                'price_per_year' => 15000.00,
                'logo_max_height' => 60,
                'placement' => ['landing_section', 'match_pages', 'standings_pages'],
                'is_active' => true,
            ],
            [
                'name' => 'Silver',
                'slug' => 'silver',
                'display_order' => 3,
                'price_per_year' => 7500.00,
                'logo_max_height' => 40,
                'placement' => ['landing_section'],
                'is_active' => true,
            ],
        ];

        foreach ($tiers as $tier) {
            SponsorTier::updateOrCreate(
                ['slug' => $tier['slug']],
                $tier,
            );
        }
    }
}
