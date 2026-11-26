<?php

namespace App\Actions\Enrollment;

use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\RegistrationCreditEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a replacement transfer crediting for an officially enrolled Student. The replacement
 * counts immediately; earlier crediting stays in history.
 */
class CorrectRegistrationCreditEvaluation
{
    public function __construct(private readonly StoreRegistrationCreditEvaluation $store) {}

    /** @param array<int, array<string, mixed>> $creditLines one source line per credited entry ID */
    public function execute(StudentProfile $student, User $actor, array $creditLines, ?bool $sameCurriculum,
        string $authorityReference, CarbonImmutable $approvedOn, int $expectedEvaluationId): RegistrationCreditEvaluation
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only active Registrar staff may correct transfer crediting.');
        }

        return DB::transaction(function () use ($student, $actor, $creditLines, $sameCurriculum, $authorityReference, $approvedOn, $expectedEvaluationId): RegistrationCreditEvaluation {
            $profile = StudentProfile::query()->lockForUpdate()->findOrFail($student->id);
            $current = RegistrationCreditEvaluation::effectiveForStudent($profile);
            if ($current === null) {
                throw ValidationException::withMessages(['evaluation' => 'No transfer crediting is recorded for this student’s current curriculum.']);
            }
            Enrollment::query()->lockForUpdate()->findOrFail($current->enrollment_id);
            if ((int) RegistrationCreditEvaluation::effectiveForStudent($profile)?->id !== $expectedEvaluationId) {
                throw ValidationException::withMessages(['evaluation' => 'The crediting changed. Reopen Correct crediting.']);
            }

            return $this->store->store(
                ['enrollment_id' => $current->enrollment_id, 'application_submission_version_id' => $current->application_submission_version_id],
                CurriculumVersion::query()->findOrFail($current->curriculum_version_id),
                $creditLines, $sameCurriculum, $authorityReference, $approvedOn, $actor,
            );
        }, attempts: 3);
    }
}
