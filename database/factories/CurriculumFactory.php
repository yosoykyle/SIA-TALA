<?php

namespace Database\Factories;

use App\Models\Curriculum;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Curriculum>
 */
class CurriculumFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'effective_year' => (string) fake()->numberBetween(2024, 2028),
            'version_name' => 'Version '.fake()->unique()->numberBetween(1, 999),
            'is_active' => true,
            'activated_at' => now(),
        ];
    }
}
