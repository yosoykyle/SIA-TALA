<?php

namespace Database\Factories;

use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentLifecycleChange>
 */
class StudentLifecycleChangeFactory extends Factory
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
            'type' => StudentLifecycleChange::TypeWithdrawal,
            'requested_on' => now()->toDateString(),
            'effective_on' => now()->toDateString(),
            'decided_on' => now()->toDateString(),
            'authority' => fake()->name(),
            'private_source_reference' => fake()->bothify('APP-####'),
            'reason' => fake()->sentence(),
            'impact_snapshot' => [],
            'state' => StudentLifecycleChange::StateRecordedApproved,
        ];
    }
}
