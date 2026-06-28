<?php

namespace Database\Factories;

use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseComponent>
 */
class CourseComponentFactory extends Factory
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
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'room_type_default' => 'LECTURE_ROOM',
            'modality_restriction' => null,
            'requires_consecutive_block' => false,
            'same_faculty' => true,
            'sequence' => 1,
        ];
    }
}
