<?php

namespace Database\Factories;

use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use App\Models\TermCohort;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermCohort>
 */
class TermCohortFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'program_id' => Program::factory(),
            'curriculum_version_id' => fn (array $attributes): Factory => CurriculumVersion::factory()
                ->state(['program_id' => $attributes['program_id']]),
            'reference' => 'SYNTH-COHORT-'.fake()->unique()->numerify('####'),
            'source' => 'Confirmed',
            'forecast_count' => 30,
            'confirmed_count' => 30,
            'state' => 'Confirmed',
            'confirmed_by' => null,
            'confirmed_at' => now(),
        ];
    }
}
