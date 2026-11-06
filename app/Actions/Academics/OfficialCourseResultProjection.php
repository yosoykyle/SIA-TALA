<?php

namespace App\Actions\Academics;

use App\Actions\Grades\IncDeadlineService;
use App\Models\CourseDropRecord;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\GradeOutcomeEvent;
use App\Models\ProgramShiftCreditEntry;
use App\Models\RegistrationCreditEvaluation;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Support\Collection;

class OfficialCourseResultProjection
{
    public function __construct(private readonly IncDeadlineService $deadlines) {}

    /**
     * @return Collection<int, covariant array{
     *     course_enrollment: CourseEnrollment,
     *     course_specification: CourseSpecification|null,
     *     term: Term|null,
     *     event: GradeOutcomeEvent|null,
     *     result: string|null,
     *     units: string,
     *     outcome_complete: bool,
     *     outcome_source: string|null,
     *     inc_state: string|null,
     *     inc_deadline: \Illuminate\Support\Carbon|null,
     *     inc_amendments: Collection,
     *     inc_settled: bool,
     *     average_exclusion: string|null
     * }>
     */
    public function forStudent(StudentProfile $student, ?Term $term = null): Collection
    {
        $results = CourseEnrollment::query()
            ->whereHas('enrollment', fn ($query) => $query
                ->where('student_profile_id', $student->id)
                ->whereNotNull('officially_enrolled_at'))
            ->whereIn('status', [CourseEnrollment::StatusActive, CourseEnrollment::StatusDropped, CourseEnrollment::StatusWithdrawn])
            ->with([
                'enrollment.term.academicYear',
                'termOffering.curriculumEntry.courseSpecification.course',
                'gradeRosterRow.outcomeEvents.deadlineAmendments',
            ])
            ->orderBy('id')
            ->get()
            ->map($this->project(...));
        $superseded = CourseEnrollment::query()
            ->whereHas('enrollment', fn ($query) => $query
                ->where('student_profile_id', $student->id)
                ->whereNotNull('officially_enrolled_at'))
            ->whereIn('supersedes_course_enrollment_id', $results->pluck('course_enrollment.id'))
            ->pluck('supersedes_course_enrollment_id');
        $results = $results->reject(fn (array $result): bool => $result['event'] === null
            && $result['course_enrollment']->status === CourseEnrollment::StatusActive
            && $superseded->contains($result['course_enrollment']->id));

        $credits = $results->contains(fn (array $result): bool => $result['event'] === null || $result['result'] === 'INC')
            ? $this->creditedCourseIds($student)
            : collect();
        $drops = CourseDropRecord::query()
            ->whereIn('course_enrollment_id', $results->pluck('course_enrollment.id'))
            ->whereNotNull('recorded_at')
            ->get()->keyBy('course_enrollment_id');
        $withdrawals = StudentLifecycleChange::query()
            ->where('student_profile_id', $student->id)
            ->where('state', StudentLifecycleChange::StateApplied)
            ->whereIn('type', [StudentLifecycleChange::TypeWithdrawal, StudentLifecycleChange::TypeLeaveOfAbsence, StudentLifecycleChange::TypeTransferOut])
            ->whereDate('effective_on', '<=', today('Asia/Manila'))
            ->get()->keyBy('enrollment_id');

        $results = $results->map(function (array $result) use ($credits, $drops, $withdrawals): array {
            $course = $result['course_enrollment'];
            $drop = $drops->get($course->id);
            $withdrawal = $withdrawals->get($course->enrollment_id);

            if ($result['event'] === null) {
                if ($course->status === CourseEnrollment::StatusDropped && $drop instanceof CourseDropRecord) {
                    $result['result'] = 'DRP';
                    $result['outcome_source'] = 'course-drop:'.$drop->id;
                } elseif ($course->status === CourseEnrollment::StatusWithdrawn && $withdrawal instanceof StudentLifecycleChange) {
                    $result['result'] = 'W';
                    $result['outcome_source'] = 'lifecycle-change:'.$withdrawal->id;
                } elseif ($credits->contains((int) $result['course_specification']?->course_id)) {
                    $result['result'] = 'CREDIT';
                    $result['outcome_source'] = 'approved-credit';
                }
            }

            $result['outcome_complete'] = $result['outcome_source'] !== null;

            return $result;
        });

        return $results->map(function (array $result) use ($results, $credits): array {
            $classification = $result['course_specification']?->academic_classification;
            $courseId = (int) $result['course_specification']?->course_id;
            $code = $result['course_specification']?->course?->code ?? 'Course';

            if ($result['result'] === 'INC' && $result['event'] instanceof GradeOutcomeEvent) {
                if ($result['event']->deadline === null) {
                    $result['outcome_complete'] = false;

                    return $result;
                }

                $result['inc_state'] = $this->deadlines->state($result['event']);
                $result['inc_deadline'] = $this->deadlines->currentDeadline($result['event']);
                $result['inc_amendments'] = $result['event']->deadlineAmendments->sortBy('recorded_at')->values();
                $separatelySatisfied = $credits->contains($courseId)
                    || $results->contains(fn (array $attempt): bool => $courseId > 0
                        && (int) $attempt['course_specification']?->course_id === $courseId
                        && $attempt['event'] !== null
                        && is_numeric($attempt['result'])
                        && (float) $attempt['result'] <= 4.00);
                $result['inc_settled'] = $result['inc_state'] === IncDeadlineService::StateCompletionOverdue || $separatelySatisfied;
                $result['average_exclusion'] = $code.': INC contributes no grade points or units'
                    .($separatelySatisfied ? '; requirement satisfied by a separate released attempt or approved credit.'
                        : ($result['inc_settled'] ? '; completion overdue, no credit.' : '; completion remains open.'));
            } elseif (in_array($classification, [CourseSpecification::AcademicClassificationPe, CourseSpecification::AcademicClassificationNstp], true)) {
                $result['average_exclusion'] = $code.': PE/NSTP-equivalent course excluded.';
            } elseif (in_array($result['result'], ['DRP', 'W', 'CREDIT'], true)) {
                $result['average_exclusion'] = $code.': nonnumeric Registrar outcome excluded.';
            }

            return $result;
        })->when($term, fn (Collection $attempts): Collection => $attempts->filter(
            fn (array $result): bool => (int) $result['term']?->id === (int) $term->id,
        ))->values();
    }

    /** @return Collection<int, int> */
    private function creditedCourseIds(StudentProfile $student): Collection
    {
        $shiftCredits = ProgramShiftCreditEntry::query()
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->whereHas('lifecycleChange', fn ($query) => $query
                ->where('student_profile_id', $student->id)
                ->where('state', StudentLifecycleChange::StateApplied))
            ->get();
        $entryIds = collect(RegistrationCreditEvaluation::creditedEntryIdsForStudent($student))
            ->concat($shiftCredits->pluck('curriculum_entry_id'));

        return CurriculumEntry::query()->whereIn('id', $entryIds)
            ->with('courseSpecification')
            ->get()->map(fn (CurriculumEntry $entry): int => (int) $entry->courseSpecification?->course_id)
            ->filter()->unique()->values();
    }

    /** @return array<string, mixed> */
    private function project(CourseEnrollment $courseEnrollment): array
    {
        $latest = $courseEnrollment->gradeRosterRow?->outcomeEvents
            ->whereNotNull('released_at')
            ->sortByDesc('id')
            ->first();

        return [
            'course_enrollment' => $courseEnrollment,
            'course_specification' => $courseEnrollment->termOffering?->curriculumEntry?->courseSpecification,
            'term' => $courseEnrollment->enrollment?->term,
            'event' => $latest instanceof GradeOutcomeEvent ? $latest : null,
            'result' => $latest?->result_code,
            'units' => (string) $courseEnrollment->units_snapshot,
            'outcome_complete' => $latest instanceof GradeOutcomeEvent,
            'outcome_source' => $latest instanceof GradeOutcomeEvent ? 'grade-outcome:'.$latest->id : null,
            'inc_state' => null,
            'inc_deadline' => null,
            'inc_amendments' => collect(),
            'inc_settled' => false,
            'average_exclusion' => null,
        ];
    }
}
