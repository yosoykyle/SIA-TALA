<?php

namespace Database\Factories;

use App\Models\Hold;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hold>
 */
class HoldFactory extends Factory
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
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
            'reason' => fake()->sentence(),
            'student_message' => fake()->sentence(),
            'effective_at' => now(),
        ];
    }
}
