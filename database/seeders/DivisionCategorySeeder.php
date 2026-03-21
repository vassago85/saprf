<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Division;
use Illuminate\Database\Seeder;

class DivisionCategorySeeder extends Seeder
{
    public function run(): void
    {
        $divisions = [
            ['code' => 'open', 'name' => 'Open', 'discipline' => 'PRS', 'description' => 'Unrestricted equipment class', 'display_order' => 1],
            ['code' => 'production', 'name' => 'Production', 'discipline' => 'PRS', 'description' => 'Factory rifle with limited modifications', 'display_order' => 2],
            ['code' => 'factory', 'name' => 'Factory', 'discipline' => 'PRS', 'description' => 'Completely factory-stock rifle', 'display_order' => 3],
            ['code' => 'gas-gun', 'name' => 'Gas Gun', 'discipline' => 'PRS', 'description' => 'Semi-automatic gas-operated rifles', 'display_order' => 4],
            ['code' => 'heavy', 'name' => 'Heavy', 'discipline' => 'PRS', 'description' => 'Heavy barrel / unrestricted weight class', 'display_order' => 5],
            ['code' => 'limited', 'name' => 'Limited', 'discipline' => 'PRS', 'description' => 'Limited modifications allowed', 'display_order' => 6],
            ['code' => 'pr22-open', 'name' => 'PR22 Open', 'discipline' => 'PR22', 'description' => 'Unrestricted rimfire equipment class', 'display_order' => 10],
            ['code' => 'pr22-base', 'name' => 'PR22 Base', 'discipline' => 'PR22', 'description' => 'Base/production rimfire class', 'display_order' => 11],
        ];

        foreach ($divisions as $data) {
            Division::firstOrCreate(['code' => $data['code']], $data);
        }

        $categories = [
            ['code' => 'sub-junior', 'name' => 'Sub-Junior', 'description' => 'Shooter below sub-junior age threshold', 'is_age_based' => true, 'min_age' => null, 'max_age' => 14, 'display_order' => 1],
            ['code' => 'junior', 'name' => 'Junior', 'description' => 'Shooter below junior age threshold', 'is_age_based' => true, 'min_age' => 15, 'max_age' => 21, 'display_order' => 2],
            ['code' => 'senior', 'name' => 'Senior', 'description' => 'Shooter at or above senior age threshold', 'is_age_based' => true, 'min_age' => 55, 'max_age' => 64, 'display_order' => 3],
            ['code' => 'super-senior', 'name' => 'Super Senior', 'description' => 'Shooter at or above super-senior age threshold', 'is_age_based' => true, 'min_age' => 65, 'max_age' => null, 'display_order' => 4],
            ['code' => 'lady', 'name' => 'Lady', 'description' => 'Female shooter', 'is_age_based' => false, 'display_order' => 5],
            ['code' => 'adaptive', 'name' => 'Adaptive', 'description' => 'Shooter with physical disability', 'is_age_based' => false, 'display_order' => 6],
            ['code' => 'military-le', 'name' => 'Military / Law Enforcement', 'description' => 'Active military or law enforcement', 'is_age_based' => false, 'display_order' => 7],
            ['code' => 'international', 'name' => 'International', 'description' => 'Non-South African shooter', 'is_age_based' => false, 'display_order' => 8],
        ];

        foreach ($categories as $data) {
            Category::firstOrCreate(['code' => $data['code']], $data);
        }
    }
}
