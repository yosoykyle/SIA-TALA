<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseSpecification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseSpecification>
 */
class CourseSpecificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'revision_code' => 'REV-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'credit_units' => 3.00,
            'grading_profile_key' => 'college_standard',
            'grading_profile_version' => 1,
            'allowed_modalities' => ['FACE_TO_FACE', 'ONLINE'],
            'same_faculty_default' => true,
            'effective_term_id' => null,
            'state' => CourseSpecification::StateDraft,
        ];
    }
}
