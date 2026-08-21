<?php

namespace Database\Factories;

use App\Models\FeePlan;
use App\Models\FeePlanCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeePlanCharge>
 */
class FeePlanChargeFactory extends Factory
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
            'sequence' => 1,
            'code' => strtoupper(fake()->unique()->bothify('FEE-###')),
            'label' => fake()->words(3, true),
            'amount' => fake()->randomFloat(2, 0, 5000),
        ];
    }
}
