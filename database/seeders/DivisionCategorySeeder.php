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
            ['slug' => 'factory',    'name' => 'Factory',    'description' => 'Factory-manufactured rifle under the PRS Production rules and the SAPRF Factory Division rules (2027 series)', 'display_order' => 2],
            ['slug' => 'limited',    'name' => 'Limited',    'description' => '.308 Win / 7.62x51 only; projectile maximum 185 gr; velocity maximum 3,200 fps (2027 series)', 'display_order' => 3],
            ['slug' => 'production', 'name' => 'Production', 'description' => 'Production-class rifle',             'display_order' => 4],
            ['slug' => 'hunter',     'name' => 'Hunter',     'description' => 'South African division. Factory stock, no chassis, 2.5 lb minimum trigger. Centrefire rifle max US$3,000; PR22 rifle max US$2,500; optic max US$2,500', 'display_order' => 8],
            ['slug' => 'mil-leo',    'name' => 'Mil/LEO',    'description' => 'IPRF: active full-time or part-time military or law enforcement. PRS Centrefire: full-time active duty or full-time LEO only', 'display_order' => 9],

            // Demographic classes (previously categories)
            ['slug' => 'ladies',     'name' => 'Ladies',     'description' => 'Female shooters',                    'display_order' => 5],
            ['slug' => 'junior',     'name' => 'Junior',     'description' => 'Centrefire PRS: 17 or younger on 1 January. IPRF team selection: 14 to 20 at the start of the year. PR22: 12 to 18. Age checked against a government ID', 'display_order' => 6],
            ['slug' => 'senior',     'name' => 'Senior',     'description' => 'Shooters at or above the senior age threshold', 'display_order' => 7],
        ];

        foreach ($divisions as $data) {
            Division::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
