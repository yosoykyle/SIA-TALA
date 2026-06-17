<?php

namespace Database\Factories;

use App\Models\ExamAccessAccommodation;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamAccessAccommodation>
 */
class ExamAccessAccommodationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_profile_id' => StudentProfile::factory(),
            'scope' => ExamAccessAccommodation::ScopeTerm,
            'basis' => ExamAccessAccommodation::BasisInstitutionalDiscretion,
            'status' => ExamAccessAccommodation::StatusPending,
            'request_reason' => fake()->sentence(),
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addMonth()->toDateString(),
            'requested_at' => now(),
        ];
    }
}
