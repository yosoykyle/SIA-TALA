<?php

namespace Database\Factories;

use App\Models\DeliveryPattern;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SectionDeliveryGroup>
 */
class SectionDeliveryGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
            'delivery_pattern_id' => DeliveryPattern::factory(),
            'name' => fake()->unique()->bothify('Delivery Group ##'),
            'modality' => 'on_site',
            'capacity' => 30,
            'assigned_count' => 0,
            'room_required' => true,
            'room' => fake()->bothify('R-###'),
            'status' => SectionDeliveryGroup::StatusActive,
            'created_by' => null,
            'updated_by' => null,
            'closed_at' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn (): array => [
            'modality' => 'online',
            'room_required' => false,
            'room' => null,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => SectionDeliveryGroup::StatusClosed,
            'closed_at' => now(),
        ]);
    }
}
