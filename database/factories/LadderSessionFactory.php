<?php

namespace Database\Factories;

use App\Enums\LadderVariable;
use App\Models\LadderSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LadderSession>
 */
class LadderSessionFactory extends Factory
{
    protected $model = LadderSession::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'variable' => LadderVariable::ChargeWeight,
            // unit gets synced in the model's saving hook.
            'unit' => 'gr',
            'name' => fake()->words(3, true).' ladder',
            'fired_on' => fake()->dateTimeBetween('-6 months')->format('Y-m-d'),
        ];
    }

    public function seatingDepth(): static
    {
        return $this->state(fn () => [
            'variable' => LadderVariable::SeatingDepth,
            'unit' => 'mm',
        ]);
    }
}
