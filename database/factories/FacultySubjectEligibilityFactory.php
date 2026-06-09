<?php

namespace Database\Factories;

use App\Models\FacultySubjectEligibility;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacultySubjectEligibility>
 */
class FacultySubjectEligibilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'faculty_id' => User::factory(),
            'subject_id' => Subject::factory(),
            'term_id' => fake()->boolean(35) ? Term::factory() : null,
            'status' => FacultySubjectEligibility::StatusActive,
            'priority' => null,
            'max_weekly_hours' => null,
            'approved_by' => null,
            'approved_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FacultySubjectEligibility::StatusInactive,
        ]);
    }
}
