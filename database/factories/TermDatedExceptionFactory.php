<?php

namespace Database\Factories;

use App\Models\TermCalendarPackage;
use App\Models\TermDatedException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermDatedException>
 */
class TermDatedExceptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_calendar_package_id' => TermCalendarPackage::factory(),
            'starts_on' => '2026-08-21',
            'ends_on' => '2026-08-21',
            'exception_type' => 'Holiday',
            'label' => 'Synthetic no-class day',
            'blocks_teaching' => true,
            'authority_reference' => 'SYNTH-CALENDAR-2026',
        ];
    }
}
