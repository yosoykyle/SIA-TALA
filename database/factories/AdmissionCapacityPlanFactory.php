<?php

namespace Database\Factories;

use App\Models\AdmissionCapacityPlan;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionCapacityPlan>
 */
class AdmissionCapacityPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'scope_type' => AdmissionCapacityPlan::ScopeCampus,
            'education_level' => null,
            'program_id' => null,
            'year_level' => null,
            'delivery_setup' => null,
            'capacity_limit' => 100,
            'reserved_count' => 0,
            'status' => AdmissionCapacityPlan::StatusApproved,
            'effective_from' => now()->subDay(),
            'effective_until' => null,
            'approved_by' => null,
            'approved_at' => now()->subDay(),
            'meta' => [],
        ];
    }
}
