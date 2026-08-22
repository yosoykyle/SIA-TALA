<?php

namespace App\Actions\Academics;

use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\GradeOutcomeEvent;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Support\Collection;

class OfficialCourseResultProjection
{
    /**
     * @return Collection<int, covariant array{
     *     course_enrollment: CourseEnrollment,
     *     course_specification: CourseSpecification|null,
     *     term: Term|null,
     *     event: GradeOutcomeEvent|null,
     *     result: string|null,
     *     units: string
     * }>
     */
    public function forStudent(StudentProfile $student, ?Term $term = null): Collection
    {
        return CourseEnrollment::query()
            ->whereHas('enrollment', function ($query) use ($student, $term): void {
                $query->where('student_profile_id', $student->id)
                    ->whereNotNull('officially_enrolled_at')
                    ->when($term, fn ($termQuery) => $termQuery->where('term_id', $term->id));
            })
            ->whereIn('status', [CourseEnrollment::StatusActive, CourseEnrollment::StatusDropped, CourseEnrollment::StatusWithdrawn])
            ->with([
                'enrollment.term',
                'termOffering.curriculumEntry.courseSpecification.course',
                'gradeRosterRow.outcomeEvents',
            ])
            ->orderBy('id')
            ->get()
            ->map($this->project(...))
            ->values();
    }

    /**
     * @return array{
     *     course_enrollment: CourseEnrollment,
     *     course_specification: CourseSpecification|null,
     *     term: Term|null,
     *     event: GradeOutcomeEvent|null,
     *     result: string|null,
     *     units: string
     * }
     */
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
            'units' => $this->units($courseEnrollment),
        ];
    }

    private function units(CourseEnrollment $courseEnrollment): string
    {
        return (string) $courseEnrollment->units_snapshot;
    }
}
