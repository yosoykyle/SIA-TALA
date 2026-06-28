<?php

namespace Database\Factories;

use App\Models\CurriculumVersion;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurriculumVersion>
 */
class CurriculumVersionFactory extends Factory
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
            'version_code' => 'v'.fake()->unique()->numberBetween(2026, 2099),
            'name' => fake()->words(3, true),
            'effective_entry_term_id' => null,
            'state' => CurriculumVersion::StateDraft,
            'approval_reference' => null,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }
}
