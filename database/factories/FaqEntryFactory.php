<?php

namespace Database\Factories;

use App\Models\FaqEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FaqEntry>
 */
class FaqEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question' => rtrim(fake()->sentence(), '.').'?',
            'answer' => fake()->paragraph(),
            'category' => fake()->randomElement(array_keys(FaqEntry::categoryOptions())),
            'sort_order' => fake()->numberBetween(0, 20),
            'is_published' => false,
            'system_key' => null,
        ];
    }

    /**
     * Indicate that the FAQ entry is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_published' => true,
        ]);
    }
}
