<?php

namespace App\Actions\Academics;

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
    public function execute(
        ExternalCompetencyRequirement $requirement,
        StudentProfile $student,
        string $outcome,
        string $evidenceReference,
        string $authorityReference,
        Carbon $authorityDate,
        User $registrar,
    ): ExternalCompetencyResult {
        if (! $registrar->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff can record external competency evidence.');
        }

        if (! in_array($outcome, [ExternalCompetencyResult::OutcomeNotYetCompetent, ExternalCompetencyResult::OutcomeCompetent], true)) {
            throw new RuntimeException('External competency outcome must be Not Yet Competent or Competent.');
        }

        if ($requirement->state !== 'ACTIVE' || blank(trim($evidenceReference)) || blank(trim($authorityReference))) {
            throw new RuntimeException('Active requirement, evidence, and authority are required.');
        }

        return DB::transaction(function () use ($requirement, $student, $outcome, $evidenceReference, $authorityReference, $authorityDate, $registrar): ExternalCompetencyResult {
            $current = ExternalCompetencyResult::query()
                ->where('external_competency_requirement_id', $requirement->id)
                ->where('student_profile_id', $student->id)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            $result = ExternalCompetencyResult::query()->create([
                'external_competency_requirement_id' => $requirement->id,
                'student_profile_id' => $student->id,
                'outcome' => $outcome,
                'evidence_reference' => trim($evidenceReference),
                'authority_reference' => trim($authorityReference),
                'authority_date' => $authorityDate,
                'recorded_by' => $registrar->id,
                'recorded_at' => now(),
                'supersedes_result_id' => $current?->id,
                'is_current' => true,
            ]);

            $current?->update(['is_current' => false]);

            return $result;
        }, attempts: 3);
    }
}
