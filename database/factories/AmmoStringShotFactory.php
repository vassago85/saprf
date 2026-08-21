<?php

namespace Database\Factories;

use App\Models\AmmoString;
use App\Models\AmmoStringShot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AmmoStringShot>
 */
class AmmoStringShotFactory extends Factory
{
    protected $model = AmmoStringShot::class;

    public function definition(): array
    {
        return [
            'ammo_string_id' => AmmoString::factory(),
            'sequence' => 1,
            'velocity_fps' => fake()->randomFloat(1, 2500, 2900),
            'excluded' => false,
            'notes' => null,
        ];
    }
}
