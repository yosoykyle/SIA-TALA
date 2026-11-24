<?php

namespace App\Actions\Academics;

use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CurriculumEntry;
use App\Models\ProgramShiftCreditEntry;
use App\Models\RegistrationCreditEvaluation;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

class CurriculumEvaluation
{
    public function __construct(private readonly OfficialCourseResultProjection $results) {}

    /** @return array{required: list<array<string, mixed>>, completed_units: string, deficiency_count: int} */
    public function forStudent(StudentProfile $student): array
    {
        $attempts = $this->results->forStudent($student);
        $acceptedCredits = ProgramShiftCreditEntry::query()
            ->with('curriculumEntry.courseSpecification')
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->where('state', ProgramShiftCreditEntry::StateRecorded)
            ->whereHas('curriculumEntry', fn ($query) => $query->where('curriculum_version_id', $student->curriculum_version_id))
            ->whereHas('lifecycleChange', fn ($query) => $query
                ->where('student_profile_id', $student->id)
                ->where('state', StudentLifecycleChange::StateApplied))
            ->get()
            ->filter(fn (ProgramShiftCreditEntry $credit): bool => $credit->numeric_grade === null || (float) $credit->numeric_grade <= 4.00)
            ->keyBy('curriculum_entry_id');
        $transferCredits = RegistrationCreditEvaluation::creditedEntryIdsForStudent($student);
        $entries = CurriculumEntry::query()
            ->where('curriculum_version_id', $student->curriculum_version_id)
            ->with(['courseSpecification.course', 'courseSpecification.requirements.relatedCourse'])
            ->orderBy('sequence')
            ->get();
        $equivalentCourseIdsByCourseId = $this->equivalentCourseIdsByCourseId($entries);

        $rows = $entries
            ->map(function (CurriculumEntry $entry) use ($attempts, $acceptedCredits, $transferCredits, $equivalentCourseIdsByCourseId): array {
                $courseId = $entry->courseSpecification?->course_id;
                $matchingCourseIds = $courseId !== null
                    ? ($equivalentCourseIdsByCourseId[(int) $courseId] ?? [(int) $courseId])
                    : [];
                $entryAttempts = $attempts->filter(fn (array $attempt): bool => $courseId !== null
                    ? in_array((int) ($attempt['course_specification']?->course_id ?? 0), $matchingCourseIds, true)
                    : (int) $attempt['course_specification']?->id === (int) $entry->course_specification_id);
                $passed = $entryAttempts->contains(
                    fn (array $attempt): bool => RegistrationAcademicEligibilityQuery::isPassingResult($attempt['result'] ?? null),
                );
                $credited = $acceptedCredits->has($entry->id) || in_array((int) $entry->id, $transferCredits, true);
                $inProgress = $entryAttempts->contains(fn (array $attempt): bool => $attempt['event'] === null
                    && $attempt['course_enrollment']->is_current
                    && $attempt['course_enrollment']->status === CourseEnrollment::StatusActive);

                return [
                    'curriculum_entry' => $entry,
                    'status' => match (true) {
                        $credited => 'Approved credit',
                        $passed => 'Completed',
                        $inProgress => 'In progress',
                        $entryAttempts->isEmpty() => 'Not taken',
                        default => 'Deficient',
                    },
                    'attempt_count' => $entryAttempts->count(),
                    'credited_units' => $passed || $credited ? (string) $entry->courseSpecification->credit_units : '0.00',
                    'requirements' => $entry->courseSpecification->requirements->map(fn ($requirement): array => [
                        'type' => $requirement->rule_type,
                        'related_course_id' => $requirement->related_course_id,
                        'related_course_code' => $requirement->relatedCourse?->code,
                    ])->values()->all(),
                ];
            });

        return [
            'required' => $rows->values()->all(),
            'completed_units' => number_format($rows->sum(fn (array $row): float => (float) $row['credited_units']), 2, '.', ''),
            'deficiency_count' => $rows->reject(fn (array $row): bool => in_array($row['status'], ['Completed', 'Approved credit'], true))->count(),
        ];
    }

    /**
     * @param  Collection<int, CurriculumEntry>  $entries
     * @return array<int, list<int>>
     */
    private function equivalentCourseIdsByCourseId(Collection $entries): array
    {
        $courseIds = $entries
            ->pluck('courseSpecification.course_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($courseIds->isEmpty()) {
            return [];
        }

        $map = [];
        foreach ($courseIds as $courseId) {
            $map[$courseId] = [$courseId];
        }

        $equivalencies = CourseRequirement::query()
            ->join('course_specifications', 'course_specifications.id', '=', 'course_requirements.course_specification_id')
            ->where('course_requirements.state', CourseRequirement::StateActive)
            ->where('course_requirements.rule_type', CourseRequirement::TypeEquivalency)
            ->where(function ($query) use ($courseIds): void {
                $query->whereIn('course_specifications.course_id', $courseIds)
                    ->orWhereIn('course_requirements.related_course_id', $courseIds);
            })
            ->get([
                'course_specifications.course_id as source_course_id',
                'course_requirements.related_course_id as related_course_id',
            ]);

        foreach ($equivalencies as $row) {
            $sourceId = (int) ($row->source_course_id ?? 0);
            $relatedId = (int) ($row->related_course_id ?? 0);
            if ($sourceId <= 0 || $relatedId <= 0) {
                continue;
            }

            if (isset($map[$sourceId]) && ! in_array($relatedId, $map[$sourceId], true)) {
                $map[$sourceId][] = $relatedId;
            }

            if (isset($map[$relatedId]) && ! in_array($sourceId, $map[$relatedId], true)) {
                $map[$relatedId][] = $sourceId;
            }
        }

        return $map;
    }
}
