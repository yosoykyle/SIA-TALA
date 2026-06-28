<?php

namespace Database\Factories;

use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
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
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'requirement_type' => strtoupper(fake()->unique()->words(2, true)),
            'evidence_method' => 'PHYSICAL_COPY',
            'blocking_level' => ChecklistItem::BlockingHandover,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_until' => null,
            'state' => AdmissionRequirementPolicy::StateActive,
            'authority' => 'Registrar policy',
        ];
    }
}
