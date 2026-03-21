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
            ['slug' => 'open', 'name' => 'Open', 'description' => 'Unrestricted equipment class', 'display_order' => 1],
            ['slug' => 'factory', 'name' => 'Factory', 'description' => 'Factory-stock rifle, no modifications', 'display_order' => 2],
            ['slug' => 'limited', 'name' => 'Limited', 'description' => 'Limited modifications allowed', 'display_order' => 3],
        ];

        foreach ($divisions as $data) {
            Division::firstOrCreate(['slug' => $data['slug']], $data);
        }

        $categories = [
            ['slug' => 'overall', 'name' => 'Overall', 'description' => 'All shooters regardless of category', 'display_order' => 1],
            ['slug' => 'ladies', 'name' => 'Ladies', 'description' => 'Female shooters', 'display_order' => 2],
            ['slug' => 'junior', 'name' => 'Junior', 'description' => 'Shooters under the junior age threshold', 'display_order' => 3],
            ['slug' => 'senior', 'name' => 'Senior', 'description' => 'Shooters at or above the senior age threshold', 'display_order' => 4],
        ];

        foreach ($categories as $data) {
            Category::firstOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
