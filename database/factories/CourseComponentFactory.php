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
            'meeting_pattern' => null,
            'room_type_default' => 'LECTURE_ROOM',
            'required_room_feature_keys' => [],
            'modality_restriction' => null,
            'requires_consecutive_block' => false,
            'same_faculty' => true,
            'sequence' => 1,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CourseComponent $component): void {
            if (filled($component->meeting_pattern)) {
                return;
            }

            $weeklyMinutes = (int) round((float) $component->weekly_contact_hours * 60);
            $component->meeting_pattern = collect(CourseComponent::meetingPatternOptions())
                ->keys()
                ->first(function (string $pattern) use ($weeklyMinutes): bool {
                    $parsed = CourseComponent::parseMeetingPattern($pattern);

                    return $parsed !== null
                        && ($parsed['count'] * $parsed['duration_minutes']) === $weeklyMinutes;
                });
        });
    }
}
