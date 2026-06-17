<?php

namespace App\Actions\Scheduling;

use App\Actions\AcademicFoundation\CurriculumScopeReadinessService;
use App\Models\CurriculumSubject;
use App\Models\FacultySubjectEligibility;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleSolverSnapshotService
{
    private const SchemaVersion = 2;

    private const MaxSectionSeats = 30;

    public function __construct(
        private readonly TermSchedulingReadinessService $readinessService,
        private readonly CurriculumScopeReadinessService $curriculumReadinessService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function captureForRun(ScheduleGenerationRun $run): array
    {
        return DB::transaction(function () use ($run): array {
            /** @var ScheduleGenerationRun $lockedRun */
            $lockedRun = ScheduleGenerationRun::query()
                ->with('term')
                ->lockForUpdate()
                ->findOrFail($run->id);

            if ($lockedRun->solver_input_snapshot !== null) {
                return $lockedRun->solver_input_snapshot;
            }

            $readiness = $this->readinessService->evaluateTerm($lockedRun->term);

            if ($readiness['is_ready'] !== true) {
                throw ValidationException::withMessages([
                    'term_id' => 'Schedule solver snapshot cannot be captured until term readiness passes.',
                ]);
            }

            $snapshot = $this->buildSnapshot($lockedRun, $readiness);
            $encodedSnapshot = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $lockedRun->forceFill([
                'solver_input_snapshot' => $snapshot,
                'solver_input_hash' => hash('sha256', $encodedSnapshot),
                'solver_snapshot_captured_at' => now(),
            ])->save();

            $run->refresh();

            return $snapshot;
        });
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return array<string, mixed>
     */
    private function buildSnapshot(ScheduleGenerationRun $run, array $readiness): array
    {
        $sections = Section::query()
            ->with(['program', 'curriculum'])
            ->where('term_id', $run->term_id)
            ->orderBy('id')
            ->get();

        $sectionPayload = $this->sectionsPayload($sections);

        return [
            'schema_version' => self::SchemaVersion,
            'captured_at' => now()->toIso8601String(),
            'readiness' => $readiness,
            'run_metadata' => $this->runMetadata($run),
            'sections' => $sectionPayload,
            'curriculum_readiness_scopes' => $this->curriculumReadinessService->evidenceForSections($sections),
            'curriculum_subject_demand' => $this->curriculumSubjectDemand($sections),
            'faculty_eligibility' => $this->facultyEligibility((int) $run->term_id),
            'faculty_availability' => $this->facultyAvailability((int) $run->term_id),
            'rooms_catalog' => $this->roomsCatalog($sectionPayload),
            'existing_commitments' => $this->existingCommitments((int) $run->term_id),
            'policy_constraints' => $this->policyConstraints(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runMetadata(ScheduleGenerationRun $run): array
    {
        $term = $run->term;

        return [
            'run_id' => (int) $run->id,
            'term_id' => (int) $run->term_id,
            'term_name' => $term?->term_name,
            'term_type' => $term?->term_type,
            'timezone' => config('app.timezone'),
            'term_start_date' => $this->dateValue($term?->term_start_date),
            'term_end_date' => $this->dateValue($term?->term_end_date),
            'class_start_date' => $this->dateValue($term?->class_start_date),
            'class_end_date' => $this->dateValue($term?->class_end_date),
            'scheduling_starts_at' => $this->dateTimeValue($term?->scheduling_starts_at),
            'requested_by' => (int) $run->requested_by,
            'generated_at' => $this->dateTimeValue($run->generated_at),
        ];
    }

    /**
     * @param  Collection<int, Section>  $sections
     * @return list<array<string, mixed>>
     */
    private function sectionsPayload(Collection $sections): array
    {
        return $sections
            ->map(fn (Section $section): array => [
                'section_id' => (int) $section->id,
                'section_name' => (string) $section->name,
                'program_id' => (int) $section->program_id,
                'program_code' => $section->program?->code,
                'curriculum_id' => $section->curriculum_id !== null ? (int) $section->curriculum_id : null,
                'curriculum_version' => $section->curriculum?->version_name,
                'year_level' => $section->year_level,
                'curriculum_period' => $section->curriculum_period,
                'modality' => $section->modality,
                'max_seats' => (int) $section->max_seats,
                'enrolled_count' => (int) $section->enrolled_count,
                'available_seats' => max(0, (int) $section->max_seats - (int) $section->enrolled_count),
                'fixed_room' => filled($section->room) ? (string) $section->room : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Section>  $sections
     * @return list<array<string, mixed>>
     */
    private function curriculumSubjectDemand(Collection $sections): array
    {
        $curriculumIds = $sections
            ->pluck('curriculum_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($curriculumIds === []) {
            return [];
        }

        $curriculumSubjects = CurriculumSubject::query()
            ->with('subject')
            ->whereIn('curriculum_id', $curriculumIds)
            ->where(function ($query): void {
                $query->whereNull('delivery_rule_override')
                    ->orWhere('delivery_rule_override', '!=', CurriculumSubject::DeliveryOverrideExcludeFromAutoSchedule);
            })
            ->orderBy('curriculum_id')
            ->orderBy('year_level')
            ->orderBy('semester')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $sections
            ->flatMap(function (Section $section) use ($curriculumSubjects): array {
                return $curriculumSubjects
                    ->filter(fn (CurriculumSubject $curriculumSubject): bool => $this->matchesSectionDemand($curriculumSubject, $section))
                    ->map(fn (CurriculumSubject $curriculumSubject): array => [
                        'section_id' => (int) $section->id,
                        'curriculum_subject_id' => (int) $curriculumSubject->id,
                        'curriculum_id' => (int) $curriculumSubject->curriculum_id,
                        'year_level' => $curriculumSubject->year_level,
                        'curriculum_period' => $curriculumSubject->semester,
                        'subject_id' => (int) $curriculumSubject->subject_id,
                        'subject_code' => $curriculumSubject->subject?->code,
                        'subject_description' => $curriculumSubject->subject?->description,
                        'units' => $this->decimalValue($curriculumSubject->subject?->units),
                        'weekly_contact_hours' => $this->decimalValue($curriculumSubject->weekly_contact_hours),
                        'lec_hours' => $this->decimalValue($curriculumSubject->weekly_contact_hours),
                        'academic_subject_type' => $curriculumSubject->academic_subject_type,
                        'scheduling_group' => $curriculumSubject->scheduling_group,
                        'delivery_rule_override' => $curriculumSubject->delivery_rule_override,
                        'sort_order' => (int) $curriculumSubject->sort_order,
                    ])
                    ->values()
                    ->all();
            })
            ->values()
            ->all();
    }

    private function matchesSectionDemand(CurriculumSubject $curriculumSubject, Section $section): bool
    {
        return (int) $curriculumSubject->curriculum_id === (int) $section->curriculum_id
            && $curriculumSubject->year_level === $section->year_level
            && $curriculumSubject->semester === $section->curriculum_period;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function facultyEligibility(int $termId): array
    {
        return FacultySubjectEligibility::query()
            ->with(['faculty', 'subject'])
            ->where('status', FacultySubjectEligibility::StatusActive)
            ->where(function ($query) use ($termId): void {
                $query->whereNull('term_id')
                    ->orWhere('term_id', $termId);
            })
            ->orderBy('subject_id')
            ->orderBy('priority')
            ->orderBy('faculty_id')
            ->get()
            ->map(fn (FacultySubjectEligibility $eligibility): array => [
                'eligibility_id' => (int) $eligibility->id,
                'faculty_id' => (int) $eligibility->faculty_id,
                'faculty_name' => $eligibility->faculty?->name,
                'subject_id' => (int) $eligibility->subject_id,
                'subject_code' => $eligibility->subject?->code,
                'term_id' => $eligibility->term_id !== null ? (int) $eligibility->term_id : null,
                'scope' => $eligibility->term_id === null ? 'default' : 'term',
                'priority' => $eligibility->priority !== null ? (int) $eligibility->priority : null,
                'max_weekly_hours' => $this->decimalValue($eligibility->max_weekly_hours),
                'approved_by' => $eligibility->approved_by !== null ? (int) $eligibility->approved_by : null,
                'approved_at' => $this->dateTimeValue($eligibility->approved_at),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function facultyAvailability(int $termId): array
    {
        $submissions = DB::table('faculty_availability_submissions')
            ->where('term_id', $termId)
            ->whereIn('status', ['submitted', 'locked'])
            ->orderBy('faculty_id')
            ->orderByDesc('version')
            ->get()
            ->groupBy('faculty_id')
            ->map(fn (Collection $facultySubmissions): object => $facultySubmissions->first())
            ->values();

        if ($submissions->isEmpty()) {
            return [];
        }

        $submissionIds = $submissions->pluck('id')->all();
        $facultyNames = DB::table('users')
            ->whereIn('id', $submissions->pluck('faculty_id')->all())
            ->pluck('name', 'id');
        $windows = DB::table('faculty_availability_windows')
            ->whereIn('submission_id', $submissionIds)
            ->orderBy('submission_id')
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('submission_id');

        return $submissions
            ->map(fn (object $submission): array => [
                'submission_id' => (int) $submission->id,
                'faculty_id' => (int) $submission->faculty_id,
                'faculty_name' => $facultyNames[$submission->faculty_id] ?? null,
                'status' => (string) $submission->status,
                'version' => (int) $submission->version,
                'submitted_at' => $this->dateTimeValue($submission->submitted_at),
                'locked_at' => $this->dateTimeValue($submission->locked_at),
                'windows' => ($windows[$submission->id] ?? collect())
                    ->map(fn (object $window): array => [
                        'day_of_week' => (int) $window->day_of_week,
                        'starts_at' => $this->timeValue($window->starts_at),
                        'ends_at' => $this->timeValue($window->ends_at),
                        'notes' => $window->notes,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function roomsCatalog(array $sections): array
    {
        return collect($sections)
            ->filter(fn (array $section): bool => filled($section['fixed_room'] ?? null))
            ->groupBy('fixed_room')
            ->map(fn (Collection $roomSections, string $room): array => [
                'room_code' => $room,
                'source' => 'sections.room',
                'section_ids' => $roomSections->pluck('section_id')->values()->all(),
                'max_section_capacity' => (int) $roomSections->max('max_seats'),
                'modalities' => $roomSections->pluck('modality')->unique()->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function existingCommitments(int $termId): array
    {
        return DB::table('section_meetings')
            ->where('term_id', $termId)
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $meeting): array => [
                'section_meeting_id' => (int) $meeting->id,
                'section_id' => (int) $meeting->section_id,
                'subject_id' => (int) $meeting->subject_id,
                'faculty_id' => $meeting->faculty_id !== null ? (int) $meeting->faculty_id : null,
                'room' => $meeting->room,
                'day_of_week' => $meeting->day_of_week !== null ? (int) $meeting->day_of_week : null,
                'starts_at' => $this->timeValue($meeting->starts_at),
                'ends_at' => $this->timeValue($meeting->ends_at),
                'modality' => $meeting->modality,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function policyConstraints(): array
    {
        return [
            'timezone' => config('app.timezone'),
            'day_options' => SectionMeeting::dayOptions(),
            'allowed_modalities' => array_keys(SectionMeeting::modalityOptions()),
            'slot_granularity_minutes' => 30,
            'day_starts_at' => '07:00:00',
            'day_ends_at' => '21:00:00',
            'mandatory_faculty_assignment' => true,
            'max_section_seats' => self::MaxSectionSeats,
            'section_capacity_mode' => 'editable_bounded_max_30_not_below_enrolled_count',
            'room_catalog_mode' => 'sections.room fixed-room rescue catalog',
        ];
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return filled($value) ? (string) $value : null;
    }

    private function dateTimeValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return filled($value) ? (string) $value : null;
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s');
        }

        if (! filled($value)) {
            return null;
        }

        $time = (string) $value;

        return strlen($time) === 5 ? $time.':00' : $time;
    }

    private function decimalValue(mixed $value): ?string
    {
        return $value !== null && $value !== '' ? number_format((float) $value, 2, '.', '') : null;
    }
}
