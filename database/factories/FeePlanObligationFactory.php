<?php

namespace Database\Factories;

use App\Models\FeePlan;
use App\Models\FeePlanObligation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeePlanObligation>
 */
class FeePlanObligationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fee_plan_id' => FeePlan::factory(),
            'code' => strtoupper(fake()->unique()->bothify('OBL-###')),
            'label' => fake()->words(3, true),
            'amount' => fake()->randomFloat(2, 0, 5000),
            'required_for_enrollment' => true,
        ];
    }
}
