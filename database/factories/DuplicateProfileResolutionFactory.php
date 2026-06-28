<?php

namespace Database\Factories;

use App\Models\DuplicateProfileResolution;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DuplicateProfileResolution>
 */
class DuplicateProfileResolutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'duplicate_student_profile_id' => StudentProfile::factory(),
            'primary_student_profile_id' => StudentProfile::factory(),
            'resolution_type' => 'LINKED_DUPLICATE',
            'reason' => fake()->sentence(),
            'resolved_by' => User::factory(),
            'resolved_at' => now(),
        ];
    }
}
