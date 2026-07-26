<?php

namespace Database\Seeders;

use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use Illuminate\Database\Seeder;

class AdmissionRequirementPolicySeeder extends Seeder
{
    /**
     * A fixed historical effective date so re-running stays idempotent against
     * the (admission_category, credential_basis, requirement_type, effective_from)
     * unique key.
     */
    private const EffectiveFrom = '2024-01-01';

    /**
     * Seed an ACTIVE mixed-evidence baseline so every supported applicant category
     * can exercise policy-driven digital uploads and Registrar-tracked requirements.
     * This remains a configurable starting point rather than the institution's final
     * admissions policy.
     *
     * Skipped under the testing environment: the feature/regression suite seeds
     * DatabaseSeeder and asserts resolver behaviour against factory-built policies
     * (e.g. "no active policy matches"), so a persistent baseline would make those
     * expectations non-deterministic.
     */
    public function run(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $this->seedBaseline();
    }

    /**
     * Insert the minimal ACTIVE baseline. Kept separate from run() so it can be
     * exercised directly by tests, bypassing the testing-environment guard above.
     */
    public function seedBaseline(): void
    {
        foreach ($this->baselinePolicies() as $policy) {
            AdmissionRequirementPolicy::query()->firstOrCreate(
                [
                    'admission_category' => $policy['admission_category'],
                    'credential_basis' => $policy['credential_basis'],
                    'requirement_type' => $policy['requirement_type'],
                    'effective_from' => self::EffectiveFrom,
                ],
                [
                    'evidence_method' => $policy['evidence_method'],
                    'blocking_level' => $policy['blocking_level'],
                    'effective_until' => null,
                    'state' => AdmissionRequirementPolicy::StateActive,
                    'authority' => 'System Default',
                ],
            );
        }
    }

    public function expectedPolicyCount(): int
    {
        return count($this->baselinePolicies());
    }

    public function baselineIsComplete(): bool
    {
        if (AdmissionRequirementPolicy::query()->count() !== $this->expectedPolicyCount()) {
            return false;
        }

        foreach ($this->baselinePolicies() as $policy) {
            $matchesBaseline = AdmissionRequirementPolicy::query()
                ->where('admission_category', $policy['admission_category'])
                ->where('credential_basis', $policy['credential_basis'])
                ->where('requirement_type', $policy['requirement_type'])
                ->whereDate('effective_from', self::EffectiveFrom)
                ->whereNull('effective_until')
                ->where('evidence_method', $policy['evidence_method'])
                ->where('blocking_level', $policy['blocking_level'])
                ->where('state', AdmissionRequirementPolicy::StateActive)
                ->where('authority', 'System Default')
                ->exists();

            if (! $matchesBaseline) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{admission_category: string, credential_basis: string, requirement_type: string, evidence_method: string, blocking_level: string}>
     */
    private function baselinePolicies(): array
    {
        return [
            ...$this->policiesFor(
                ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                ApplicantIntake::CredentialBasisSeniorHighSchool,
                [
                    ['IDENTITY_DOCUMENT', ChecklistItem::EvidenceMethodDigitalUpload, ChecklistItem::BlockingHandover],
                    ['BIRTH_CERTIFICATE', ChecklistItem::EvidenceMethodDigitalUpload, ChecklistItem::BlockingHandover],
                    ['GOOD_MORAL', ChecklistItem::EvidenceMethodDigitalUpload, ChecklistItem::BlockingEnrollment],
                    ['FORM_137', ChecklistItem::EvidenceMethodPhysicalCopy, ChecklistItem::BlockingEnrollment],
                ],
            ),
            ...$this->policiesFor(
                ApplicantIntake::AdmissionCategoryTransfer,
                ApplicantIntake::CredentialBasisTransferCredentials,
                [
                    ['IDENTITY_DOCUMENT', ChecklistItem::EvidenceMethodDigitalUpload, ChecklistItem::BlockingHandover],
                    ['BIRTH_CERTIFICATE', ChecklistItem::EvidenceMethodDigitalUpload, ChecklistItem::BlockingHandover],
                    ['TRANSCRIPT_OF_RECORDS', ChecklistItem::EvidenceMethodDigitalUpload, ChecklistItem::BlockingEnrollment],
                    ['GOOD_MORAL', ChecklistItem::EvidenceMethodPhysicalCopy, ChecklistItem::BlockingEnrollment],
                ],
            ),
            ...$this->policiesFor(
                ApplicantIntake::AdmissionCategoryReturning,
                ApplicantIntake::CredentialBasisPriorStudentRecord,
                [
                    ['IDENTITY_DOCUMENT', ChecklistItem::EvidenceMethodDigitalUpload, ChecklistItem::BlockingHandover],
                    ['PRIOR_STUDENT_RECORD', ChecklistItem::EvidenceMethodMetadataOnly, ChecklistItem::BlockingHandover],
                ],
            ),
        ];
    }

    /**
     * @param  list<array{string, string, string}>  $requirements
     * @return list<array{admission_category: string, credential_basis: string, requirement_type: string, evidence_method: string, blocking_level: string}>
     */
    private function policiesFor(string $category, string $credential, array $requirements): array
    {
        return array_map(
            fn (array $requirement): array => [
                'admission_category' => $category,
                'credential_basis' => $credential,
                'requirement_type' => $requirement[0],
                'evidence_method' => $requirement[1],
                'blocking_level' => $requirement[2],
            ],
            $requirements,
        );
    }
}
