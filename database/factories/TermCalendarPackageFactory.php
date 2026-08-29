<?php

namespace Database\Factories;

use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermCalendarPackage>
 */
class TermCalendarPackageFactory extends Factory
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
            'version' => 1,
            'state' => TermCalendarPackage::StateDraft,
            'administrative_starts_on' => '2026-06-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-03',
            'classes_end_on' => '2026-12-05',
            'faculty_availability_due_at' => '2026-07-15 09:00:00',
            'authority_reference' => 'SYNTH-CALENDAR-2026',
            'authority_date' => '2026-05-15',
            'special_term_schedule_basis' => null,
            'recorded_by' => User::factory(),
            'activated_at' => null,
            'closed_at' => null,
        ];
    }
}
