<?php

namespace Database\Factories;

use App\Models\Barrel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Barrel>
 */
class BarrelFactory extends Factory
{
    protected $model = Barrel::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->randomElement(['Bartlein', 'Krieger', 'Proof', 'Rock']).' #'.fake()->numberBetween(1, 5),
            'chambering' => fake()->randomElement(['6mm Dasher', '6.5 Creedmoor', '6BR', '308 Win']),
            'maker' => fake()->randomElement(['Bartlein', 'Krieger', 'Proof Research', 'Rock Creek']),
            'length_mm' => fake()->randomElement([660, 680, 700, 720]),
            'twist_rate' => fake()->randomElement(['1:7', '1:7.5', '1:8', '1:8.5']),
            'round_count' => fake()->numberBetween(0, 3000),
        ];
    }

    public function retired(): static
    {
        return $this->state(fn () => [
            'retired_on' => now()->subMonth()->toDateString(),
        ]);
    }
}
