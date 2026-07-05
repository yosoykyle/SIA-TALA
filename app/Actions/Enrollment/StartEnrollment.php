<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartEnrollment
{
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

        return DB::transaction(function () use ($studentProfile, $term, $studentType): Enrollment {
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
