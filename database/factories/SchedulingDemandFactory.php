<?php

namespace Database\Factories;

use App\Models\CourseComponent;
use App\Models\SchedulingDemand;
use App\Models\SectionDeliveryGroup;
use App\Models\TermOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchedulingDemand>
 */
class SchedulingDemandFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_offering_id' => TermOffering::factory(),
            'course_component_id' => CourseComponent::factory(),
            'section_delivery_group_id' => SectionDeliveryGroup::factory(),
            'demand_key' => fake()->unique()->uuid(),
            'required_duration_minutes' => 180,
            'meeting_count' => 1,
            'modality' => TermOffering::ModalityFaceToFace,
            'fixed_faculty_user_id' => null,
            'fixed_room_id' => null,
            'fixed_day_of_week' => null,
            'fixed_start_time' => null,
            'source_snapshot' => [],
            'readiness_findings' => [],
            'validation_state' => SchedulingDemand::ValidationReadyForReview,
            'generated_by' => null,
            'readiness_checked_at' => now(),
        ];
    }
}
