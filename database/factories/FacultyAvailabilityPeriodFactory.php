<?php

namespace Database\Factories;

use App\Models\FacultyAvailabilityPeriod;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacultyAvailabilityPeriod>
 */
class FacultyAvailabilityPeriodFactory extends Factory
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
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDays(7),
            'status' => FacultyAvailabilityPeriod::StatusOpen,
            'created_by' => User::factory(),
            'locked_at' => null,
        ];
    }
}
