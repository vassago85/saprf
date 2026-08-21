<?php

namespace Database\Factories;

use App\Models\LadderSession;
use App\Models\LadderStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LadderStep>
 */
class LadderStepFactory extends Factory
{
    protected $model = LadderStep::class;

    public function definition(): array
    {
        return [
            'ladder_session_id' => LadderSession::factory(),
            'value' => fake()->randomFloat(3, 30, 45),
            'include_in_fit' => true,
            'sort_order' => 0,
        ];
    }
}
