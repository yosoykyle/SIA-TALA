<?php

namespace App\Actions\Enrollment;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartEnrollment
{
    public function __construct(private readonly CalendarPhaseGateService $calendarPhaseGate) {}

    /**
     * @throws ValidationException
     */
    public function execute(
        StudentProfile $studentProfile,
        Term $term,
        string $studentType,
        User $actor,
    ): Enrollment {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may start an enrollment.');
        }

        $studentType = $this->validatedStudentType($studentType);

        return $this->start($studentProfile, $term, $studentType, rejectFinalExisting: false);
    }

    /**
     * @throws AuthorizationException
     * @throws CalendarGateViolation
     * @throws ValidationException
     */
    public function executeContinuing(
        StudentProfile $studentProfile,
        Term $term,
        string $studentType,
        User $actor,
    ): Enrollment {
        if (! $actor->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleSystemSuperAdmin,
        ])) {
            throw new AuthorizationException('Only Registrar or System Super Admin staff may start a continuing enrollment.');
        }

        $this->calendarPhaseGate->assertEnrollmentWindowOpen($term->id);

        return $this->start(
            $studentProfile,
            $term,
            $this->validatedStudentType($studentType),
            rejectFinalExisting: true,
        );
    }

    private function start(
        StudentProfile $studentProfile,
        Term $term,
        string $studentType,
        bool $rejectFinalExisting,
    ): Enrollment {
        return DB::transaction(function () use ($studentProfile, $term, $studentType, $rejectFinalExisting): Enrollment {
            $lockedProfile = StudentProfile::query()
                ->lockForUpdate()
                ->findOrFail($studentProfile->id);
            $lockedTerm = Term::query()
                ->lockForUpdate()
                ->findOrFail($term->id);

            $enrollment = Enrollment::query()
                ->where('student_profile_id', $lockedProfile->id)
                ->where('term_id', $lockedTerm->id)
                ->lockForUpdate()
                ->first();

            if ($enrollment instanceof Enrollment) {
                if ($rejectFinalExisting && in_array($enrollment->status, [
                    'officially_enrolled',
                    'cancelled',
                    'dropped',
                    'withdrawn',
                ], true)) {
                    throw ValidationException::withMessages([
                        'status' => $this->nonRestartableMessage($enrollment),
                    ]);
                }

                return $enrollment;
            }

            return Enrollment::query()->create([
                'student_profile_id' => $lockedProfile->id,
                'term_id' => $lockedTerm->id,
                'status' => 'pending_review',
                'student_type' => $studentType,
                'registered_at' => null,
                'officially_enrolled_at' => null,
            ]);
        }, attempts: 3);
    }

    private function nonRestartableMessage(Enrollment $enrollment): string
    {
        $status = Str::headline($enrollment->status);
        $message = $enrollment->status === 'officially_enrolled'
            ? 'This student is already officially enrolled for this term. Review the existing enrollment record.'
            : "This enrollment is {$status} and cannot be restarted. Review the existing enrollment record.";

        if (filled($enrollment->status_reason)) {
            $message .= ' Recorded reason: '.$enrollment->status_reason;
        }

        return $message;
    }

    /**
     * @throws ValidationException
     */
    private function validatedStudentType(string $studentType): string
    {
        if (in_array($studentType, ['new', 'transferee', 'returnee', 'regular', 'irregular'], true)) {
            return $studentType;
        }

        throw ValidationException::withMessages([
            'student_type' => 'Select a valid enrollment student type.',
        ]);
    }
}
