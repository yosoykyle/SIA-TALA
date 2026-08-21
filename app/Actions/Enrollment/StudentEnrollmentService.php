<?php

namespace App\Actions\Enrollment;

use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class StudentEnrollmentService
{
    /**
     * @throws ValidationException
     */
    public function completeFinanceClearedHandover(
        Enrollment $enrollment,
        ?User $actor = null,
        ?CarbonImmutable $clearedAt = null,
    ): Enrollment {
        throw ValidationException::withMessages([
            'enrollment' => 'The early finance handover path is retired. Use atomic official enrollment finalization.',
        ]);
    }

    /**
     * @return array{ready:bool, blockers:list<string>}
     */
    public function corReadiness(Enrollment $enrollment): array
    {
        $enrollment->loadMissing([
            'credentialUser',
            'courseEnrollments',
            'currentCorVersion',
        ]);
        $blockers = [];

        if ($enrollment->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled) {
            $blockers[] = 'enrollment_not_official';
        }

        if ($enrollment->credentialUser?->status !== User::StatusActive) {
            $blockers[] = 'account_not_active';
        }

        if (! $enrollment->credentialUser?->hasRole('student')) {
            $blockers[] = 'student_role_missing';
        }

        $activeCourseEnrollments = $enrollment->courseEnrollments
            ->where('status', CourseEnrollment::StatusActive);

        if ($activeCourseEnrollments->isEmpty()) {
            $blockers[] = 'course_enrollment_missing';
        }

        if ($enrollment->currentCorVersion === null) {
            $blockers[] = 'cor_version_missing';
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
        ];
    }
}
