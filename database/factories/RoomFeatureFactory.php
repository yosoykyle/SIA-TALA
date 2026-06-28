<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomFeature>
 */
class RoomFeatureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'feature_key' => fake()->randomElement(['PROJECTOR', 'COMPUTER_UNITS', 'LAB_SINK', 'AIR_CONDITIONED']),
        ];
    }
}
