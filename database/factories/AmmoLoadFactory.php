<?php

namespace Database\Factories;

use App\Models\AmmoLoad;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AmmoLoad>
 */
class AmmoLoadFactory extends Factory
{
    protected $model = AmmoLoad::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nickname' => fake()->words(2, true).' Load',
            'bullet_make' => fake()->randomElement(['Berger', 'Sierra', 'Hornady', 'Lapua']),
            'bullet_weight' => fake()->randomElement(['140gr', '155gr', '168gr', '185gr']),
            'brass' => fake()->randomElement(['Lapua', 'Peterson', 'ADG']),
            'primer' => fake()->randomElement(['CCI BR2', 'Federal 210M']),
            'powder' => fake()->randomElement(['Vihtavuori N150', 'Hodgdon H4350']),
            'is_active' => true,
        ];
    }
}
