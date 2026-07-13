<?php

namespace App\Actions\Scheduling;

use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleSolverSnapshotService
{
    private const ContractVersion = 'tal94-demand-v2';

    private const DefaultDayStartsAt = '07:00:00';

    private const DefaultDayEndsAt = '20:00:00';

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

        if (array_diff($excludedDemandIds, $demandIds) !== []) {
            throw ValidationException::withMessages([
                'scheduling_demands' => 'Only demands captured by the selected run may be excluded from live validation.',
            ]);
        }

        $demands = $this->demandsForTerm($term, $demandIds);
        $demands = $demands->reject(
            fn (SchedulingDemand $demand): bool => in_array((int) $demand->id, $excludedDemandIds, true),
        );

        if ($demands->isEmpty() && count($excludedDemandIds) !== count($demandIds)) {
            throw ValidationException::withMessages([
                'scheduling_demands' => 'Schedule revalidation requires current Scheduling Demand rows.',
            ]);
        }

        return $this->buildSnapshot($run, $term, $demands, useCurrentSources: true);
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
                'sectionDeliveryGroup.section',
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
    ): array {
        $timeSlots = $this->timeSlots($term);
        $demandPayload = $this->schedulingDemandsPayload($demands, $term, $useCurrentSources);

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
            'time_slots' => $timeSlots,
            'subjects' => $this->subjectsPayload($demandPayload),
            'scheduling_demands' => $demandPayload,
            'sections' => $this->sectionsPayload($demandPayload),
            'section_delivery_groups' => $this->sectionDeliveryGroupsPayload($demandPayload),
            'rooms' => $this->roomsPayload(),
            'faculty' => $this->facultyPayload($demandPayload),
            'faculty_qualifications' => $this->facultyQualificationsPayload($demandPayload),
            'faculty_availability' => [],
            'term_offerings' => $this->termOfferingsPayload($demandPayload),
            'student_cohort_groups' => $this->studentCohortGroupsPayload($demandPayload),
            'calendar_blocks' => $this->calendarBlocksPayload($term),
            'hard_constraints' => $this->hardConstraints(),
            'soft_constraints' => $this->softConstraints(),
            'constraint_profile' => [
                'key' => 'balanced_v1',
                'version' => 1,
                'hard_constraints' => $this->hardConstraints(),
                'soft_weights' => array_fill_keys($this->softConstraints(), 1),
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
        $slotMinutes = max(1, (int) $term->scheduling_slot_minutes);
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
    ): array {
        return $demands
            ->map(function ($demand) use ($term, $useCurrentSources): array {
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

                return [
                    'scheduling_demand_id' => (int) $demand->id,
                    'demand_key' => $demand->demand_key,
                    'term_offering_id' => (int) $demand->term_offering_id,
                    'section_id' => $section?->id !== null ? (int) $section->id : (int) ($source['section_id'] ?? 0),
                    'section_delivery_group_id' => (int) $demand->section_delivery_group_id,
                    'course_id' => $course?->id !== null ? (int) $course->id : $this->nullableInt($source['course_id'] ?? null),
                    'course_code' => $course->code ?? ($source['course_code'] ?? null),
                    'course_component_id' => (int) $demand->course_component_id,
                    'component_type' => $component->component_type ?? ($source['component_type'] ?? null),
                    'required_duration_minutes' => $useCurrentSources && $component instanceof CourseComponent
                        ? max(1, (int) round((float) $component->weekly_contact_hours * 60))
                        : (int) $demand->required_duration_minutes,
                    'meeting_count' => (int) $demand->meeting_count,
                    'modality' => $modality,
                    'validation_state' => $demand->validation_state,
                    'expected_count' => (int) ($source['expected_count'] ?? $group->expected_count ?? 0),
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
                    'fixed_faculty_user_id' => $demand->fixed_faculty_user_id !== null ? (int) $demand->fixed_faculty_user_id : null,
                    'fixed_room_id' => $demand->fixed_room_id !== null ? (int) $demand->fixed_room_id : null,
                    'fixed_day_of_week' => $demand->fixed_day_of_week !== null ? (int) $demand->fixed_day_of_week : null,
                    'fixed_start_time' => $this->timeString($demand->fixed_start_time),
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
            ->map(fn (array $demand): array => [
                'cohort_or_student_group_id' => (int) $demand['section_delivery_group_id'],
                'section_delivery_group_id' => (int) $demand['section_delivery_group_id'],
                'expected_count' => (int) $demand['expected_count'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function calendarBlocksPayload(Term $term): array
    {
        return CalendarEvent::query()
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
            'prefer_earlier_time_blocks',
            'reduce_faculty_idle_gaps',
            'balance_faculty_load',
            'use_rooms_efficiently',
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
