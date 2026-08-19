<?php

namespace Database\Factories;

use App\Models\TermCalendarPackage;
use App\Models\TermTeachingGridRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermTeachingGridRow>
 */
class TermTeachingGridRowFactory extends Factory
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
            'day_of_week' => 1,
            'starts_at' => '07:00:00',
            'ends_at' => '21:00:00',
            'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
        ];
    }
}
