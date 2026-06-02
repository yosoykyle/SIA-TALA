<?php

namespace Database\Factories;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('SUB-###')),
            'description' => fake()->sentence(3),
            'units' => '3.00',
            'lec_hours' => '3.00',
            'category' => null,
            'department' => 'college',
            'subject_type' => null,
        ];
    }
}
