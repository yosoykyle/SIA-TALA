<?php

namespace Database\Factories;

use App\Models\ServiceRequest;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRequest>
 */
class ServiceRequestFactory extends Factory
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
            'term_id' => Term::factory(),
            'category' => 'student_service',
            'sub_type' => 'drop_form',
            'status' => ServiceRequest::StatusSubmitted,
            'details' => fake()->sentence(),
            'attachment_paths' => [],
        ];
    }
}
