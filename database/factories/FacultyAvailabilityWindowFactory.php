<?php

namespace Database\Factories;

use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultyAvailabilityWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacultyAvailabilityWindow>
 */
class FacultyAvailabilityWindowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => FacultyAvailabilitySubmission::factory(),
            'day_of_week' => fake()->numberBetween(1, 5),
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
            'notes' => null,
        ];
    }
}
