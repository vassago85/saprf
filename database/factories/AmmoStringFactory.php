<?php

namespace Database\Factories;

use App\Models\AmmoString;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AmmoString>
 */
class AmmoStringFactory extends Factory
{
    protected $model = AmmoString::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ammo_load_id' => null,
            'barrel_id' => null,
            'ladder_session_id' => null,
            'label' => fake()->words(2, true).' string',
            'fired_on' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'temperature_c' => fake()->randomFloat(1, 5, 35),
            'notes' => null,
        ];
    }
}
