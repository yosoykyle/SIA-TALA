<?php

namespace App\Actions\Scheduling;

use App\Models\CalendarEvent;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\ResourceUnavailability;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingCommitment;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ScheduleSolverSnapshotService
{
    private const ContractVersion = ScheduleGenerationRun::ContractVersion;

    private const DefaultDayStartsAt = '07:00:00';

    private const DefaultDayEndsAt = '21:00:00';

    public function __construct(private readonly ReadyTermPlanningProjection $readiness) {}

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

            $existingSnapshot = $this->arrayValue($lockedRun->getAttribute('input_snapshot'));

            if (($existingSnapshot['contract_version'] ?? null) === self::ContractVersion) {
                return $existingSnapshot;
            }

            $term = $lockedRun->term;

            if (! $term instanceof Term) {
                throw ValidationException::withMessages([
                    'term_id' => 'Solver run must reference a valid term.',
                ]);
            }

            $this->assertDemandReadiness($term);

            $hasCanonicalClasses = Section::query()
                ->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $term->id))
                ->exists();

            if ($hasCanonicalClasses) {
                $readiness = $this->readiness->forTerm($term);

                if (! $readiness['ready']) {
                    throw ValidationException::withMessages([
                        'term_planning' => collect($readiness['blockers'])->pluck('reason')->implode(' '),
                    ]);
                }
            }

            $demands = $this->readyDemandsForTerm($term);

            if ($demands->isEmpty()) {
                throw ValidationException::withMessages([
                    'scheduling_demands' => 'At least one READY_FOR_REVIEW Scheduling Demand row is required before solver dispatch.',
                ]);
            }

            $snapshot = $this->buildSnapshot($lockedRun, $term, $demands);
            $encodedSnapshot = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $lockedRun->forceFill([
                'input_snapshot' => $snapshot,
                'input_hash' => hash('sha256', $encodedSnapshot),
                'contract_version' => self::ContractVersion,
                'quality_policy' => ScheduleGenerationRun::QualityPolicyLexicographic,
            ])->save();

            $run->refresh();

            return $snapshot;
        }, 3);
    }

    /**
     * @param  array{faculty_user_id:int,room_id?:int|null,day_of_week:int,starts_at:string,ends_at:string}  $assignment
     * @return array<string, mixed>
     */
    public function captureRepairForRun(
        ScheduleGenerationRun $run,
        ScheduleGenerationRun $sourceRun,
        CandidateScheduleRow $requestedRow,
        array $assignment,
        User $actor,
        string $reason,
        string $authority,
    ): array {
        $assignment['starts_at'] = $this->hourMinute($assignment['starts_at']);
        $assignment['ends_at'] = $this->hourMinute($assignment['ends_at']);
        $validated = Validator::make($assignment, [
            'faculty_user_id' => ['required', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
        ])->validate();
        $reason = trim($reason);
        $authority = trim($authority);

        if ($reason === '' || $authority === '') {
            throw ValidationException::withMessages([
                'repair' => 'Repair requires an attributable reason and authority reference.',
            ]);
        }

        return DB::transaction(function () use ($run, $sourceRun, $requestedRow, $validated, $actor, $reason, $authority): array {
            $lockedRun = ScheduleGenerationRun::query()->lockForUpdate()->findOrFail($run->id);
            $lockedSource = ScheduleGenerationRun::query()->lockForUpdate()->findOrFail($sourceRun->id);
            $sourceSnapshot = $this->arrayValue($lockedSource->input_snapshot);

            if ($lockedSource->status !== ScheduleGenerationRun::StatusUnderReview
                || ($sourceSnapshot['contract_version'] ?? null) !== self::ContractVersion) {
                throw ValidationException::withMessages([
                    'source_candidate' => 'Repair requires the current immutable timetable-v2 candidate.',
                ]);
            }

            $sourceRows = CandidateScheduleRow::query()
                ->where('schedule_run_id', $lockedSource->id)
                ->orderBy('scheduling_demand_id')
                ->orderBy('meeting_sequence')
                ->lockForUpdate()
                ->get();
            $target = $sourceRows->firstWhere('id', $requestedRow->id);

            if (! $target instanceof CandidateScheduleRow || $sourceRows->isEmpty()) {
                throw ValidationException::withMessages([
                    'source_candidate' => 'The requested repair source is stale or incomplete.',
                ]);
            }

            $demands = collect($this->listValue($sourceSnapshot['scheduling_demands'] ?? null))
                ->filter(fn (mixed $demand): bool => is_array($demand))
                ->keyBy(fn (array $demand): int => (int) $demand['scheduling_demand_id']);
            $baseline = $sourceRows->map(function (CandidateScheduleRow $row) use ($demands): array {
                $demand = $demands->get((int) $row->scheduling_demand_id);

                if (! is_array($demand)) {
                    throw ValidationException::withMessages([
                        'source_candidate' => 'The repair source no longer matches its immutable demand snapshot.',
                    ]);
                }

                return [
                    'candidate_row_id' => (int) $row->id,
                    'scheduling_demand_id' => (int) $row->scheduling_demand_id,
                    'meeting_sequence' => (int) $row->meeting_sequence,
                    'term_offering_id' => (int) $demand['term_offering_id'],
                    'section_id' => (int) $demand['section_id'],
                    'section_delivery_group_id' => (int) $demand['section_delivery_group_id'],
                    'cohort_or_student_group_id' => (int) $demand['cohort_or_student_group_id'],
                    'cohort_or_student_group_ids' => $demand['cohort_or_student_group_ids'] ?? [(int) $demand['cohort_or_student_group_id']],
                    'subject_id' => $demand['course_id'] ?? null,
                    'course_component_id' => $demand['course_component_id'] ?? null,
                    'faculty_id' => (int) $row->faculty_user_id,
                    'faculty_user_id' => (int) $row->faculty_user_id,
                    'room_id' => $row->room_id !== null ? (int) $row->room_id : null,
                    'day' => (int) $row->day_of_week,
                    'day_of_week' => (int) $row->day_of_week,
                    'start_time' => $this->timeString($row->starts_at),
                    'starts_at' => $this->timeString($row->starts_at),
                    'end_time' => $this->timeString($row->ends_at),
                    'ends_at' => $this->timeString($row->ends_at),
                    'time_block_reference' => $row->time_block_key,
                    'time_block_key' => $row->time_block_key,
                ];
            })->values()->all();
            $requestedDemand = $demands->get((int) $target->scheduling_demand_id);

            if (! is_array($requestedDemand)) {
                throw ValidationException::withMessages(['source_candidate' => 'The requested repair demand is unavailable.']);
            }

            $snapshot = [
                ...$sourceSnapshot,
                'captured_at' => now()->toIso8601String(),
                'run_metadata' => [
                    ...$this->arrayValue($sourceSnapshot['run_metadata'] ?? null),
                    'solver_run_id' => (int) $lockedRun->id,
                    'requested_by' => (int) $actor->id,
                ],
                'operation' => [
                    'kind' => 'repair',
                    'source_candidate' => [
                        'run_id' => (int) $lockedSource->id,
                        'candidate_version' => (int) $lockedSource->candidate_version,
                        'assignments' => $baseline,
                    ],
                    'requested_assignment' => [
                        'scheduling_demand_id' => (int) $target->scheduling_demand_id,
                        'meeting_sequence' => (int) $target->meeting_sequence,
                        'faculty_user_id' => (int) $validated['faculty_user_id'],
                        'room_id' => $validated['room_id'] ?? null,
                        'day_of_week' => (int) $validated['day_of_week'],
                        'starts_at' => $validated['starts_at'].':00',
                        'ends_at' => $validated['ends_at'].':00',
                    ],
                    'reason' => $reason,
                    'authority_reference' => $authority,
                ],
            ];
            $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $lockedRun->forceFill([
                'input_snapshot' => $snapshot,
                'input_hash' => hash('sha256', $encoded),
                'contract_version' => self::ContractVersion,
                'quality_policy' => ScheduleGenerationRun::QualityPolicyLexicographic,
                'candidate_version' => ((int) $lockedSource->candidate_version) + 1,
                'candidate_state' => 'RepairQueued',
                'candidate_review_reason' => $reason,
            ])->save();
            $run->refresh();

            return $snapshot;
        }, 3);
    }

    /**
     * Build a read-only validation context from current authoritative records.
     *
     * @return array<string, mixed>
     */
    public function currentForRun(ScheduleGenerationRun $run, array $excludedDemandIds = []): array
    {
        $run->loadMissing('term');
        $term = $run->term;

        if (! $term instanceof Term) {
            throw ValidationException::withMessages([
                'term_id' => 'Schedule revalidation requires a valid term.',
            ]);
        }

        $demandIds = $this->demandIdsForRun($run);
        $excludedDemandIds = collect($excludedDemandIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $demands = $this->demandsForTerm($term, $demandIds);
        $availableDemandIds = $demands->modelKeys();

        if (array_diff($excludedDemandIds, $availableDemandIds) !== []) {
            throw ValidationException::withMessages([
                'scheduling_demands' => 'Only demands captured by the selected run may be excluded from live validation.',
            ]);
        }

        $cohortIdsByDeliveryGroup = $this->cohortIdsByDeliveryGroup($demands);
        $demands = $demands->reject(
            fn (SchedulingDemand $demand): bool => in_array((int) $demand->id, $excludedDemandIds, true),
        );

        if ($demands->isEmpty() && count($excludedDemandIds) !== count($availableDemandIds)) {
            throw ValidationException::withMessages([
                'scheduling_demands' => 'Schedule revalidation requires current Scheduling Demand rows.',
            ]);
        }

        return $this->buildSnapshot(
            $run,
            $term,
            $demands,
            useCurrentSources: true,
            cohortIdsByDeliveryGroup: $cohortIdsByDeliveryGroup,
        );
    }

    private function assertDemandReadiness(Term $term): void
    {
        $blockingCount = SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
            ->where('validation_state', '!=', SchedulingDemand::ValidationReadyForReview)
            ->count();

        if ($blockingCount > 0) {
            throw ValidationException::withMessages([
                'scheduling_demands' => 'All Scheduling Demand rows for the selected term must be READY_FOR_REVIEW before solver dispatch.',
            ]);
        }
    }

    /**
     * @return EloquentCollection<int, SchedulingDemand>
     */
    private function readyDemandsForTerm(Term $term): EloquentCollection
    {
        return $this->demandsForTerm($term)
            ->where('validation_state', SchedulingDemand::ValidationReadyForReview);
    }

    /**
     * @return EloquentCollection<int, SchedulingDemand>
     */
    private function demandsForTerm(Term $term, ?array $demandIds = null): EloquentCollection
    {
        return SchedulingDemand::query()
            ->with([
                'courseComponent.courseSpecification.course',
                'fixedFaculty',
                'fixedRoom',
                'sectionDeliveryGroup.section.cohorts',
                'termOffering.curriculumEntry.curriculumVersion',
                'termOffering.curriculumEntry.courseSpecification.course',
            ])
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
            ->when($demandIds !== null, fn ($query) => $query->whereKey($demandIds))
            ->orderBy('term_offering_id')
            ->orderBy('section_delivery_group_id')
            ->orderBy('course_component_id')
            ->get();
    }

    /**
     * @return list<int>|null
     */
    private function demandIdsForRun(ScheduleGenerationRun $run): ?array
    {
        $snapshot = $this->arrayValue($run->getAttribute('input_snapshot'));
        $capturedIds = collect($snapshot['scheduling_demands'] ?? [])
            ->filter(fn (mixed $demand): bool => is_array($demand))
            ->pluck('scheduling_demand_id')
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($capturedIds !== []) {
            return $capturedIds;
        }

        $persistedIds = $run->candidateRows()
            ->pluck('scheduling_demand_id')
            ->merge($run->sectionMeetings()->pluck('scheduling_demand_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $persistedIds !== [] ? $persistedIds : null;
    }

    /**
     * @param  EloquentCollection<int, SchedulingDemand>  $demands
     * @return array<string, mixed>
     */
    private function buildSnapshot(
        ScheduleGenerationRun $run,
        Term $term,
        EloquentCollection $demands,
        bool $useCurrentSources = false,
        ?array $cohortIdsByDeliveryGroup = null,
    ): array {
        $timeSlots = $this->timeSlots($term);
        $calendarPackage = TermCalendarPackage::query()
            ->with(['windows', 'teachingGridRows', 'datedExceptions'])
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->first();
        $demandPayload = $this->schedulingDemandsPayload(
            $demands,
            $term,
            $useCurrentSources,
            $cohortIdsByDeliveryGroup ?? $this->cohortIdsByDeliveryGroup($demands),
        );

        return [
            'contract_version' => self::ContractVersion,
            'captured_at' => now()->toIso8601String(),
            'run_metadata' => [
                'solver_run_id' => (int) $run->id,
                'term_id' => (int) $term->id,
                'requested_by' => $run->requested_by !== null ? (int) $run->requested_by : null,
                'timezone' => config('app.timezone'),
            ],
            'term' => [
                'term_id' => (int) $term->id,
                'academic_year_id' => (int) $term->academic_year_id,
                'type' => $term->type,
                'label' => $term->label,
                'starts_on' => $this->dateString($term->getAttribute('starts_on')),
                'ends_on' => $this->dateString($term->getAttribute('ends_on')),
                'scheduling_slot_minutes' => (int) $term->scheduling_slot_minutes,
                'scheduling_days' => $this->schedulingDays($term),
                'scheduling_day_starts_at' => $this->timeString($term->scheduling_day_starts_at) ?? self::DefaultDayStartsAt,
                'scheduling_day_ends_at' => $this->timeString($term->scheduling_day_ends_at) ?? self::DefaultDayEndsAt,
                'default_max_units' => $term->default_max_units,
            ],
            'term_calendar_package' => $calendarPackage instanceof TermCalendarPackage ? [
                'term_calendar_package_id' => (int) $calendarPackage->id,
                'version' => (int) $calendarPackage->version,
                'authority_reference' => $calendarPackage->authority_reference,
                'authority_date' => $this->dateString($calendarPackage->authority_date),
                'administrative_starts_on' => $this->dateString($calendarPackage->administrative_starts_on),
                'administrative_ends_on' => $this->dateString($calendarPackage->administrative_ends_on),
                'classes_start_on' => $this->dateString($calendarPackage->classes_start_on),
                'classes_end_on' => $this->dateString($calendarPackage->classes_end_on),
                'special_term_schedule_basis' => $calendarPackage->special_term_schedule_basis,
                'window_ids' => $calendarPackage->windows->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                'teaching_grid_row_ids' => $calendarPackage->teachingGridRows->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                'dated_exception_ids' => $calendarPackage->datedExceptions->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            ] : null,
            'time_slots' => $timeSlots,
            'subjects' => $this->subjectsPayload($demandPayload),
            'scheduling_demands' => $demandPayload,
            'sections' => $this->sectionsPayload($demandPayload),
            'section_delivery_groups' => $this->sectionDeliveryGroupsPayload($demandPayload),
            'rooms' => $this->roomsPayload(),
            'faculty' => $this->facultyPayload($demandPayload),
            'faculty_qualifications' => $this->facultyQualificationsPayload($demandPayload),
            'faculty_availability' => $this->facultyAvailabilityPayload($term),
            'term_offerings' => $this->termOfferingsPayload($demandPayload),
            'student_cohort_groups' => $this->studentCohortGroupsPayload($demandPayload),
            'calendar_blocks' => $this->calendarBlocksPayload($term),
            'dated_exceptions' => $this->datedExceptionsPayload($term),
            'hard_constraints' => $this->hardConstraints(),
            'soft_constraints' => $this->softConstraints(),
            'constraint_profile' => [
                'key' => 'lexicographic_v1',
                'version' => 1,
                'hard_constraints' => $this->hardConstraints(),
                'objective_hierarchy' => $this->softConstraints(),
            ],
            'fixed_assignments' => $this->fixedAssignmentsPayload($demandPayload),
            'optimization_settings' => [
                'slot_granularity_minutes' => (int) $term->scheduling_slot_minutes,
                'candidate_schedule_mode' => 'provisional_only',
                'publish_after_solver' => false,
            ],
        ];
    }

    /**
     * @return list<array{time_slot_id:int,time_block_key:string,day_of_week:int,starts_at:string,ends_at:string,duration_minutes:int}>
     */
    private function timeSlots(Term $term): array
    {
        $calendarPackage = TermCalendarPackage::query()
            ->with('teachingGridRows')
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->first();
        $slotMinutes = $calendarPackage instanceof TermCalendarPackage ? 30 : max(1, (int) $term->scheduling_slot_minutes);
        if ($calendarPackage instanceof TermCalendarPackage && $calendarPackage->teachingGridRows->isNotEmpty()) {
            $slots = [];
            $id = 1;

            foreach ($calendarPackage->teachingGridRows->sortBy(
                fn ($gridRow): string => sprintf('%02d|%s', (int) $gridRow->day_of_week, (string) $gridRow->starts_at),
            ) as $gridRow) {
                $dayStart = $this->minutes($this->timeString($gridRow->starts_at) ?? self::DefaultDayStartsAt);
                $dayEnd = $this->minutes($this->timeString($gridRow->ends_at) ?? self::DefaultDayEndsAt);

                for ($startsAt = $dayStart; $startsAt + $slotMinutes <= $dayEnd; $startsAt += $slotMinutes) {
                    $slots[] = [
                        'time_slot_id' => $id++,
                        'time_block_key' => 'D'.$gridRow->day_of_week.'-'.$this->compactTime($startsAt),
                        'day_of_week' => (int) $gridRow->day_of_week,
                        'starts_at' => $this->time($startsAt),
                        'ends_at' => $this->time($startsAt + $slotMinutes),
                        'duration_minutes' => $slotMinutes,
                    ];
                }
            }

            return $slots;
        }

        $dayStart = $this->minutes($this->timeString($term->scheduling_day_starts_at) ?? self::DefaultDayStartsAt);
        $dayEnd = $this->minutes($this->timeString($term->scheduling_day_ends_at) ?? self::DefaultDayEndsAt);
        $slots = [];
        $id = 1;

        foreach ($this->schedulingDays($term) as $day) {
            for ($startsAt = $dayStart; $startsAt + $slotMinutes <= $dayEnd; $startsAt += $slotMinutes) {
                $endsAt = $startsAt + $slotMinutes;

                $slots[] = [
                    'time_slot_id' => $id++,
                    'time_block_key' => 'D'.$day.'-'.$this->compactTime($startsAt),
                    'day_of_week' => $day,
                    'starts_at' => $this->time($startsAt),
                    'ends_at' => $this->time($endsAt),
                    'duration_minutes' => $slotMinutes,
                ];
            }
        }

        return $slots;
    }

    /**
     * @param  EloquentCollection<int, SchedulingDemand>  $demands
     * @return list<array<string, mixed>>
     */
    private function schedulingDemandsPayload(
        EloquentCollection $demands,
        Term $term,
        bool $useCurrentSources,
        array $cohortIdsByDeliveryGroup,
    ): array {
        $commitmentsBySection = SchedulingCommitment::query()
            ->where('term_id', $term->id)
            ->whereNotNull('section_id')
            ->get()
            ->groupBy(fn (SchedulingCommitment $commitment): int => (int) $commitment->section_id);

        return $demands
            ->map(function ($demand) use ($term, $useCurrentSources, $cohortIdsByDeliveryGroup, $commitmentsBySection): array {
                $group = $demand->getRelationValue('sectionDeliveryGroup');
                $group = $group instanceof SectionDeliveryGroup ? $group : null;
                $section = $group?->getRelationValue('section');
                $section = $section instanceof Section ? $section : null;
                $component = $demand->getRelationValue('courseComponent');
                $component = $component instanceof CourseComponent ? $component : null;
                $specification = $component?->getRelationValue('courseSpecification');
                $specification = $specification instanceof CourseSpecification ? $specification : null;
                $course = $specification?->getRelationValue('course');
                $course = $course instanceof Course ? $course : null;
                $offering = $demand->getRelationValue('termOffering');
                $offering = $offering instanceof TermOffering ? $offering : null;
                $source = $useCurrentSources
                    ? $this->currentSourceSnapshot($term, $demand, $offering, $group, $section, $component, $specification, $course)
                    : $this->arrayValue($demand->getAttribute('source_snapshot'));
                $facultyOptions = collect($source['faculty_load_options'] ?? [])
                    ->filter(fn (mixed $option): bool => is_array($option) && isset($option['faculty_user_id']))
                    ->values()
                    ->all();
                $modality = $useCurrentSources
                    ? (filled($group?->modality) ? (string) $group->modality : (string) $offering?->modality)
                    : (string) $demand->modality;
                $cohortIds = $cohortIdsByDeliveryGroup[(int) $demand->section_delivery_group_id];
                $cohortExpectedCounts = $section instanceof Section && $section->relationLoaded('cohorts') && $section->cohorts->isNotEmpty()
                    ? $section->cohorts->mapWithKeys(fn ($cohort): array => [
                        (int) $cohort->id => (int) $cohort->pivot->getAttribute('expected_count'),
                    ])->all()
                    : [(int) $cohortIds[0] => (int) ($source['expected_count'] ?? $group->expected_count ?? 0)];
                $expectedCount = array_sum($cohortExpectedCounts);
                $meetingPattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);
                $sectionCommitments = $commitmentsBySection->get((int) $section->id, collect());
                $sectionCommitment = $sectionCommitments->count() === 1 ? $sectionCommitments->first() : null;

                return [
                    'scheduling_demand_id' => (int) $demand->id,
                    'demand_key' => $demand->demand_key,
                    'term_offering_id' => (int) $demand->term_offering_id,
                    'section_id' => $section?->id !== null ? (int) $section->id : (int) ($source['section_id'] ?? 0),
                    'section_delivery_group_id' => (int) $demand->section_delivery_group_id,
                    'cohort_or_student_group_id' => (int) $cohortIds[0],
                    'cohort_or_student_group_ids' => array_map('intval', $cohortIds),
                    'cohort_expected_counts' => $cohortExpectedCounts,
                    'course_id' => $course?->id !== null ? (int) $course->id : $this->nullableInt($source['course_id'] ?? null),
                    'course_code' => $course->code ?? ($source['course_code'] ?? null),
                    'course_component_id' => (int) $demand->course_component_id,
                    'component_type' => $component->component_type ?? ($source['component_type'] ?? null),
                    'required_duration_minutes' => $useCurrentSources && $meetingPattern !== null
                        ? $meetingPattern['duration_minutes']
                        : (int) $demand->required_duration_minutes,
                    'meeting_count' => $useCurrentSources && $meetingPattern !== null
                        ? $meetingPattern['count']
                        : (int) $demand->meeting_count,
                    'meeting_pattern' => $component->meeting_pattern,
                    'modality' => $modality,
                    'validation_state' => $demand->validation_state,
                    'expected_count' => $expectedCount,
                    'section_capacity' => (int) ($source['section_capacity'] ?? $section->capacity ?? 0),
                    'room_type_requirement' => $source['room_type_requirement'] ?? null,
                    'required_room_feature_keys' => collect($component->required_room_feature_keys ?? [])
                        ->map(fn (mixed $key): string => strtoupper(trim((string) $key)))
                        ->filter()
                        ->unique()
                        ->sort()
                        ->values()
                        ->all(),
                    'load_units' => $specification?->credit_units,
                    'room_required' => $modality === TermOffering::ModalityFaceToFace,
                    'same_faculty_required' => (bool) ($source['same_faculty_required'] ?? false),
                    'requires_consecutive_block' => (bool) ($component->requires_consecutive_block ?? false),
                    'eligible_faculty_user_ids' => collect($facultyOptions)
                        ->pluck('faculty_user_id')
                        ->map(fn (mixed $id): int => (int) $id)
                        ->values()
                        ->all(),
                    'faculty_load_options' => $facultyOptions,
                    'scheduling_commitment_id' => $sectionCommitment instanceof SchedulingCommitment ? $sectionCommitment->id : null,
                    'fixed_faculty_user_id' => $sectionCommitment instanceof SchedulingCommitment && $sectionCommitment->faculty_user_id !== null
                        ? (int) $sectionCommitment->faculty_user_id
                        : ($demand->fixed_faculty_user_id !== null ? (int) $demand->fixed_faculty_user_id : null),
                    'fixed_room_id' => $sectionCommitment instanceof SchedulingCommitment && $sectionCommitment->room_id !== null
                        ? (int) $sectionCommitment->room_id
                        : ($demand->fixed_room_id !== null ? (int) $demand->fixed_room_id : null),
                    'fixed_day_of_week' => $sectionCommitment instanceof SchedulingCommitment && $sectionCommitment->day_of_week !== null
                        ? (int) $sectionCommitment->day_of_week
                        : ($demand->fixed_day_of_week !== null ? (int) $demand->fixed_day_of_week : null),
                    'fixed_start_time' => $this->timeString(
                        $sectionCommitment instanceof SchedulingCommitment ? $sectionCommitment->starts_at : $demand->fixed_start_time,
                    ),
                    'source_snapshot' => $source,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function currentSourceSnapshot(
        Term $term,
        SchedulingDemand $demand,
        TermOffering $offering,
        SectionDeliveryGroup $group,
        Section $section,
        CourseComponent $component,
        CourseSpecification $specification,
        Course $course,
    ): array {
        $facultyLoadOptions = $this->currentFacultyLoadOptions($term, $course);

        return [
            'term_id' => (int) $term->id,
            'term_offering_id' => (int) $demand->term_offering_id,
            'section_id' => (int) $section->id,
            'section_delivery_group_id' => (int) $demand->section_delivery_group_id,
            'curriculum_entry_id' => (int) $offering->curriculum_entry_id,
            'course_specification_id' => (int) $specification->id,
            'course_id' => (int) $course->id,
            'course_code' => $course->code,
            'course_component_id' => (int) $demand->course_component_id,
            'component_type' => $component->component_type,
            'weekly_contact_hours' => $component->weekly_contact_hours,
            'meeting_pattern' => $component->meeting_pattern,
            'expected_count' => (int) $group->expected_count,
            'section_capacity' => (int) $section->capacity,
            'offering_modality' => $offering->modality,
            'demand_modality' => filled($group->modality) ? (string) $group->modality : (string) $offering->modality,
            'room_type_requirement' => $offering->room_type_override ?: $component->room_type_default,
            'same_faculty_required' => (bool) ($offering->same_faculty_override ?? $specification->same_faculty_default ?? $component->same_faculty),
            'eligible_faculty_count' => count($facultyLoadOptions),
            'faculty_load_options' => $facultyLoadOptions,
        ];
    }

    /**
     * @return list<array{faculty_user_id:int,qualification_id:int,term_load_override_id:int|null,max_allowed_units:string|null}>
     */
    private function currentFacultyLoadOptions(Term $term, Course $course): array
    {
        $qualifications = FacultyQualification::query()
            ->whereBelongsTo($course)
            ->where('is_active', true)
            ->orderBy('faculty_user_id')
            ->get();
        $overrides = FacultyTermLoadOverride::query()
            ->whereBelongsTo($term)
            ->where('is_active', true)
            ->whereIn('faculty_user_id', $qualifications->pluck('faculty_user_id')->all())
            ->get()
            ->keyBy('faculty_user_id');

        return $qualifications
            ->map(function (FacultyQualification $qualification) use ($term, $overrides): array {
                $override = $overrides->get($qualification->faculty_user_id);
                $maxAllowedUnits = $override instanceof FacultyTermLoadOverride
                    ? $override->allowedLoadUnits()
                    : ($term->default_max_units !== null ? (float) $term->default_max_units : null);

                return [
                    'faculty_user_id' => (int) $qualification->faculty_user_id,
                    'qualification_id' => (int) $qualification->id,
                    'term_load_override_id' => $override instanceof FacultyTermLoadOverride ? (int) $override->id : null,
                    'max_allowed_units' => $maxAllowedUnits !== null
                        ? number_format($maxAllowedUnits, 2, '.', '')
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $demands
     * @return list<array<string, mixed>>
     */
    private function subjectsPayload(array $demands): array
    {
        return collect($demands)
            ->filter(fn (array $demand): bool => ($demand['course_id'] ?? null) !== null)
            ->unique('course_id')
            ->map(fn (array $demand): array => [
                'subject_id' => (int) $demand['course_id'],
                'course_id' => (int) $demand['course_id'],
                'course_code' => $demand['course_code'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $demands
     * @return list<array<string, mixed>>
     */
    private function sectionsPayload(array $demands): array
    {
        return collect($demands)
            ->unique('section_id')
            ->map(fn (array $demand): array => [
                'section_id' => (int) $demand['section_id'],
                'section_capacity' => (int) $demand['section_capacity'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $demands
     * @return list<array<string, mixed>>
     */
    private function sectionDeliveryGroupsPayload(array $demands): array
    {
        return collect($demands)
            ->unique('section_delivery_group_id')
            ->map(fn (array $demand): array => [
                'section_delivery_group_id' => (int) $demand['section_delivery_group_id'],
                'section_id' => (int) $demand['section_id'],
                'expected_count' => (int) $demand['expected_count'],
                'modality' => $demand['modality'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function roomsPayload(): array
    {
        return Room::query()
            ->with('features')
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (Room $room): array => [
                'room_id' => (int) $room->id,
                'code' => $room->code,
                'name' => $room->name,
                'room_type' => $room->room_type,
                'capacity' => (int) $room->capacity,
                'feature_keys' => $room->features
                    ->pluck('feature_key')
                    ->map(fn (mixed $key): string => strtoupper(trim((string) $key)))
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $demands
     * @return list<array<string, mixed>>
     */
    private function facultyPayload(array $demands): array
    {
        return collect($demands)
            ->flatMap(fn (array $demand): array => $demand['faculty_load_options'] ?? [])
            ->filter(fn (mixed $option): bool => is_array($option) && isset($option['faculty_user_id']))
            ->groupBy(fn (array $option): int => (int) $option['faculty_user_id'])
            ->map(fn (Collection $options, int $facultyId): array => [
                'faculty_id' => $facultyId,
                'max_allowed_units' => $options->pluck('max_allowed_units')->filter()->first(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $demands
     * @return list<array<string, mixed>>
     */
    private function facultyQualificationsPayload(array $demands): array
    {
        return collect($demands)
            ->flatMap(fn (array $demand): array => collect($demand['faculty_load_options'] ?? [])
                ->filter(fn (mixed $option): bool => is_array($option) && isset($option['faculty_user_id']))
                ->map(fn (array $option): array => [
                    'scheduling_demand_id' => (int) $demand['scheduling_demand_id'],
                    'course_id' => $demand['course_id'] !== null ? (int) $demand['course_id'] : null,
                    'faculty_user_id' => (int) $option['faculty_user_id'],
                    'qualification_id' => $this->nullableInt($option['qualification_id'] ?? null),
                    'term_load_override_id' => $this->nullableInt($option['term_load_override_id'] ?? null),
                    'max_allowed_units' => $option['max_allowed_units'] ?? null,
                ])
                ->all())
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $demands
     * @return list<array<string, mixed>>
     */
    private function termOfferingsPayload(array $demands): array
    {
        return collect($demands)
            ->unique('term_offering_id')
            ->map(fn (array $demand): array => [
                'term_offering_id' => (int) $demand['term_offering_id'],
                'modality' => $demand['modality'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $demands
     * @return list<array<string, mixed>>
     */
    private function studentCohortGroupsPayload(array $demands): array
    {
        return collect($demands)
            ->unique('section_delivery_group_id')
            ->flatMap(fn (array $demand): array => collect($demand['cohort_or_student_group_ids'])
                ->map(fn (mixed $cohortId): array => [
                    'cohort_or_student_group_id' => (int) $cohortId,
                    'section_delivery_group_id' => (int) $demand['section_delivery_group_id'],
                    'expected_count' => (int) ($demand['cohort_expected_counts'][(int) $cohortId] ?? 0),
                ])->all())
            ->values()
            ->all();
    }

    /**
     * Build one stable term-scoped cohort identity for every course-specific
     * delivery group that represents the same program, year level, and cohort code.
     *
     * @param  EloquentCollection<int, SchedulingDemand>  $demands
     * @return array<int, list<int>>
     */
    private function cohortIdsByDeliveryGroup(EloquentCollection $demands): array
    {
        $canonical = [];

        foreach ($demands as $demand) {
            $group = $demand->getRelationValue('sectionDeliveryGroup');
            $section = $group instanceof SectionDeliveryGroup ? $group->getRelationValue('section') : null;

            if (! $section instanceof Section || ! $section->relationLoaded('cohorts') || $section->cohorts->isEmpty()) {
                continue;
            }

            $canonical[(int) $demand->section_delivery_group_id] = $section->cohorts
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
        }

        if ($canonical !== []) {
            if (count($canonical) !== $demands->pluck('section_delivery_group_id')->unique()->count()) {
                throw ValidationException::withMessages([
                    'student_cohort_groups' => 'Every canonical Class Offering requires at least one confirmed Term Cohort.',
                ]);
            }

            return $canonical;
        }

        $identities = $demands->map(function (SchedulingDemand $demand): array {
            $group = $demand->getRelationValue('sectionDeliveryGroup');
            $offering = $demand->getRelationValue('termOffering');
            $entry = $offering instanceof TermOffering
                ? $offering->getRelationValue('curriculumEntry')
                : null;
            $curriculum = $entry instanceof CurriculumEntry
                ? $entry->getRelationValue('curriculumVersion')
                : null;
            $groupName = $group instanceof SectionDeliveryGroup ? trim((string) $group->name) : '';
            $yearLevel = $entry instanceof CurriculumEntry ? trim((string) $entry->year_level) : '';
            $programId = $curriculum instanceof CurriculumVersion ? (int) $curriculum->program_id : 0;

            if ($groupName === '' || $yearLevel === '' || $programId <= 0) {
                throw ValidationException::withMessages([
                    'student_cohort_groups' => 'Every scheduling demand requires an exact delivery-group cohort code, curriculum year level, and program before solver dispatch.',
                ]);
            }

            return [
                'section_delivery_group_id' => (int) $demand->section_delivery_group_id,
                'identity' => $programId.'|'.$yearLevel.'|'.$groupName,
            ];
        });

        return $identities
            ->groupBy('identity')
            ->reduce(function (array $cohortIds, Collection $rows): array {
                $cohortId = (int) $rows->min('section_delivery_group_id');

                foreach ($rows as $row) {
                    $cohortIds[(int) $row['section_delivery_group_id']] = [$cohortId];
                }

                return $cohortIds;
            }, []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function calendarBlocksPayload(Term $term): array
    {
        $legacy = CalendarEvent::query()
            ->whereBelongsTo($term)
            ->recurringSchedulingBlocks()
            ->where('state', CalendarEvent::StateActive)
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('scope_type')
            ->orderBy('id')
            ->get()
            ->map(fn ($event): array => [
                'calendar_event_id' => (int) $event->id,
                'event_type' => $event->event_type,
                'scope_type' => $event->scope_type,
                'room_id' => $event->room_id !== null ? (int) $event->room_id : null,
                'faculty_user_id' => $event->faculty_user_id !== null ? (int) $event->faculty_user_id : null,
                'authority' => $event->authority,
                'day_of_week' => (int) $event->day_of_week,
                'starts_at' => $this->timeString($event->starts_at),
                'ends_at' => $this->timeString($event->ends_at),
            ])
            ->values()
            ->all();
        $resourceBlocks = ResourceUnavailability::query()
            ->where('term_id', $term->id)
            ->whereNull('effective_on')
            ->whereNotNull('day_of_week')
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ResourceUnavailability $record): array => [
                'resource_unavailability_id' => (int) $record->id,
                'event_type' => 'ResourceUnavailability',
                'scope_type' => $record->room_id !== null ? 'Room' : 'Faculty',
                'room_id' => $record->room_id !== null ? (int) $record->room_id : null,
                'faculty_user_id' => $record->faculty_user_id !== null ? (int) $record->faculty_user_id : null,
                'authority' => $record->authority_reference,
                'day_of_week' => $record->day_of_week,
                'starts_at' => $this->timeString($record->starts_at),
                'ends_at' => $this->timeString($record->ends_at),
            ])->all();
        $package = TermCalendarPackage::query()
            ->with('teachingGridRows')
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->first();
        $packageBlocks = [];

        if ($package instanceof TermCalendarPackage) {
            foreach ($package->teachingGridRows as $gridRow) {
                foreach ($this->listValue($gridRow->breaks) as $break) {
                    if (! is_array($break) || blank($break['starts_at'] ?? null) || blank($break['ends_at'] ?? null)) {
                        continue;
                    }

                    $packageBlocks[] = [
                        'term_teaching_grid_row_id' => (int) $gridRow->id,
                        'event_type' => 'TeachingGridBreak',
                        'scope_type' => 'Institution',
                        'room_id' => null,
                        'faculty_user_id' => null,
                        'authority' => $package->authority_reference,
                        'day_of_week' => (int) $gridRow->day_of_week,
                        'starts_at' => $this->timeString($break['starts_at']),
                        'ends_at' => $this->timeString($break['ends_at']),
                    ];
                }
            }

        }

        return [...$packageBlocks, ...$legacy, ...$resourceBlocks];
    }

    /** @return list<array<string, mixed>> */
    private function datedExceptionsPayload(Term $term): array
    {
        $package = TermCalendarPackage::query()
            ->with('datedExceptions')
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->first();
        $calendarExceptions = $package instanceof TermCalendarPackage
            ? $package->datedExceptions->map(fn ($exception): array => [
                'term_dated_exception_id' => (int) $exception->id,
                'source_type' => 'TermDatedException',
                'starts_on' => $this->dateString($exception->starts_on),
                'ends_on' => $this->dateString($exception->ends_on),
                'blocks_teaching' => (bool) $exception->blocks_teaching,
                'authority_reference' => $exception->authority_reference,
            ])->all()
            : [];
        $resourceExceptions = ResourceUnavailability::query()
            ->where('term_id', $term->id)
            ->whereNotNull('effective_on')
            ->get()
            ->map(fn (ResourceUnavailability $record): array => [
                'resource_unavailability_id' => (int) $record->id,
                'source_type' => 'ResourceUnavailability',
                'effective_on' => $this->dateString($record->effective_on),
                'room_id' => $record->room_id !== null ? (int) $record->room_id : null,
                'faculty_user_id' => $record->faculty_user_id !== null ? (int) $record->faculty_user_id : null,
                'starts_at' => $this->timeString($record->starts_at),
                'ends_at' => $this->timeString($record->ends_at),
                'authority_reference' => $record->authority_reference,
            ])->all();

        return [...$calendarExceptions, ...$resourceExceptions];
    }

    private function hourMinute(mixed $value): mixed
    {
        if (! is_string($value) || ! preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $value)) {
            return $value;
        }

        return mb_substr($value, 0, 5);
    }

    /** @return list<array<string, mixed>> */
    private function facultyAvailabilityPayload(Term $term): array
    {
        return FacultyAvailabilityDeclaration::query()
            ->where('term_id', $term->id)
            ->orderBy('faculty_user_id')
            ->orderByDesc('version')
            ->get()
            ->unique('faculty_user_id')
            ->map(fn (FacultyAvailabilityDeclaration $record): array => [
                'faculty_user_id' => (int) $record->faculty_user_id,
                'declaration_version' => (int) $record->version,
                'declaration' => $record->declaration,
                'hard_unavailability' => $record->hard_unavailability ?? [],
                'declared_at' => $record->declared_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function hardConstraints(): array
    {
        return [
            'assign_every_ready_scheduling_demand_once',
            'faculty_no_overlap',
            'room_no_overlap',
            'section_delivery_group_no_overlap',
            'respect_fixed_assignments',
            'respect_calendar_blocks',
            'respect_room_capacity_type_and_features',
            'respect_faculty_qualification_and_load',
        ];
    }

    /**
     * @return list<string>
     */
    private function softConstraints(): array
    {
        return [
            'cohort_mode_switches',
            'cohort_idle_time',
            'faculty_load_imbalance',
            'faculty_idle_time',
            'room_seat_waste',
            'stable_earlier_placement',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $demands
     * @return list<array<string, mixed>>
     */
    private function fixedAssignmentsPayload(array $demands): array
    {
        return collect($demands)
            ->filter(fn (array $demand): bool => $demand['fixed_faculty_user_id'] !== null
                || $demand['fixed_room_id'] !== null
                || $demand['fixed_day_of_week'] !== null
                || $demand['fixed_start_time'] !== null)
            ->map(fn (array $demand): array => [
                'scheduling_demand_id' => (int) $demand['scheduling_demand_id'],
                'fixed_faculty_user_id' => $demand['fixed_faculty_user_id'],
                'fixed_room_id' => $demand['fixed_room_id'],
                'fixed_day_of_week' => $demand['fixed_day_of_week'],
                'fixed_start_time' => $demand['fixed_start_time'],
            ])
            ->values()
            ->all();
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @return list<int>
     */
    private function schedulingDays(Term $term): array
    {
        $days = collect($term->scheduling_days ?? [1, 2, 3, 4, 5, 6])
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $days !== [] ? $days : [1, 2, 3, 4, 5, 6];
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<mixed> */
    private function listValue(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hour * 60) + $minute;
    }

    private function time(int $minutes): string
    {
        $hour = intdiv($minutes, 60);
        $minute = $minutes % 60;

        return sprintf('%02d:%02d:00', $hour, $minute);
    }

    private function compactTime(int $minutes): string
    {
        $hour = intdiv($minutes, 60);
        $minute = $minutes % 60;

        return sprintf('%02d%02d', $hour, $minute);
    }

    private function timeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        $time = (string) $value;

        return strlen($time) === 5 ? $time.':00' : substr($time, 0, 8);
    }
}
