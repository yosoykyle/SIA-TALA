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
            'name' => fake()->words(2, true),
            'building' => fake()->optional()->randomElement(['Main', 'Annex', 'Laboratory']),
            'room_type' => Room::TypeLectureRoom,
            'capacity' => fake()->numberBetween(20, 40),
            'is_active' => true,
            'notes' => null,
        ];
    }
}
