<?php

namespace Database\Seeders;

use App\Models\MembershipFeeTier;
use Illuminate\Database\Seeder;

class MembershipFeeTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'slug' => 'adult',
                'name' => 'Adult',
                'description' => 'Standard adult membership.',
                'price' => 850.00,
                'display_order' => 1,
                'is_default' => true,
            ],
            [
                'slug' => 'military-law-enforcement',
                'name' => 'Military / Law Enforcement Officer',
                'description' => 'Discounted rate for serving military and law enforcement officers.',
                'price' => 425.00,
                'display_order' => 2,
                'is_default' => false,
                'is_active' => false,
            ],
            [
                'slug' => 'senior',
                'name' => 'Senior',
                'description' => 'Discounted rate for senior members.',
                'price' => 425.00,
                'display_order' => 3,
                'is_default' => false,
            ],
        ];

        foreach ($tiers as $tier) {
            MembershipFeeTier::firstOrCreate(
                ['slug' => $tier['slug']],
                [
                    'name' => $tier['name'],
                    'description' => $tier['description'],
                    'price' => $tier['price'],
                    'duration_months' => 12,
                    'display_order' => $tier['display_order'],
                    'is_active' => $tier['is_active'] ?? true,
                    'is_default' => $tier['is_default'],
                ],
            );
        }
    }
}
