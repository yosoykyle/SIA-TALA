<?php

namespace Database\Factories;

use App\Models\FacultyTermLoadOverride;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacultyTermLoadOverride>
 */
class FacultyTermLoadOverrideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'faculty_user_id' => User::factory(),
            'term_id' => Term::factory(),
            'default_max_units_snapshot' => 21.00,
            'approved_overload_units' => 0.00,
            'authority' => 'Academic Head approval',
            'reason' => 'Approved registrar-recorded term load exception.',
            'is_active' => true,
        ];
    }
}
