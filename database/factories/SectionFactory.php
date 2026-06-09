<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'program_id' => Program::factory(),
            'curriculum_id' => null,
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'name' => fake()->unique()->bothify('Section ##'),
            'room' => fake()->bothify('R-###'),
            'max_seats' => 30,
            'enrolled_count' => 0,
            'modality' => 'on_site',
        ];
    }
}
