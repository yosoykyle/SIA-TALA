<?php

namespace Database\Factories;

use App\Models\OfficialOutputRestriction;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficialOutputRestriction>
 */
class OfficialOutputRestrictionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_profile_id' => StudentProfile::factory(),
            'output_type' => OfficialOutputRestriction::OutputTor,
            'reason' => 'Accounting review is required for this official output.',
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
        ];
    }
}
