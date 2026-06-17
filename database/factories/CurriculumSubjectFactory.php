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
            'weekly_contact_hours' => '3.00',
            'academic_subject_type' => CurriculumSubject::AcademicSubjectTypeMajor,
            'scheduling_group' => CurriculumSubject::SchedulingGroupLecture,
            'delivery_rule_override' => null,
            'sort_order' => 0,
        ];
    }

    public function excludedFromAutoSchedule(): self
    {
        return $this->state(fn (): array => [
            'delivery_rule_override' => CurriculumSubject::DeliveryOverrideExcludeFromAutoSchedule,
        ]);
    }
}
