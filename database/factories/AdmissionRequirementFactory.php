<?php

namespace Database\Factories;

use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionRequirement>
 */
class AdmissionRequirementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_requirement_set_id' => AdmissionRequirementSet::factory(),
            'code' => strtoupper(fake()->unique()->lexify('REQ-????')),
            'label' => fake()->words(3, true),
            'authority_reference' => 'Synthetic Registrar requirement authority',
            'purpose' => 'Confirm the applicant-supplied admission evidence.',
            'credential_classification' => AdmissionRequirement::ClassificationCoreOtherOfficialCredential,
            'requires_preliminary_evidence' => true,
            'official_submission_method' => AdmissionRequirement::SubmissionInPerson,
            'due_stage' => AdmissionRequirement::DueEnrollmentReadiness,
            'applicant_instructions' => 'Upload one preliminary review copy.',
            'registrar_instructions' => 'Verify the official credential source separately.',
            'exception_permitted' => false,
            'required_approving_authority' => null,
            'display_order' => 10,
        ];
    }

    public function firstYearCompletionCredential(): static
    {
        return $this->state(fn (): array => [
            'credential_classification' => AdmissionRequirement::ClassificationCoreFirstYearCompletionCredential,
            'due_stage' => AdmissionRequirement::DueEnrollmentReadiness,
            'official_submission_method' => AdmissionRequirement::SubmissionInPerson,
            'exception_permitted' => false,
            'required_approving_authority' => null,
        ]);
    }

    public function transferCredential(): static
    {
        return $this->state(fn (): array => [
            'credential_classification' => AdmissionRequirement::ClassificationCoreTransferCredential,
            'due_stage' => AdmissionRequirement::DueEnrollmentReadiness,
            'official_submission_method' => AdmissionRequirement::SubmissionSchoolToSchool,
            'exception_permitted' => false,
            'required_approving_authority' => null,
        ]);
    }

    public function nonCoreException(): static
    {
        return $this->state(fn (): array => [
            'credential_classification' => AdmissionRequirement::ClassificationNonCore,
            'exception_permitted' => true,
            'required_approving_authority' => 'Registrar',
        ]);
    }
}
