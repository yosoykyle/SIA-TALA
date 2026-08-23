<?php

namespace Database\Factories;

use App\Models\CurriculumVersion;
use App\Models\GraduationApplication;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GraduationApplication>
 */
class GraduationApplicationFactory extends Factory
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
            'curriculum_version_id' => CurriculumVersion::factory(),
            'term_id' => Term::factory(),
            'version' => 1,
            'state' => GraduationApplication::StateActive,
            'active_scope_key' => null,
            'source_fingerprint' => hash('sha256', fake()->uuid()),
            'applied_by' => User::factory(),
            'applied_at' => now(),
        ];
    }
}
