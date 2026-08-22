<?php

namespace App\Actions\Academics;

use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;

class CurriculumEvaluation
{
    public function __construct(private readonly OfficialCourseResultProjection $results) {}

    /** @return array{required: list<array<string, mixed>>, completed_units: string, deficiency_count: int} */
    public function forStudent(StudentProfile $student): array
    {
        $attempts = $this->results->forStudent($student);
        $acceptedCredits = ProgramShiftCreditEntry::query()
            ->where('treatment', ProgramShiftCreditEntry::TreatmentAccepted)
            ->whereHas('lifecycleChange', fn ($query) => $query
                ->where('student_profile_id', $student->id)
                ->where('state', StudentLifecycleChange::StateApplied))
            ->get()
            ->keyBy('curriculum_entry_id');
        $currentEntryIds = CourseEnrollment::query()
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->whereHas('enrollment', fn ($query) => $query->where('student_profile_id', $student->id))
            ->with('termOffering')
            ->get()
            ->pluck('termOffering.curriculum_entry_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();
        $rows = CurriculumEntry::query()
            ->where('curriculum_version_id', $student->curriculum_version_id)
            ->with(['courseSpecification.course', 'courseSpecification.requirements.relatedCourse'])
            ->orderBy('sequence')
            ->get()
            ->map(function (CurriculumEntry $entry) use ($attempts, $acceptedCredits, $currentEntryIds): array {
                $entryAttempts = $attempts->filter(fn (array $attempt): bool => (int) $attempt['course_specification']?->id === (int) $entry->course_specification_id
                );
                $passed = $entryAttempts->contains(fn (array $attempt): bool => is_numeric($attempt['result']) && (float) $attempt['result'] <= 4.00
                );
                $credited = $acceptedCredits->has($entry->id);
                $inProgress = $currentEntryIds->contains((int) $entry->id);

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
                        'minimum_grade' => $requirement->minimum_grade,
                        'accepts_transfer_credit' => $requirement->accepts_transfer_credit,
                    ])->values()->all(),
                ];
            });

        return [
            'required' => $rows->values()->all(),
            'completed_units' => number_format($rows->sum(fn (array $row): float => (float) $row['credited_units']), 2, '.', ''),
            'deficiency_count' => $rows->reject(fn (array $row): bool => in_array($row['status'], ['Completed', 'Approved credit'], true))->count(),
        ];
    }
}
