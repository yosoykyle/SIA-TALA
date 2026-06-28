<?php

namespace Database\Factories;

use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\TermOffering;
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
            'name' => fake()->unique()->bothify('Delivery Group ##'),
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'delivery_override' => null,
            'state' => SectionDeliveryGroup::StateReady,
        ];
    }

    public function online(): static
    {
        return $this->state(fn (): array => [
            'modality' => TermOffering::ModalityOnline,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'state' => SectionDeliveryGroup::StateClosed,
        ]);
    }
}
