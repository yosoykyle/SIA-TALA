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
        do {
            $startYear = fake()->unique()->numberBetween(2026, 2099);
        } while (AcademicYear::query()->where('label', "{$startYear}-".($startYear + 1))->exists());

        return [
            'label' => "{$startYear}-".($startYear + 1),
            'starts_on' => "{$startYear}-08-01",
            'ends_on' => ($startYear + 1).'-05-31',
            'state' => AcademicYear::StateDraft,
        ];
    }
}
