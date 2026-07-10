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
     * Seed a minimal ACTIVE baseline so a fresh install lets applicants submit.
     * Every admission_category + credential_basis pair receives a DIGITAL_UPLOAD
     * identity requirement (required by ApplicantIntakeService::recordIdentityEvidence)
     * plus one physical document requirement. This is intentionally minimal and is
     * NOT the full institutional matrix, which the Registrar configures through the
     * Admission Requirements surface.
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

    /**
     * @return list<array{admission_category: string, credential_basis: string, requirement_type: string, evidence_method: string, blocking_level: string}>
     */
    private function baselinePolicies(): array
    {
        $pairs = [
            [ApplicantIntake::AdmissionCategoryFirstTimeCollege, ApplicantIntake::CredentialBasisSeniorHighSchool, 'FORM_137'],
            [ApplicantIntake::AdmissionCategoryTransfer, ApplicantIntake::CredentialBasisTransferCredentials, 'TRANSCRIPT_OF_RECORDS'],
            [ApplicantIntake::AdmissionCategoryReturning, ApplicantIntake::CredentialBasisPriorStudentRecord, 'TRANSCRIPT_OF_RECORDS'],
        ];

        $policies = [];

        foreach ($pairs as [$category, $credential, $physicalDocument]) {
            $policies[] = [
                'admission_category' => $category,
                'credential_basis' => $credential,
                'requirement_type' => 'IDENTITY_DOCUMENT',
                'evidence_method' => ChecklistItem::EvidenceMethodDigitalUpload,
                'blocking_level' => ChecklistItem::BlockingHandover,
            ];
            $policies[] = [
                'admission_category' => $category,
                'credential_basis' => $credential,
                'requirement_type' => $physicalDocument,
                'evidence_method' => ChecklistItem::EvidenceMethodPhysicalCopy,
                'blocking_level' => ChecklistItem::BlockingEnrollment,
            ];
        }

        return $policies;
    }
}
