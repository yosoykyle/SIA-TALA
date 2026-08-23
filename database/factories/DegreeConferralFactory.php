<?php

namespace Database\Factories;

use App\Models\CompletionReadinessVersion;
use App\Models\CurriculumVersion;
use App\Models\DegreeConferral;
use App\Models\GraduationApplication;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DegreeConferral>
 */
class DegreeConferralFactory extends Factory
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
            'graduation_application_id' => GraduationApplication::factory(),
            'completion_readiness_version_id' => CompletionReadinessVersion::factory(),
            'curriculum_version_id' => CurriculumVersion::factory(),
            'version' => 1,
            'active_scope_key' => null,
            'program_name_snapshot' => fake()->words(3, true),
            'degree_name' => fake()->words(4, true),
            'conferred_on' => today(),
            'authority_reference' => 'SYNTH-CONFERRAL-'.fake()->unique()->numerify('####'),
            'source_fingerprint' => hash('sha256', fake()->uuid()),
            'final_evaluation_snapshot' => [],
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
        ];
    }
}
