<?php

namespace Database\Factories;

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurriculumSubject>
 */
class CurriculumSubjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'curriculum_id' => Curriculum::factory(),
            'subject_id' => Subject::factory(),
            'year_level' => '1st Year',
            'semester' => '1st Semester',
            'sort_order' => 0,
        ];
    }
}
