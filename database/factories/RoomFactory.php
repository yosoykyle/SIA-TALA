<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('R-###')),
            'name' => fake()->optional()->words(2, true),
            'building' => fake()->optional()->randomElement(['Main', 'Annex', 'Laboratory']),
            'capacity' => fake()->numberBetween(20, 40),
            'is_active' => true,
        ];
    }
}
