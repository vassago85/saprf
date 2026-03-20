<?php

namespace Database\Seeders;

use App\Models\FirearmCalibre;
use App\Models\FirearmMake;
use App\Models\FirearmModel;
use Illuminate\Database\Seeder;

class FirearmReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedMakes();
        $this->seedModels();
        $this->seedCalibres();
    }

    protected function seedMakes(): void
    {
        $csvPath = resource_path('data/firearm_makes.csv');
        if (! file_exists($csvPath)) {
            $this->command->warn('firearm_makes.csv not found');
            return;
        }

        $handle = fopen($csvPath, 'r');
        $header = fgetcsv($handle);
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3) continue;

            FirearmMake::updateOrCreate(
                ['name' => trim($row[0])],
                [
                    'country' => trim($row[1]) ?: null,
                    'is_active' => filter_var($row[2], FILTER_VALIDATE_BOOLEAN),
                ]
            );
            $count++;
        }

        fclose($handle);
        $this->command->info("Seeded {$count} firearm makes.");
    }

    protected function seedModels(): void
    {
        $csvPath = resource_path('data/firearm_models.csv');
        if (! file_exists($csvPath)) {
            $this->command->warn('firearm_models.csv not found');
            return;
        }

        $handle = fopen($csvPath, 'r');
        $header = fgetcsv($handle);
        $count = 0;
        $makeCache = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3) continue;

            $makeName = trim($row[0]);
            $modelName = trim($row[1]);

            if (! isset($makeCache[$makeName])) {
                $make = FirearmMake::where('name', $makeName)->first();
                if (! $make) continue;
                $makeCache[$makeName] = $make->id;
            }

            FirearmModel::updateOrCreate(
                ['firearm_make_id' => $makeCache[$makeName], 'name' => $modelName],
                ['is_active' => filter_var($row[2], FILTER_VALIDATE_BOOLEAN)]
            );
            $count++;
        }

        fclose($handle);
        $this->command->info("Seeded {$count} firearm models.");
    }

    protected function seedCalibres(): void
    {
        $csvPath = resource_path('data/firearm_calibres.csv');
        if (! file_exists($csvPath)) {
            $this->command->warn('firearm_calibres.csv not found');
            return;
        }

        $handle = fopen($csvPath, 'r');
        $header = fgetcsv($handle);
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 6) continue;

            FirearmCalibre::updateOrCreate(
                ['name' => trim($row[0])],
                [
                    'category' => trim($row[1]) ?: 'rifle',
                    'family' => trim($row[2]) ?: null,
                    'bullet_diameter' => ! empty($row[3]) ? (float) $row[3] : null,
                    'is_active' => filter_var($row[5], FILTER_VALIDATE_BOOLEAN),
                ]
            );
            $count++;
        }

        fclose($handle);
        $this->command->info("Seeded {$count} firearm calibres.");
    }
}
