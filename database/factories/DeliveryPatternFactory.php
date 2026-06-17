<?php

namespace Database\Factories;

use App\Models\DeliveryPattern;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryPattern>
 */
class DeliveryPatternFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('DP-###')),
            'version' => 1,
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'modality' => 'on_site',
            'allowed_days' => [1, 2, 3, 4, 5],
            'subject_routing' => DeliveryPattern::SubjectRoutingSameSubjectSet,
            'enforcement_level' => DeliveryPattern::EnforcementStrict,
            'default_room_required' => true,
            'is_active' => true,
            'is_frozen' => false,
            'used_at' => null,
            'cloned_from_id' => null,
            'created_by' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn (): array => [
            'modality' => 'online',
            'default_room_required' => false,
        ]);
    }

    public function frozen(): static
    {
        return $this->state(fn (): array => [
            'is_frozen' => true,
            'used_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
