<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\AssessmentObligation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssessmentObligation>
 */
class AssessmentObligationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'code' => strtoupper(fake()->unique()->bothify('OBL-###')),
            'label' => fake()->words(3, true),
            'amount' => fake()->randomFloat(2, 1, 5000),
            'required_for_enrollment' => true,
        ];
    }
}
