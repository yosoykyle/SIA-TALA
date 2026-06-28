<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\FacultyQualification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacultyQualification>
 */
class FacultyQualificationFactory extends Factory
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
            'course_id' => Course::factory(),
            'is_active' => true,
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
            'notes' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
