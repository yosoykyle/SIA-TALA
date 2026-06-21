<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startYear = fake()->unique()->numberBetween(2026, 2099);

        return [
            'academic_year' => "{$startYear}-".($startYear + 1),
            'school_year_start_date' => "{$startYear}-08-01",
            'school_year_end_date' => ($startYear + 1).'-05-31',
            'status' => 'draft',
            'reference_note' => fake()->optional()->sentence(),
        ];
    }
}
