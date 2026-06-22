<?php

namespace Database\Factories;

use App\Models\ApplicantDocumentRequirement;
use App\Models\ApplicantIntake;
use App\Models\DocumentRequirementItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicantDocumentRequirement>
 */
class ApplicantDocumentRequirementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'applicant_intake_id' => ApplicantIntake::factory(),
            'admission_offering_id' => null,
            'admission_requirement_policy_id' => null,
            'document_requirement_item_id' => null,
            'item_key' => 'psa_birth_certificate',
            'label' => 'PSA Birth Certificate',
            'gate_type' => DocumentRequirementItem::GateTypeAdmission,
            'permitted_evidence_methods' => ['applicant_upload', 'registrar_assisted_upload'],
            'storage_class' => DocumentRequirementItem::StorageClassCredentialFile,
            'sensitivity_class' => DocumentRequirementItem::SensitivityStandard,
            'deadline_strategy' => null,
            'evidence_state' => ApplicantDocumentRequirement::EvidenceStatePending,
            'meta' => [],
        ];
    }
}
