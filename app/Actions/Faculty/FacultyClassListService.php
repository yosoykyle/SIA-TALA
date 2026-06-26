<?php

namespace App\Actions\Faculty;

use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Models\Enrollment;
use App\Models\Hold;
use App\Models\ScheduleGenerationRun;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use stdClass;

class FacultyClassListService
{
    public function __construct(
        private readonly DecimalMoney $money,
        private readonly HoldEvaluationService $holds,
    ) {}

    /**
     * @return list<FacultyClassListRow>
     *
     * @throws AuthorizationException
     */
    public function forSectionSubject(int $sectionId, int $subjectId, User $faculty): array
    {
        $this->assertCanViewClassList($faculty);
        $this->assertAssignedToSectionSubject($sectionId, $subjectId, $faculty);

        $rows = DB::table('enrollment_subjects')
            ->join('enrollments', 'enrollments.id', '=', 'enrollment_subjects.enrollment_id')
            ->join('student_profiles', 'student_profiles.id', '=', 'enrollments.student_profile_id')
            ->join('users', 'users.id', '=', 'student_profiles.user_id')
            ->where('enrollments.section_id', $sectionId)
            ->where('enrollment_subjects.subject_id', $subjectId)
            ->where('enrollment_subjects.status', 'enrolled')
            ->where('enrollment_subjects.is_dropped', false)
            ->whereIn('enrollments.status', ['pre_enrolled', 'officially_enrolled'])
            ->orderBy('users.name')
            ->select([
                'enrollments.id as enrollment_id',
                'enrollments.student_profile_id',
                'enrollments.section_id',
                'enrollments.term_id',
                'enrollments.status as enrollment_status',
                'student_profiles.student_id',
                'student_profiles.year_level',
                'student_profiles.modality',
                'users.name as student_name',
                'enrollment_subjects.subject_id',
            ])
            ->get();

        return $rows
            ->map(fn (stdClass $row): FacultyClassListRow => new FacultyClassListRow(
                enrollmentId: (int) $row->enrollment_id,
                studentProfileId: (int) $row->student_profile_id,
                studentId: (string) $row->student_id,
                studentName: (string) $row->student_name,
                sectionId: (int) $row->section_id,
                subjectId: (int) $row->subject_id,
                termId: (int) $row->term_id,
                yearLevel: $row->year_level !== null ? (string) $row->year_level : null,
                modality: $row->modality !== null ? (string) $row->modality : null,
                enrollmentStatus: (string) $row->enrollment_status,
                financeStatus: $this->facultyPaymentStatusFor(
                    enrollmentId: (int) $row->enrollment_id,
                    studentProfileId: (int) $row->student_profile_id,
                ),
            ))
            ->all();
    }

    public function facultyPaymentStatusFor(int $enrollmentId, int $studentProfileId): string
    {
        $currentBalance = DB::table('student_profiles')
            ->where('id', $studentProfileId)
            ->value('current_balance');

        if ($currentBalance !== null && $this->money->greaterThanZero((string) $currentBalance)) {
            return 'with_balance';
        }

        $studentProfile = StudentProfile::query()->find($studentProfileId);
        $enrollment = Enrollment::query()->find($enrollmentId);

        if ($studentProfile instanceof StudentProfile
            && $this->holds->hasActiveBlockingHold($studentProfile, [Hold::BlockingEnrollment], $enrollment)
        ) {
            return 'with_balance';
        }

        return 'paid';
    }

    /**
     * @throws AuthorizationException
     */
    private function assertCanViewClassList(User $faculty): void
    {
        if ($faculty->hasRole('faculty') && $faculty->can('view-class-list')) {
            return;
        }

        throw new AuthorizationException('Only assigned faculty can view faculty class lists.');
    }

    /**
     * @throws AuthorizationException
     */
    private function assertAssignedToSectionSubject(int $sectionId, int $subjectId, User $faculty): void
    {
        $assignedViaMeeting = DB::table('section_meetings')
            ->where('section_id', $sectionId)
            ->where('subject_id', $subjectId)
            ->where('faculty_id', $faculty->id)
            ->where(function ($query): void {
                $query->whereNull('schedule_generation_run_id')
                    ->orWhereExists(function ($runQuery): void {
                        $runQuery
                            ->selectRaw('1')
                            ->from('schedule_generation_runs')
                            ->whereColumn('schedule_generation_runs.id', 'section_meetings.schedule_generation_run_id')
                            ->where('schedule_generation_runs.status', '!=', ScheduleGenerationRun::StatusSuperseded);
                    });
            })
            ->exists();

        $assignedViaSectionTeacher = DB::table('section_teacher')
            ->where('section_id', $sectionId)
            ->where('subject_id', $subjectId)
            ->where('user_id', $faculty->id)
            ->exists();

        if ($assignedViaMeeting || $assignedViaSectionTeacher) {
            return;
        }

        throw new AuthorizationException('Faculty can view only assigned section and subject class lists.');
    }
}
