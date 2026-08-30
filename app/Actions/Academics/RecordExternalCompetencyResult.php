<?php

namespace App\Actions\Academics;

use App\Actions\Completion\CompletionReadinessProjection;
use App\Models\ExternalCompetencyRequirement;
use App\Models\ExternalCompetencyResult;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecordExternalCompetencyResult
{
    public function __construct(private readonly CompletionReadinessProjection $completionReadiness) {}

    public function execute(
        ExternalCompetencyRequirement $requirement,
        StudentProfile $student,
        string $outcome,
        string $evidenceReference,
        string $authorityReference,
        Carbon $authorityDate,
        User $registrar,
        ?Carbon $assessmentDate = null,
        ?string $externalSource = null,
        ?string $credentialType = null,
        ?string $credentialReference = null,
        ?Carbon $credentialValidUntil = null,
        ?string $safeRemarks = null,
        ?int $expectedPredecessorId = null,
        ?string $commandKey = null,
    ): ExternalCompetencyResult {
        if (! $registrar->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff can record external competency evidence.');
        }

        if (! in_array($outcome, [ExternalCompetencyResult::OutcomeNotYetCompetent, ExternalCompetencyResult::OutcomeCompetent], true)) {
            throw new RuntimeException('External competency outcome must be Not Yet Competent or Competent.');
        }

        $assessmentDate ??= $authorityDate;
        $externalSource = trim($externalSource ?? $authorityReference);
        $credentialType = filled($credentialType) ? strtoupper(trim((string) $credentialType)) : null;
        $credentialReference = filled($credentialReference) ? trim((string) $credentialReference) : null;
        $safeRemarks = filled($safeRemarks) ? trim((string) $safeRemarks) : null;

        if (blank(trim($evidenceReference)) || blank(trim($authorityReference)) || $externalSource === '') {
            throw new RuntimeException('Evidence, external source, and authority are required.');
        }

        if ($credentialType !== null && ! in_array($credentialType, ['NC', 'COC'], true)) {
            throw new RuntimeException('Credential type must be NC or COC.');
        }

        if (($credentialType === null) !== ($credentialReference === null)) {
            throw new RuntimeException('Credential type and reference must be recorded together.');
        }

        $sourceKey = hash('sha256', $commandKey ?? implode('|', [
            $requirement->id,
            $student->id,
            $outcome,
            trim($evidenceReference),
            trim($authorityReference),
            $authorityDate->toDateString(),
            $assessmentDate->toDateString(),
            $externalSource,
            $credentialType,
            $credentialReference,
            $credentialValidUntil?->toDateString(),
            $safeRemarks,
        ]));

        return DB::transaction(function () use ($requirement, $student, $outcome, $evidenceReference, $authorityReference, $authorityDate, $registrar, $assessmentDate, $externalSource, $credentialType, $credentialReference, $credentialValidUntil, $safeRemarks, $expectedPredecessorId, $sourceKey): ExternalCompetencyResult {
            $student = StudentProfile::query()->lockForUpdate()->findOrFail($student->id);
            $requirement = ExternalCompetencyRequirement::query()
                ->with('curriculumVersion')
                ->lockForUpdate()
                ->findOrFail($requirement->id);

            $existing = ExternalCompetencyResult::query()->where('source_key', $sourceKey)->first();

            if ($existing instanceof ExternalCompetencyResult) {
                return $existing;
            }

            if ($requirement->state !== 'ACTIVE'
                || $requirement->curriculumVersion?->state !== 'ACTIVE'
                || (int) $requirement->curriculum_version_id !== (int) $student->curriculum_version_id) {
                throw new RuntimeException('The external competency requirement is not active for the Student current curriculum.');
            }

            $current = ExternalCompetencyResult::query()
                ->where('external_competency_requirement_id', $requirement->id)
                ->where('student_profile_id', $student->id)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            $currentId = $current instanceof ExternalCompetencyResult ? (int) $current->id : 0;

            if ($expectedPredecessorId !== null && $currentId !== $expectedPredecessorId) {
                throw new RuntimeException('External competency evidence changed while this action was open. Review the current result and try again.');
            }

            $result = ExternalCompetencyResult::query()->create([
                'external_competency_requirement_id' => $requirement->id,
                'student_profile_id' => $student->id,
                'outcome' => $outcome,
                'assessment_date' => $assessmentDate,
                'external_source' => $externalSource,
                'credential_type' => $credentialType,
                'credential_reference' => $credentialReference,
                'credential_valid_until' => $credentialValidUntil,
                'safe_remarks' => $safeRemarks,
                'source_key' => $sourceKey,
                'evidence_reference' => trim($evidenceReference),
                'authority_reference' => trim($authorityReference),
                'authority_date' => $authorityDate,
                'recorded_by' => $registrar->id,
                'recorded_at' => now(),
                'supersedes_result_id' => $current?->id,
                'is_current' => true,
            ]);

            $current?->update(['is_current' => false]);
            $this->completionReadiness->persist($student, $registrar);

            return $result;
        }, attempts: 3);
    }
}
