<?php

namespace App\Actions\Enrollment;

use App\Models\AdmissionApplication;
use App\Models\ApplicationSubmissionVersion;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\RegistrationCreditEvaluation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordRegistrationCreditEvaluation
{
    /** @param list<int> $creditedCurriculumEntryIds */
    public function execute(Enrollment $enrollment, User $actor, array $creditedCurriculumEntryIds, string $authorityReference,
        CarbonImmutable $approvedOn, int $expectedSubmissionVersionId, int $expectedCurriculumVersionId, int $expectedLockVersion,
        ?int $expectedEvaluationId = null): RegistrationCreditEvaluation
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only active Registrar staff may record an approved evaluation.');
        }

        return DB::transaction(function () use ($enrollment, $actor, $creditedCurriculumEntryIds, $authorityReference, $approvedOn,
            $expectedSubmissionVersionId, $expectedCurriculumVersionId, $expectedLockVersion, $expectedEvaluationId): RegistrationCreditEvaluation {
            $case = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            $application = $case->admissionApplication()->lockForUpdate()->first();
            $curriculum = CurriculumVersion::query()->find($expectedCurriculumVersionId);
            $latestId = RegistrationCreditEvaluation::query()->where('enrollment_id', $case->id)->latest('id')->value('id');
            if ($case->canonical_outcome !== Enrollment::OutcomeInProgress || $case->lock_version !== $expectedLockVersion
                || $application?->application_path !== AdmissionApplication::PathTransferee
                || $expectedSubmissionVersionId < 1
                || ! ApplicationSubmissionVersion::query()->where('admission_application_id', $application->id)->whereKey($expectedSubmissionVersionId)->exists()
                || (int) $application?->current_submission_version_id !== $expectedSubmissionVersionId
                || ($latestId === null ? null : (int) $latestId) !== $expectedEvaluationId
                || $curriculum?->state !== CurriculumVersion::StateActive || (int) $curriculum?->program_id !== (int) $application?->program_id) {
                throw ValidationException::withMessages(['evaluation' => 'The enrollment or evaluation changed. Reopen the academic evaluation.']);
            }
            $ids = array_map(fn ($id): int => (int) $id, $creditedCurriculumEntryIds);
            if (count($ids) !== count(array_unique($ids)) || CurriculumEntry::query()->where('curriculum_version_id', $curriculum->id)->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['credited_curriculum_entry_ids' => 'Choose each credited subject once from the selected curriculum.']);
            }
            if (trim($authorityReference) === '' || mb_strlen($authorityReference) > 255 || $approvedOn->startOfDay()->isAfter(today())) {
                throw ValidationException::withMessages(['authority_reference' => 'Record the approval reference and an approval date that is not in the future.']);
            }
            sort($ids);

            return RegistrationCreditEvaluation::query()->create([
                'enrollment_id' => $case->id, 'application_submission_version_id' => $expectedSubmissionVersionId,
                'curriculum_version_id' => $curriculum->id, 'credited_curriculum_entry_ids' => $ids,
                'authority_reference' => trim($authorityReference), 'approved_on' => $approvedOn,
                'recorded_by' => $actor->id, 'recorded_at' => now(),
            ]);
        }, attempts: 3);
    }
}
