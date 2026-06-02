<?php

namespace Database\Factories;

use App\Enums\GradeCorrectionStatus;
use App\Models\GradeCorrection;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeCorrection>
 */
class GradeCorrectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'grade_id' => null,
            'subject_id' => Subject::factory(),
            'term_id' => Term::factory(),
            'assessment_component' => 'Final Grade',
            'current_grade' => null,
            'requested_action' => fake()->sentence(6),
            'reason' => fake()->sentence(8),
            'attachment_paths' => null,
            'status' => GradeCorrectionStatus::Submitted,
            'assigned_to' => null,
            'creator_id' => null,
            'resolved_at' => null,
        ];
    }
}
