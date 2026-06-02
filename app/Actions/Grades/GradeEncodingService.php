<?php

namespace App\Actions\Grades;

use App\Models\Grade;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;

class GradeEncodingService
{
    public function __construct(
        private readonly SHSGradingService $shsGrading,
        private readonly CollegeGradingService $collegeGrading,
    ) {}

    /**
     * @param  array<string, int|float|string|null>  $periodGrades
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function encode(int $enrollmentSubjectId, array $periodGrades, User $actor, ?CarbonImmutable $encodedAt = null): Grade
    {
        $timestamp = $encodedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($enrollmentSubjectId, $periodGrades, $actor, $timestamp): Grade {
            [$enrollmentSubject, $enrollment] = $this->lockedContext($enrollmentSubjectId);

            $this->assertCanEncode($actor, $enrollmentSubject, $enrollment);
            $this->assertEnrollmentSubjectCanReceiveGrade($enrollmentSubject);

            $grade = $this->lockedGrade($enrollmentSubject);

            if ($grade instanceof Grade && $grade->is_finalized) {
                throw ValidationException::withMessages([
                    'grade' => 'Finalized grades cannot be edited.',
                ]);
            }

            $attributes = $this->isShs($enrollment)
                ? $this->shsAttributes($periodGrades)
                : $this->collegeAttributes($periodGrades);

            $grade = $this->persistGrade($grade, $enrollmentSubject, $enrollment, $actor, $attributes);
            $this->recordAudit($grade, $actor, 'grade_encoded', $attributes['audit_payload'], $timestamp);

            return $grade->fresh();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function markIncomplete(int $enrollmentSubjectId, User $actor, ?CarbonImmutable $encodedAt = null): Grade
    {
        $timestamp = $encodedAt ?? CarbonImmutable::now(config('app.timezone'));

        return DB::transaction(function () use ($enrollmentSubjectId, $actor, $timestamp): Grade {
            [$enrollmentSubject, $enrollment] = $this->lockedContext($enrollmentSubjectId);

            $this->assertCanEncode($actor, $enrollmentSubject, $enrollment);
            $this->assertEnrollmentSubjectCanReceiveGrade($enrollmentSubject);

            $grade = $this->lockedGrade($enrollmentSubject);

            if ($grade instanceof Grade && $grade->is_finalized) {
                throw ValidationException::withMessages([
                    'grade' => 'Finalized grades cannot be edited.',
                ]);
            }

            $incExpiresAt = CarbonImmutable::parse((string) $enrollment->term_end_date, config('app.timezone'))
                ->addDays(365)
                ->endOfDay();

            $attributes = [
                'prelim_grade' => null,
                'midterm_grade' => null,
                'final_grade' => null,
                'grade' => null,
                'remarks' => 'inc',
                'is_inc' => true,
                'inc_expires_at' => $incExpiresAt,
                'audit_payload' => [
                    'mode' => 'inc',
                    'inc_expires_at' => $incExpiresAt->toIso8601String(),
                ],
            ];

            $grade = $this->persistGrade($grade, $enrollmentSubject, $enrollment, $actor, $attributes);
            $this->recordAudit($grade, $actor, 'grade_marked_incomplete', $attributes['audit_payload'], $timestamp);

            return $grade->fresh();
        });
    }

    /**
     * @return array{stdClass, stdClass}
     */
    private function lockedContext(int $enrollmentSubjectId): array
    {
        $enrollmentSubject = DB::table('enrollment_subjects')
            ->where('id', $enrollmentSubjectId)
            ->lockForUpdate()
            ->first();

        if (! $enrollmentSubject instanceof stdClass) {
            throw new RuntimeException('Enrollment subject not found.');
        }

        $enrollment = DB::table('enrollments')
            ->join('student_profiles', 'student_profiles.id', '=', 'enrollments.student_profile_id')
            ->leftJoin('programs', 'programs.id', '=', 'student_profiles.program_id')
            ->join('terms', 'terms.id', '=', 'enrollments.term_id')
            ->where('enrollments.id', $enrollmentSubject->enrollment_id)
            ->select([
                'enrollments.id',
                'enrollments.term_id',
                'enrollments.section_id',
                'enrollments.student_profile_id',
                'student_profiles.education_level as student_education_level',
                'programs.department as program_department',
                'terms.term_end_date',
            ])
            ->lockForUpdate()
            ->first();

        if (! $enrollment instanceof stdClass) {
            throw new RuntimeException('Enrollment not found for enrollment subject.');
        }

        return [$enrollmentSubject, $enrollment];
    }

    /**
     * @throws AuthorizationException
     */
    private function assertCanEncode(User $actor, stdClass $enrollmentSubject, stdClass $enrollment): void
    {
        if (! $actor->can('encode-grades')) {
            throw new AuthorizationException('Only faculty with grade encoding permission can encode grades.');
        }

        if ($this->isAssignedViaSectionMeeting($actor, $enrollmentSubject) || $this->isAssignedViaSectionTeacher($actor, $enrollmentSubject, $enrollment)) {
            return;
        }

        throw new AuthorizationException('Only the assigned faculty can encode grades for this subject.');
    }

    /**
     * @throws ValidationException
     */
    private function assertEnrollmentSubjectCanReceiveGrade(stdClass $enrollmentSubject): void
    {
        if ((bool) $enrollmentSubject->is_dropped || $enrollmentSubject->status !== 'enrolled') {
            throw ValidationException::withMessages([
                'enrollment_subject_id' => 'Dropped or inactive subjects cannot receive grades.',
            ]);
        }
    }

    private function isAssignedViaSectionMeeting(User $actor, stdClass $enrollmentSubject): bool
    {
        if ($enrollmentSubject->section_meeting_id === null) {
            return false;
        }

        return DB::table('section_meetings')
            ->where('id', $enrollmentSubject->section_meeting_id)
            ->where('subject_id', $enrollmentSubject->subject_id)
            ->where('faculty_id', $actor->id)
            ->exists();
    }

    private function isAssignedViaSectionTeacher(User $actor, stdClass $enrollmentSubject, stdClass $enrollment): bool
    {
        if ($enrollment->section_id === null) {
            return false;
        }

        return DB::table('section_teacher')
            ->where('section_id', $enrollment->section_id)
            ->where('subject_id', $enrollmentSubject->subject_id)
            ->where('user_id', $actor->id)
            ->exists();
    }

    private function lockedGrade(stdClass $enrollmentSubject): ?Grade
    {
        return Grade::query()
            ->where('enrollment_id', $enrollmentSubject->enrollment_id)
            ->where('subject_id', $enrollmentSubject->subject_id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, int|float|string|null>  $periodGrades
     * @return array{prelim_grade:string, midterm_grade:string, final_grade:string, grade:string, remarks:string, is_inc:bool, inc_expires_at:null, audit_payload:array<string, mixed>}
     */
    private function shsAttributes(array $periodGrades): array
    {
        $result = $this->shsGrading->calculateFinalGrade($periodGrades);

        return [
            // V1 schema stores the active-semester SHS quarters in the first two period columns.
            'prelim_grade' => $result['q1'],
            'midterm_grade' => $result['q2'],
            'final_grade' => $result['final_grade'],
            'grade' => $result['final_grade'],
            'remarks' => $result['remarks'],
            'is_inc' => false,
            'inc_expires_at' => null,
            'audit_payload' => [
                'mode' => 'shs',
                'q1' => $result['q1'],
                'q2' => $result['q2'],
                'final_grade' => $result['final_grade'],
            ],
        ];
    }

    /**
     * @param  array<string, int|float|string|null>  $periodGrades
     * @return array{prelim_grade:string, midterm_grade:string, final_grade:string, grade:string, remarks:string, is_inc:bool, inc_expires_at:null, audit_payload:array<string, mixed>}
     */
    private function collegeAttributes(array $periodGrades): array
    {
        $result = $this->collegeGrading->calculateFinalGrade($periodGrades);

        return [
            'prelim_grade' => $result['prelim'],
            'midterm_grade' => $result['midterm'],
            'final_grade' => $result['final_raw_average'],
            'grade' => $result['equivalent_grade'],
            'remarks' => $result['remarks'],
            'is_inc' => false,
            'inc_expires_at' => null,
            'audit_payload' => [
                'mode' => 'college',
                'prelim' => $result['prelim'],
                'midterm' => $result['midterm'],
                'final' => $result['final'],
                'final_raw_average' => $result['final_raw_average'],
                'equivalent_grade' => $result['equivalent_grade'],
            ],
        ];
    }

    /**
     * @param  array{prelim_grade:string|null, midterm_grade:string|null, final_grade:string|null, grade:string|null, remarks:string, is_inc:bool, inc_expires_at:mixed, audit_payload:array<string, mixed>}  $attributes
     */
    private function persistGrade(?Grade $grade, stdClass $enrollmentSubject, stdClass $enrollment, User $actor, array $attributes): Grade
    {
        $grade ??= new Grade([
            'enrollment_id' => $enrollmentSubject->enrollment_id,
            'enrollment_subject_id' => $enrollmentSubject->id,
            'subject_id' => $enrollmentSubject->subject_id,
            'term_id' => $enrollment->term_id,
        ]);

        $grade->forceFill([
            'enrollment_subject_id' => $enrollmentSubject->id,
            'faculty_id' => $actor->id,
            'prelim_grade' => $attributes['prelim_grade'],
            'midterm_grade' => $attributes['midterm_grade'],
            'final_grade' => $attributes['final_grade'],
            'grade' => $attributes['grade'],
            'remarks' => $attributes['remarks'],
            'is_inc' => $attributes['is_inc'],
            'inc_expires_at' => $attributes['inc_expires_at'],
        ])->save();

        return $grade;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordAudit(Grade $grade, User $actor, string $event, array $properties, CarbonImmutable $recordedAt): void
    {
        activity('grades')
            ->causedBy($actor)
            ->performedOn($grade)
            ->event($event)
            ->withProperties($properties + [
                'enrollment_id' => $grade->enrollment_id,
                'enrollment_subject_id' => $grade->enrollment_subject_id,
                'subject_id' => $grade->subject_id,
            ])
            ->createdAt($recordedAt)
            ->log('Grade encoding updated.');
    }

    private function isShs(stdClass $enrollment): bool
    {
        $educationLevel = mb_strtolower((string) $enrollment->student_education_level);
        $department = mb_strtolower((string) $enrollment->program_department);

        return in_array($educationLevel, ['shs', 'senior high school'], true)
            || in_array($department, ['shs', 'senior high school'], true);
    }
}
