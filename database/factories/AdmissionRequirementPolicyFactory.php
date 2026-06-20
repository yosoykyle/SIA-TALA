<?php

namespace Database\Factories;

use App\Models\AdmissionOffering;
use App\Models\AdmissionRequirementPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionRequirementPolicy>
 */
class AdmissionRequirementPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_offering_id' => AdmissionOffering::factory(),
            'version' => 1,
            'status' => AdmissionRequirementPolicy::StatusActive,
            'effective_from' => now()->subDay(),
            'effective_until' => null,
            'approved_by' => null,
            'approved_at' => now()->subDay(),
            'source_label' => 'test_policy',
            'meta' => [],
        ];
    }
}
