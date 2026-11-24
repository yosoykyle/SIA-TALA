<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class CurrentOfficialEnrollmentResolver
{
    public function forStudent(User $student): ?Enrollment
    {
        $profile = $student->studentProfile()->first();

        return $profile instanceof StudentProfile ? $this->forProfile($profile) : null;
    }

    public function forStudentInTerm(User $student, ?int $termId = null): ?Enrollment
    {
        if ($termId === null) {
            return $this->forStudent($student);
        }

        return $this->allActiveForStudent($student)
            ->first(fn (Enrollment $enrollment): bool => (int) $enrollment->term_id === $termId);
    }

    /**
     * @return EloquentCollection<int, Enrollment>
     */
    public function allActiveForStudent(User $student): EloquentCollection
    {
        $profile = $student->studentProfile()->first();

        return $profile instanceof StudentProfile
            ? $this->allActiveForProfile($profile)
            : new EloquentCollection;
    }

    public function forProfile(StudentProfile $profile): ?Enrollment
    {
        return $this->activeOfficialEnrollmentsQuery($profile)->first();
    }

    /**
     * @return EloquentCollection<int, Enrollment>
     */
    public function allActiveForProfile(StudentProfile $profile): EloquentCollection
    {
        return $this->activeOfficialEnrollmentsQuery($profile)
            ->with(['term.academicYear'])
            ->get();
    }

    /**
     * @return Builder<Enrollment>
     */
    private function activeOfficialEnrollmentsQuery(StudentProfile $profile): Builder
    {
        return Enrollment::query()
            ->select('enrollments.*')
            ->join('terms', 'terms.id', '=', 'enrollments.term_id')
            ->where('enrollments.student_profile_id', $profile->id)
            ->where('enrollments.status', 'officially_enrolled')
            ->where('enrollments.canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
            ->whereNotNull('enrollments.current_cor_version_id')
            ->whereNotNull('enrollments.officially_enrolled_at')
            ->where('terms.state', Term::StateActive)
            ->orderByDesc('terms.starts_on')
            ->orderByDesc('terms.id')
            ->orderByDesc('enrollments.officially_enrolled_at')
            ->orderByDesc('enrollments.id');
    }
}
