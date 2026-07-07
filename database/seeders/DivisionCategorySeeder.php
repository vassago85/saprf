<?php

namespace Database\Seeders;

use App\Models\Division;
use Illuminate\Database\Seeder;

/**
 * Seeds the FLAT list of shooter divisions.
 *
 * Ladies, Junior, and Senior — previously demographic categories — now sit
 * alongside the equipment-based divisions (Open, Factory, Limited, Production).
 * Every shooter picks exactly one.
 *
 * The class name is kept as `DivisionCategorySeeder` for compatibility with
 * `DatabaseSeeder`, even though the "Category" half of the name is legacy.
 */
class DivisionCategorySeeder extends Seeder
{
    public function run(): void
    {
        $divisions = [
            // Equipment classes
            ['slug' => 'open',       'name' => 'Open',       'description' => 'Unrestricted equipment class',       'display_order' => 1],
            ['slug' => 'factory',    'name' => 'Factory',    'description' => 'Factory-stock rifle, no modifications', 'display_order' => 2],
            ['slug' => 'limited',    'name' => 'Limited',    'description' => 'Limited modifications allowed',      'display_order' => 3],
            ['slug' => 'production', 'name' => 'Production', 'description' => 'Production-class rifle',             'display_order' => 4],

            // Demographic classes (previously categories)
            ['slug' => 'ladies',     'name' => 'Ladies',     'description' => 'Female shooters',                    'display_order' => 5],
            ['slug' => 'junior',     'name' => 'Junior',     'description' => 'Shooters under the junior age cut-off', 'display_order' => 6],
            ['slug' => 'senior',     'name' => 'Senior',     'description' => 'Shooters at or above the senior age threshold', 'display_order' => 7],
        ];

        foreach ($divisions as $data) {
            Division::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
