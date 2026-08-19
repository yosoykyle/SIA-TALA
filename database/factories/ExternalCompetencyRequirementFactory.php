<?php

namespace Database\Factories;

use App\Models\CurriculumVersion;
use App\Models\ExternalCompetencyRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalCompetencyRequirement>
 */
class ExternalCompetencyRequirementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'curriculum_version_id' => CurriculumVersion::factory(),
            'curriculum_entry_id' => null,
            'requirement_code' => strtoupper(fake()->unique()->lexify('COMP-????')),
            'qualification_label' => fake()->words(3, true),
            'qualification_level' => 'Level 1',
            'treatment' => 'Required',
            'authority_reference' => 'Synthetic external competency authority',
            'authority_date' => today(),
            'state' => 'Draft',
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
        ];
    }
}
