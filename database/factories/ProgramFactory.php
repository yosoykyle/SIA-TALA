<?php

namespace Database\Factories;

use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->words(3, true),
            'description' => null,
            'duration_years' => fake()->numberBetween(2, 4),
            'is_active' => true,
        ];
    }

    public function withDescription(?string $description = null): static
    {
        return $this->state(fn (): array => [
            'description' => $description ?? fake()->sentence(14),
        ]);
    }
}
