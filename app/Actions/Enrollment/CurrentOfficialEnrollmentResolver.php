<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;

class CurrentOfficialEnrollmentResolver
{
    public function forStudent(User $student): ?Enrollment
    {
        $profile = $student->studentProfile()->first();

        return $profile instanceof StudentProfile ? $this->forProfile($profile) : null;
    }

    public function forProfile(StudentProfile $profile): ?Enrollment
    {
        return Enrollment::query()
            ->select('enrollments.*')
            ->join('terms', 'terms.id', '=', 'enrollments.term_id')
            ->where('enrollments.student_profile_id', $profile->id)
            ->where('enrollments.status', 'officially_enrolled')
            ->whereNotNull('enrollments.officially_enrolled_at')
            ->where('terms.state', Term::StateActive)
            ->orderByDesc('terms.starts_on')
            ->orderByDesc('terms.id')
            ->orderByDesc('enrollments.officially_enrolled_at')
            ->orderByDesc('enrollments.id')
            ->first();
    }
}
