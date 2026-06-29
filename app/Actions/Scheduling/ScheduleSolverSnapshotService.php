<?php

namespace App\Actions\Scheduling;

use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
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
    private const ContractVersion = 'tal61-demand-v1';

    private const DayStartsAt = '07:00:00';

    private const DayEndsAt = '20:00:00';

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
        return SchedulingDemand::query()
            ->with([
                'courseComponent.courseSpecification.course',
                'fixedFaculty',
                'fixedRoom',
                'sectionDeliveryGroup.section',
                'termOffering.curriculumEntry.courseSpecification.course',
            ])
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
            ->where('validation_state', SchedulingDemand::ValidationReadyForReview)
            ->orderBy('term_offering_id')
            ->orderBy('section_delivery_group_id')
            ->orderBy('course_component_id')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, SchedulingDemand>  $demands
     * @return array<string, mixed>
     */
    private function buildSnapshot(ScheduleGenerationRun $run, Term $term, EloquentCollection $demands): array
    {
        $timeSlots = $this->timeSlots($term);
        $demandPayload = $this->schedulingDemandsPayload($demands);

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
        $dayStart = $this->minutes(self::DayStartsAt);
        $dayEnd = $this->minutes(self::DayEndsAt);
        $slots = [];
        $id = 1;

        for ($day = 1; $day <= 6; $day++) {
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
    private function schedulingDemandsPayload(EloquentCollection $demands): array
    {
        return $demands
            ->map(function ($demand): array {
                $source = $this->arrayValue($demand->getAttribute('source_snapshot'));
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
                $facultyOptions = collect($source['faculty_load_options'] ?? [])
                    ->filter(fn (mixed $option): bool => is_array($option) && isset($option['faculty_user_id']))
                    ->values()
                    ->all();

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
                    'required_duration_minutes' => (int) $demand->required_duration_minutes,
                    'meeting_count' => (int) $demand->meeting_count,
                    'modality' => $demand->modality,
                    'expected_count' => (int) ($source['expected_count'] ?? $group->expected_count ?? 0),
                    'section_capacity' => (int) ($source['section_capacity'] ?? $section->capacity ?? 0),
                    'room_type_requirement' => $source['room_type_requirement'] ?? null,
                    'room_required' => $demand->modality === TermOffering::ModalityFaceToFace,
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
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (Room $room): array => [
                'room_id' => (int) $room->id,
                'code' => $room->code,
                'name' => $room->name,
                'room_type' => $room->room_type,
                'capacity' => (int) $room->capacity,
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
            ->where('blocks_scheduling', true)
            ->where('state', CalendarEvent::StateActive)
            ->orderBy('start_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($event): array => [
                'calendar_event_id' => (int) $event->id,
                'event_type' => $event->event_type,
                'scope_type' => $event->scope_type,
                'room_id' => $event->room_id !== null ? (int) $event->room_id : null,
                'faculty_user_id' => $event->faculty_user_id !== null ? (int) $event->faculty_user_id : null,
                'start_at' => $this->dateTimeString($event->getAttribute('start_at')),
                'end_at' => $this->dateTimeString($event->getAttribute('end_at')),
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
            'respect_room_capacity_and_type',
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

    private function dateTimeString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
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

        $time = (string) $value;

        return strlen($time) === 5 ? $time.':00' : substr($time, 0, 8);
    }
}
