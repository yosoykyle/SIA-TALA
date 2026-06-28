<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseRequirement>
 */
class CourseRequirementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_specification_id' => CourseSpecification::factory(),
            'rule_type' => CourseRequirement::TypePrerequisite,
            'group_key' => 'G1',
            'related_course_id' => Course::factory(),
            'direction' => null,
            'equivalency_scope' => null,
            'required_outcome' => 'PASSED',
            'minimum_grade' => null,
            'accepts_transfer_credit' => true,
            'effective_from' => null,
            'effective_until' => null,
            'authority' => 'Curriculum Review',
            'state' => CourseRequirement::StateActive,
            'sequence' => 1,
        ];
    }
}
