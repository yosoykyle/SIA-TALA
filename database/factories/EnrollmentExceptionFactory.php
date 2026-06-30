<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentException>
 */
class EnrollmentExceptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'student_profile_id' => StudentProfile::factory(),
            'term_id' => Term::factory(),
            'exception_type' => EnrollmentException::TypePrerequisite,
            'scope_key' => fake()->unique()->slug(),
            'reason' => fake()->sentence(),
            'evidence_reference' => fake()->bothify('EVID-####'),
            'approved_at' => now(),
            'state' => EnrollmentException::StateActive,
        ];
    }
}
