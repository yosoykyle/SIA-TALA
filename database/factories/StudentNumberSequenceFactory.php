<?php

namespace Database\Factories;

use App\Models\StudentNumberSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentNumberSequence>
 */
class StudentNumberSequenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'year' => fake()->unique()->numberBetween(2020, 2099),
            'last_number' => 0,
        ];
    }
}
