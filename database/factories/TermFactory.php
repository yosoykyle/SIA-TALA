<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Term>
 */
class TermFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'ends_on' => now()->addMonths(4)->endOfMonth()->toDateString(),
            'state' => Term::StateDraft,
            'scheduling_slot_minutes' => 30,
            'scheduling_days' => [1, 2, 3, 4, 5, 6],
            'scheduling_day_starts_at' => '07:00:00',
            'scheduling_day_ends_at' => '21:00:00',
            'default_max_units' => 21.00,
        ];
    }
}
