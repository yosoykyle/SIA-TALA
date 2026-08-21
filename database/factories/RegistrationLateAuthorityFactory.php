<?php

namespace Database\Factories;

use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\RegistrationLateAuthority;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationLateAuthority>
 */
class RegistrationLateAuthorityFactory extends Factory
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
            'term_id' => fn (array $attributes): int => (int) Enrollment::query()->findOrFail($attributes['enrollment_id'])->term_id,
            'action_type' => RegistrationLateAuthority::ActionCourseDrop,
            'before_course_enrollment_id' => fn (array $attributes): int => CourseEnrollment::factory()->create([
                'enrollment_id' => $attributes['enrollment_id'],
            ])->id,
            'after_section_id' => null,
            'approving_office' => 'Registrar Office',
            'authority_reference' => fake()->unique()->bothify('SYN-LATE-####'),
            'authority_date' => today(),
            'reason' => 'Synthetic bounded late authority.',
            'effective_at' => now(),
            'learner_acknowledgement_basis' => 'Recorded learner acknowledgement',
            'source_academic_decision' => 'Recorded Registrar academic decision',
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
        ];
    }
}
