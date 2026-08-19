<?php

namespace Database\Factories;

use App\Models\ResourceUnavailability;
use App\Models\Room;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceUnavailability>
 */
class ResourceUnavailabilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'room_id' => Room::factory(),
            'faculty_user_id' => null,
            'effective_on' => null,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'authority_reference' => 'SYNTH-RESOURCE-UNAVAILABLE',
            'reason' => 'Synthetic room maintenance interval.',
        ];
    }
}
