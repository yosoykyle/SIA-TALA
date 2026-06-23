<?php

namespace Database\Factories;

use App\Models\GradeSubmissionPackage;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeSubmissionPackage>
 */
class GradeSubmissionPackageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'section_id' => Section::factory(),
            'subject_id' => Subject::factory(),
            'faculty_id' => User::factory(),
            'state' => GradeSubmissionPackage::StateSubmitted,
            'roster_snapshot_checksum' => hash('sha256', fake()->uuid()),
            'grading_profile_snapshot' => [
                'scheme' => 'college',
                'periods' => ['prelim', 'midterm', 'final'],
            ],
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
        ];
    }
}
