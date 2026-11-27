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
    public function __construct(private readonly StoreRegistrationCreditEvaluation $store) {}

    /**
     * @param  list<int>  $creditedCurriculumEntryIds
     * @param  array<int, array<string, mixed>>  $creditLines  one source line per credited entry ID (source course, TOR grade, units, remarks)
     */
    public function execute(Enrollment $enrollment, User $actor, array $creditedCurriculumEntryIds, string $authorityReference,
        CarbonImmutable $approvedOn, int $expectedSubmissionVersionId, int $expectedCurriculumVersionId, int $expectedLockVersion,
        ?int $expectedEvaluationId = null, array $creditLines = [], ?bool $sameCurriculum = null): RegistrationCreditEvaluation
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only active Registrar staff may record an approved evaluation.');
        }

        return DB::transaction(function () use ($enrollment, $actor, $creditedCurriculumEntryIds, $authorityReference, $approvedOn,
            $expectedSubmissionVersionId, $expectedCurriculumVersionId, $expectedLockVersion, $expectedEvaluationId, $creditLines, $sameCurriculum): RegistrationCreditEvaluation {
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
                || ! in_array($curriculum?->state, [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded], true)
                || (int) $curriculum?->program_id !== (int) $application?->program_id) {
                throw ValidationException::withMessages(['evaluation' => 'The enrollment or evaluation changed. Reopen the academic evaluation.']);
            }
            $ids = array_map(fn ($id): int => (int) $id, $creditedCurriculumEntryIds);
            if (count($ids) !== count(array_unique($ids)) || CurriculumEntry::query()->where('curriculum_version_id', $curriculum->id)->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['credited_curriculum_entry_ids' => 'Choose each credited subject once from the selected curriculum.']);
            }
            $missingLine = collect($ids)->first(fn (int $id): bool => ! array_key_exists($id, $creditLines));
            if ($missingLine !== null || count($creditLines) !== count($ids)) {
                throw ValidationException::withMessages(['credit_lines' => "Record the previous school's course and TOR grade for every credited subject."]);
            }

            return $this->store->store(
                ['enrollment_id' => $case->id, 'application_submission_version_id' => $expectedSubmissionVersionId],
                $curriculum, $creditLines, $sameCurriculum, $authorityReference, $approvedOn, $actor,
            );
        }, attempts: 3);
    }
}
