<?php

namespace Database\Factories;

use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurriculumEntry>
 */
class CurriculumEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'curriculum_version_id' => CurriculumVersion::factory(),
            'course_specification_id' => CourseSpecification::factory(),
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => fake()->numberBetween(1, 12),
            'requirement_group' => CurriculumEntry::RequirementGroupRequired,
        ];
    }
}
