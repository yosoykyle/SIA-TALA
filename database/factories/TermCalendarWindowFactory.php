<?php

namespace Database\Factories;

use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermCalendarWindow>
 */
class TermCalendarWindowFactory extends Factory
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
            'window_type' => 'Enrollment',
            'opens_on' => '2026-06-15',
            'closes_on' => '2026-07-31',
            'cutoff_at' => '17:00:00',
        ];
    }
}
