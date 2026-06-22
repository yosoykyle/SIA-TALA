<?php

namespace Database\Factories;

use App\Models\AdmissionRequirementPolicy;
use App\Models\DocumentRequirementItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRequirementItem>
 */
class DocumentRequirementItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_requirement_policy_id' => AdmissionRequirementPolicy::factory(),
            'key' => 'psa_birth_certificate',
            'label' => 'PSA Birth Certificate',
            'gate_type' => DocumentRequirementItem::GateTypeAdmission,
            'sort_order' => 10,
            'permitted_evidence_methods' => ['applicant_upload', 'registrar_assisted_upload'],
            'storage_class' => DocumentRequirementItem::StorageClassCredentialFile,
            'sensitivity_class' => DocumentRequirementItem::SensitivityStandard,
            'verified_field_mapping' => [],
            'deadline_strategy' => null,
            'retention_policy' => null,
            'meta' => [],
        ];
    }
}
