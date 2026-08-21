<?php

namespace Database\Factories;

use App\Models\FeePlan;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeePlan>
 */
class FeePlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'term_id' => Term::factory(),
            'version' => 1,
            'state' => FeePlan::StateDraft,
            'currency' => 'PHP',
            'created_by' => User::factory(),
        ];
    }
}
