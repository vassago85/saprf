<?php

namespace Database\Factories;

use App\Models\LadderShot;
use App\Models\LadderStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LadderShot>
 */
class LadderShotFactory extends Factory
{
    protected $model = LadderShot::class;

    public function definition(): array
    {
        return [
            'ladder_step_id' => LadderStep::factory(),
            'velocity_fps' => fake()->randomFloat(1, 2500, 2800),
            'sequence' => 0,
            'excluded' => false,
        ];
    }
}
