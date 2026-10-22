<?php

namespace Database\Factories;

use App\Models\ApplicationSubmissionVersion;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\RegistrationCreditEvaluation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationCreditEvaluation>
 */
class RegistrationCreditEvaluationFactory extends Factory
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
            'application_submission_version_id' => ApplicationSubmissionVersion::factory(),
            'curriculum_version_id' => CurriculumVersion::factory(),
            'credited_curriculum_entry_ids' => [], 'authority_reference' => 'SYN-EVAL-001',
            'approved_on' => today(), 'recorded_by' => User::factory(), 'recorded_at' => now(),
        ];
    }
}
