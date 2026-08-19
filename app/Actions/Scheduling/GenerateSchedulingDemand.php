<?php

namespace App\Actions\Scheduling;

use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Room;
use App\Models\SchedulingCommitment;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class GenerateSchedulingDemand
{
    /**
     * @return array{created:int,updated:int,skipped:int,total:int,ready:int,action_required:int,findings:int}
     */
    public function forTerm(User $actor, Term $term): array
    {
        Gate::forUser($actor)->authorize('create', SchedulingDemand::class);

        return DB::transaction(function () use ($actor, $term): array {
            $summary = [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'total' => 0,
                'ready' => 0,
                'action_required' => 0,
                'findings' => 0,
            ];

            $offerings = $this->offeringsForTerm($term);

            foreach ($offerings as $offering) {
                $components = $this->courseComponents($offering);
                $sections = $this->sectionsForOffering($offering);

                foreach ($sections as $section) {
                    if (in_array($section->state, [Section::StateClosed, Section::StateCancelled], true)) {
                        continue;
                    }

                    foreach ($this->deliveryGroupsForSection($section) as $group) {
                        if (in_array($group->state, [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled], true)) {
                            continue;
                        }

                        foreach ($components as $component) {
                            $attributes = $this->attributesForDemand($actor, $term, $offering, $section, $group, $component);
                            $demand = SchedulingDemand::query()->firstOrNew([
                                'term_offering_id' => $attributes['term_offering_id'],
                                'course_component_id' => $attributes['course_component_id'],
                                'section_delivery_group_id' => $attributes['section_delivery_group_id'],
                            ]);
                            $wasNew = ! $demand->exists;
                            $demand->fill($attributes);
                            $demand->save();

                            $summary['total']++;
                            $summary['findings'] += count($attributes['readiness_findings']);

                            if ($attributes['validation_state'] === SchedulingDemand::ValidationReadyForReview) {
                                $summary['ready']++;
                            } else {
                                $summary['action_required']++;
                            }

                            if ($wasNew) {
                                $summary['created']++;
                            } elseif ($demand->wasChanged()) {
                                $summary['updated']++;
                            } else {
                                $summary['skipped']++;
                            }
                        }
                    }
                }
            }

            return $summary;
        }, 3);
    }

    /**
     * @return EloquentCollection<int, TermOffering>
     */
    private function offeringsForTerm(Term $term): EloquentCollection
    {
        return TermOffering::query()
            ->whereBelongsTo($term)
            ->where('state', TermOffering::StatePendingScheduling)
            ->with([
                'term.calendarEvents',
                'curriculumEntry.courseSpecification.course',
                'curriculumEntry.courseSpecification.components',
                'sections.deliveryGroups',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return EloquentCollection<int, Section>
     */
    private function sectionsForOffering(TermOffering $offering): EloquentCollection
    {
        return Section::query()
            ->whereBelongsTo($offering)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return EloquentCollection<int, SectionDeliveryGroup>
     */
    private function deliveryGroupsForSection(Section $section): EloquentCollection
    {
        return SectionDeliveryGroup::query()
            ->whereBelongsTo($section)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return EloquentCollection<int, CourseComponent>
     */
    private function courseComponents(TermOffering $offering): EloquentCollection
    {
        $specification = $offering->courseSpecification();

        if (! $specification instanceof CourseSpecification) {
            return new EloquentCollection;
        }

        return CourseComponent::query()
            ->whereBelongsTo($specification)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesForDemand(
        User $actor,
        Term $term,
        TermOffering $offering,
        Section $section,
        SectionDeliveryGroup $group,
        CourseComponent $component,
    ): array {
        $specification = CourseSpecification::query()->find($component->course_specification_id);
        $course = $specification instanceof CourseSpecification
            ? Course::query()->find($specification->course_id)
            : null;
        $modality = filled($group->modality) ? (string) $group->modality : (string) $offering->modality;
        $meetingPattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);
        $durationMinutes = $meetingPattern['duration_minutes']
            ?? max(1, (int) round((float) $component->weekly_contact_hours * 60));
        $meetingCount = $meetingPattern['count'] ?? 1;
        $commitments = SchedulingCommitment::query()
            ->where('term_id', $term->id)
            ->where('section_id', $section->id)
            ->lockForUpdate()
            ->get();
        $commitment = $commitments->count() === 1 ? $commitments->first() : null;
        $fixedInputs = $this->fixedInputs($group);

        if ($commitment instanceof SchedulingCommitment) {
            $fixedInputs = [
                'fixed_faculty_user_id' => $commitment->faculty_user_id !== null ? (int) $commitment->faculty_user_id : $fixedInputs['fixed_faculty_user_id'],
                'fixed_room_id' => $commitment->room_id !== null ? (int) $commitment->room_id : $fixedInputs['fixed_room_id'],
                'fixed_day_of_week' => $commitment->day_of_week !== null ? (int) $commitment->day_of_week : $fixedInputs['fixed_day_of_week'],
                'fixed_start_time' => $this->timeString($commitment->starts_at),
            ];
        }
        $roomTypeRequirement = $offering->room_type_override ?: $component->room_type_default;
        $facultyLoadOptions = $course instanceof Course
            ? $this->facultyLoadOptions($term, $course)
            : [];
        $suitableRoomCount = $this->suitableRoomCount($modality, $roomTypeRequirement, (int) $group->expected_count);
        $schedulingWindowCount = $this->schedulingWindowCount($term);
        $blockingCalendarBlocks = $this->recurringBlockingCalendarBlocks($term);
        $sameFacultyDefault = $specification instanceof CourseSpecification ? $specification->same_faculty_default : null;

        $snapshot = [
            'term_id' => (int) $term->id,
            'term_offering_id' => (int) $offering->id,
            'section_id' => (int) $section->id,
            'section_delivery_group_id' => (int) $group->id,
            'curriculum_entry_id' => (int) $offering->curriculum_entry_id,
            'course_specification_id' => $specification instanceof CourseSpecification ? (int) $specification->id : null,
            'course_id' => $course instanceof Course ? (int) $course->id : null,
            'course_code' => $course?->code,
            'course_component_id' => (int) $component->id,
            'component_type' => $component->component_type,
            'weekly_contact_hours' => number_format((float) $component->weekly_contact_hours, 2, '.', ''),
            'meeting_pattern' => $component->meeting_pattern,
            'scheduling_commitment_id' => $commitment?->id,
            'expected_count' => (int) $group->expected_count,
            'section_capacity' => (int) $section->capacity,
            'offering_modality' => $offering->modality,
            'demand_modality' => $modality,
            'room_type_requirement' => $roomTypeRequirement,
            'same_faculty_required' => (bool) ($offering->same_faculty_override ?? $sameFacultyDefault ?? $component->same_faculty),
            'eligible_faculty_count' => count($facultyLoadOptions),
            'faculty_load_options' => $facultyLoadOptions,
            'suitable_room_count' => $suitableRoomCount,
            'active_scheduling_window_count' => $schedulingWindowCount,
            'blocking_calendar_event_count' => count($blockingCalendarBlocks),
            'blocking_calendar_blocks' => $blockingCalendarBlocks,
        ];

        $findings = $this->findings(
            term: $term,
            offering: $offering,
            section: $section,
            group: $group,
            component: $component,
            specification: $specification,
            course: $course,
            modality: $modality,
            roomTypeRequirement: is_string($roomTypeRequirement) ? $roomTypeRequirement : null,
            fixedInputs: $fixedInputs,
            facultyLoadOptions: $facultyLoadOptions,
            suitableRoomCount: $suitableRoomCount,
            schedulingWindowCount: $schedulingWindowCount,
            blockingCalendarBlocks: $blockingCalendarBlocks,
            durationMinutes: $durationMinutes,
        );

        if ($commitments->count() > 1) {
            $findings[] = $this->finding('multiple_section_commitments', 'blocking', 'section', $section->id, 'A Class Offering may have only one current exact scheduling commitment.');
        } elseif ($commitment instanceof SchedulingCommitment) {
            $commitmentStart = $this->minutesFromTime($this->timeString($commitment->starts_at));
            $commitmentEnd = $this->minutesFromTime($this->timeString($commitment->ends_at));

            if ($commitment->day_of_week === null
                || $commitmentStart === null
                || $commitmentEnd === null
                || ($commitment->faculty_user_id === null && $commitment->room_id === null)) {
                $findings[] = $this->finding('incomplete_section_commitment', 'blocking', 'scheduling_commitment', $commitment->id, 'An exact Class Offering commitment requires a day, start, end, and at least one committed Faculty or room.');
            } elseif (($commitmentEnd - $commitmentStart) !== $durationMinutes) {
                $findings[] = $this->finding('section_commitment_duration_mismatch', 'blocking', 'scheduling_commitment', $commitment->id, 'The exact Class Offering commitment duration must equal one Meeting Pattern block.');
            }
        }

        return [
            'term_offering_id' => $offering->id,
            'course_component_id' => $component->id,
            'section_delivery_group_id' => $group->id,
            'demand_key' => $this->demandKey($offering, $group, $component),
            'required_duration_minutes' => $durationMinutes,
            'meeting_count' => $meetingCount,
            'modality' => $modality,
            'fixed_faculty_user_id' => $fixedInputs['fixed_faculty_user_id'],
            'fixed_room_id' => $fixedInputs['fixed_room_id'],
            'fixed_day_of_week' => $fixedInputs['fixed_day_of_week'],
            'fixed_start_time' => $fixedInputs['fixed_start_time'],
            'source_snapshot' => $snapshot,
            'readiness_findings' => $findings,
            'validation_state' => $findings === []
                ? SchedulingDemand::ValidationReadyForReview
                : SchedulingDemand::ValidationActionRequired,
            'generated_by' => $actor->id,
            'readiness_checked_at' => now(),
        ];
    }

    private function demandKey(TermOffering $offering, SectionDeliveryGroup $group, CourseComponent $component): string
    {
        return "term-offering:{$offering->id}:delivery-group:{$group->id}:component:{$component->id}";
    }

    /**
     * @return array{fixed_faculty_user_id:int|null,fixed_room_id:int|null,fixed_day_of_week:int|null,fixed_start_time:string|null}
     */
    private function fixedInputs(SectionDeliveryGroup $group): array
    {
        $rawOverride = $group->getAttribute('delivery_override');
        $override = is_array($rawOverride) ? $rawOverride : [];

        return [
            'fixed_faculty_user_id' => $this->integerOrNull($override['fixed_faculty_user_id'] ?? $override['faculty_user_id'] ?? null),
            'fixed_room_id' => $this->integerOrNull($override['fixed_room_id'] ?? $override['room_id'] ?? null),
            'fixed_day_of_week' => $this->integerOrNull($override['fixed_day_of_week'] ?? $override['day_of_week'] ?? null),
            'fixed_start_time' => filled($override['fixed_start_time'] ?? $override['start_time'] ?? null)
                ? (string) ($override['fixed_start_time'] ?? $override['start_time'])
                : null,
        ];
    }

    private function integerOrNull(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? null : $integer;
    }

    /**
     * @return list<array{faculty_user_id:int<0, max>,qualification_id:int,term_load_override_id:int|null,max_allowed_units:string|null}>
     */
    private function facultyLoadOptions(Term $term, Course $course): array
    {
        $qualifications = FacultyQualification::query()
            ->whereBelongsTo($course)
            ->where('is_active', true)
            ->orderBy('faculty_user_id')
            ->get();

        if ($qualifications->isEmpty()) {
            return [];
        }

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
                    'max_allowed_units' => $this->decimalString($maxAllowedUnits),
                ];
            })
            ->values()
            ->all();
    }

    private function decimalString(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 2, '.', '');
    }

    private function suitableRoomCount(string $modality, mixed $roomTypeRequirement, int $expectedCount): int
    {
        if ($modality !== TermOffering::ModalityFaceToFace) {
            return 0;
        }

        return Room::query()
            ->where('is_active', true)
            ->where('capacity', '>=', $expectedCount)
            ->when(filled($roomTypeRequirement), fn ($query) => $query->where('room_type', $roomTypeRequirement))
            ->count();
    }

    private function schedulingWindowCount(Term $term): int
    {
        return CalendarEvent::query()
            ->whereBelongsTo($term)
            ->where('event_type', CalendarEvent::TypeWindow)
            ->where('process_key', 'scheduling')
            ->where('state', CalendarEvent::StateActive)
            ->whereNotNull('start_at')
            ->whereNotNull('end_at')
            ->count();
    }

    /**
     * @return list<array{id:int,scope_type:string,room_id:int|null,faculty_user_id:int|null,event_type:string,process_key:string|null,day_of_week:int,starts_at:string,ends_at:string}>
     */
    private function recurringBlockingCalendarBlocks(Term $term): array
    {
        return CalendarEvent::query()
            ->whereBelongsTo($term)
            ->where('blocks_scheduling', true)
            ->where('state', CalendarEvent::StateActive)
            ->whereNotNull('day_of_week')
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (CalendarEvent $event): array => [
                'id' => (int) $event->id,
                'scope_type' => (string) $event->scope_type,
                'room_id' => $event->room_id === null ? null : (int) $event->room_id,
                'faculty_user_id' => $event->faculty_user_id === null ? null : (int) $event->faculty_user_id,
                'event_type' => (string) $event->event_type,
                'process_key' => $event->process_key,
                'day_of_week' => (int) $event->day_of_week,
                'starts_at' => $this->timeString($event->starts_at),
                'ends_at' => $this->timeString($event->ends_at),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{fixed_faculty_user_id:int|null,fixed_room_id:int|null,fixed_day_of_week:int|null,fixed_start_time:string|null}  $fixedInputs
     * @param  list<array{faculty_user_id:int<0, max>,qualification_id:int,term_load_override_id:int|null,max_allowed_units:string|null}>  $facultyLoadOptions
     * @param  list<array{id:int,scope_type:string,room_id:int|null,faculty_user_id:int|null,event_type:string,process_key:string|null,day_of_week:int,starts_at:string,ends_at:string}>  $blockingCalendarBlocks
     * @return list<array{key:string,severity:string,source_type:string,source_id:int|null,message:string}>
     */
    private function findings(
        Term $term,
        TermOffering $offering,
        Section $section,
        SectionDeliveryGroup $group,
        CourseComponent $component,
        ?CourseSpecification $specification,
        ?Course $course,
        string $modality,
        ?string $roomTypeRequirement,
        array $fixedInputs,
        array $facultyLoadOptions,
        int $suitableRoomCount,
        int $schedulingWindowCount,
        array $blockingCalendarBlocks,
        int $durationMinutes,
    ): array {
        $findings = [];

        if ($term->state !== Term::StateActive) {
            $findings[] = $this->finding('term_not_active', 'blocking', 'term', $term->id, 'The term must be active before solver readiness can pass.');
        }

        if ($schedulingWindowCount === 0) {
            $findings[] = $this->finding('missing_active_scheduling_window', 'blocking', 'term', $term->id, 'No active Academic Calendar scheduling window is recorded for this term.');
        }

        if ((int) $term->scheduling_slot_minutes < 1) {
            $findings[] = $this->finding('invalid_scheduling_slot_minutes', 'blocking', 'term', $term->id, 'The term must define a positive scheduling slot duration.');
        }

        if (! $specification instanceof CourseSpecification || $specification->state !== CourseSpecification::StateActive) {
            $findings[] = $this->finding('course_specification_not_active', 'blocking', 'course_specification', $specification?->id, 'The demand must reference an active Course Specification revision.');
        }

        if ((float) $component->weekly_contact_hours <= 0.0) {
            $findings[] = $this->finding('missing_component_contact_hours', 'blocking', 'course_component', $component->id, 'The Course Component must define positive weekly contact hours.');
        }

        $meetingPattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);

        if ($meetingPattern === null) {
            $findings[] = $this->finding('missing_meeting_pattern', 'blocking', 'course_component', $component->id, 'The Course Component must define an approved weekly Meeting Pattern.');
        } elseif (($meetingPattern['count'] * $meetingPattern['duration_minutes']) !== (int) round((float) $component->weekly_contact_hours * 60)) {
            $findings[] = $this->finding('meeting_pattern_contact_hours_mismatch', 'blocking', 'course_component', $component->id, 'The weekly Meeting Pattern must equal the Course Component contact hours.');
        }

        if (filled($component->modality_restriction) && $component->modality_restriction !== $modality) {
            $findings[] = $this->finding('component_modality_restriction_mismatch', 'blocking', 'course_component', $component->id, 'The delivery group modality does not match the Course Component modality restriction.');
        }

        if ($group->state !== SectionDeliveryGroup::StateReady) {
            $findings[] = $this->finding('delivery_group_not_ready', 'blocking', 'section_delivery_group', $group->id, 'The Section Delivery Group must be marked Ready before solver dispatch.');
        }

        if ($group->exceedsSectionCapacity()) {
            $findings[] = $this->finding('delivery_group_expected_count_exceeds_section_capacity', 'blocking', 'section_delivery_group', $group->id, 'The delivery-group expected count exceeds the owning section capacity.');
        }

        if ($course instanceof Course && $facultyLoadOptions === []) {
            $findings[] = $this->finding('missing_active_faculty_qualification', 'blocking', 'course', $course->id, 'No active Faculty Qualification exists for the demand course.');
        }

        if ($term->default_max_units === null && collect($facultyLoadOptions)->contains(fn (array $option): bool => $option['max_allowed_units'] === null)) {
            $findings[] = $this->finding('missing_default_faculty_load', 'blocking', 'term', $term->id, 'The term must define a default faculty load or an active Faculty Term Load Override for each eligible faculty member.');
        }

        if ($modality === TermOffering::ModalityFaceToFace) {
            if (blank($roomTypeRequirement)) {
                $findings[] = $this->finding('missing_room_type_requirement', 'blocking', 'course_component', $component->id, 'Face-to-Face demand needs a room type from the Course Component or Term Offering override.');
            } elseif ($suitableRoomCount === 0) {
                $findings[] = $this->finding('missing_suitable_room', 'blocking', 'room', null, 'No active room matches the required room type and expected delivery-group count.');
            }

            if ($fixedInputs['fixed_room_id'] !== null && ! $this->fixedRoomIsSuitable($fixedInputs['fixed_room_id'], $roomTypeRequirement, (int) $group->expected_count)) {
                $findings[] = $this->finding('fixed_room_not_suitable', 'blocking', 'room', $fixedInputs['fixed_room_id'], 'The fixed room override is inactive, below expected count, or does not match the required room type.');
            }
        }

        if ($fixedInputs['fixed_faculty_user_id'] !== null && ! collect($facultyLoadOptions)->contains(
            fn (array $option): bool => (int) $option['faculty_user_id'] === $fixedInputs['fixed_faculty_user_id']
        )) {
            $findings[] = $this->finding('fixed_faculty_not_qualified', 'blocking', 'faculty_qualification', $fixedInputs['fixed_faculty_user_id'], 'The fixed faculty override does not point to an active qualified faculty/load option for this course and term.');
        }

        if ($fixedInputs['fixed_day_of_week'] !== null && ($fixedInputs['fixed_day_of_week'] < 1 || $fixedInputs['fixed_day_of_week'] > 7)) {
            $findings[] = $this->finding('invalid_fixed_day_of_week', 'blocking', 'section_delivery_group', $group->id, 'The fixed day override must use a valid recurring day of week.');
        }

        if ($fixedInputs['fixed_start_time'] !== null && $fixedInputs['fixed_day_of_week'] === null) {
            $findings[] = $this->finding('missing_fixed_day_for_fixed_time', 'blocking', 'section_delivery_group', $group->id, 'A fixed start time override must include the recurring day it belongs to.');
        }

        if ($fixedInputs['fixed_start_time'] !== null && $this->minutesFromTime($fixedInputs['fixed_start_time']) === null) {
            $findings[] = $this->finding('invalid_fixed_start_time', 'blocking', 'section_delivery_group', $group->id, 'The fixed start time override must be a valid time value.');
        }

        $fixedDay = $fixedInputs['fixed_day_of_week'];
        $fixedStart = $this->minutesFromTime($fixedInputs['fixed_start_time']);
        $schedulingDays = array_map('intval', $term->scheduling_days ?? []);
        $dayStart = $this->minutesFromTime($this->timeString($term->scheduling_day_starts_at));
        $dayEnd = $this->minutesFromTime($this->timeString($term->scheduling_day_ends_at));
        $slotMinutes = (int) $term->scheduling_slot_minutes;

        if ($fixedDay !== null
            && $fixedDay >= 1
            && $fixedDay <= 7
            && ! in_array($fixedDay, $schedulingDays, true)) {
            $findings[] = $this->finding('fixed_day_outside_scheduling_grid', 'blocking', 'term', $term->id, 'The fixed day override must belong to the term scheduling days.');
        }

        if ($fixedStart !== null
            && $dayStart !== null
            && ($fixedStart < $dayStart
                || $slotMinutes < 1
                || ($fixedStart - $dayStart) % $slotMinutes !== 0)) {
            $findings[] = $this->finding('fixed_start_outside_scheduling_grid', 'blocking', 'term', $term->id, 'The fixed start override must align with a captured term scheduling slot.');
        }

        if ($fixedStart !== null
            && $dayEnd !== null
            && $fixedStart + $durationMinutes > $dayEnd) {
            $findings[] = $this->finding('fixed_time_exceeds_scheduling_day', 'blocking', 'term', $term->id, 'The fixed assignment duration must finish within the term scheduling day.');
        }

        $conflictingBlockId = $this->conflictingCalendarBlockId($fixedInputs, $blockingCalendarBlocks, $durationMinutes);

        if ($conflictingBlockId !== null) {
            $findings[] = $this->finding('fixed_time_conflicts_with_calendar_block', 'blocking', 'calendar_event', $conflictingBlockId, 'The fixed day/time override overlaps an active recurring scheduling block.');
        }

        return $findings;
    }

    /**
     * @return array{key:string,severity:string,source_type:string,source_id:int|null,message:string}
     */
    private function finding(string $key, string $severity, string $sourceType, ?int $sourceId, string $message): array
    {
        return [
            'key' => $key,
            'severity' => $severity,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'message' => $message,
        ];
    }

    private function fixedRoomIsSuitable(int $roomId, ?string $roomTypeRequirement, int $expectedCount): bool
    {
        return Room::query()
            ->whereKey($roomId)
            ->where('is_active', true)
            ->where('capacity', '>=', $expectedCount)
            ->when(filled($roomTypeRequirement), fn ($query) => $query->where('room_type', $roomTypeRequirement))
            ->exists();
    }

    /**
     * @param  array{fixed_faculty_user_id:int|null,fixed_room_id:int|null,fixed_day_of_week:int|null,fixed_start_time:string|null}  $fixedInputs
     * @param  list<array{id:int,scope_type:string,room_id:int|null,faculty_user_id:int|null,event_type:string,process_key:string|null,day_of_week:int,starts_at:string,ends_at:string}>  $blockingCalendarBlocks
     */
    private function conflictingCalendarBlockId(array $fixedInputs, array $blockingCalendarBlocks, int $durationMinutes): ?int
    {
        if ($fixedInputs['fixed_day_of_week'] === null || $fixedInputs['fixed_start_time'] === null) {
            return null;
        }

        $fixedStart = $this->minutesFromTime($fixedInputs['fixed_start_time']);

        if ($fixedStart === null) {
            return null;
        }

        $fixedEnd = $fixedStart + $durationMinutes;

        foreach ($blockingCalendarBlocks as $block) {
            if ((int) $block['day_of_week'] !== $fixedInputs['fixed_day_of_week'] || ! $this->calendarBlockAppliesToFixedInputs($block, $fixedInputs)) {
                continue;
            }

            $blockStart = $this->minutesFromTime($block['starts_at']);
            $blockEnd = $this->minutesFromTime($block['ends_at']);

            if ($blockStart !== null && $blockEnd !== null && $fixedStart < $blockEnd && $fixedEnd > $blockStart) {
                return (int) $block['id'];
            }
        }

        return null;
    }

    /**
     * @param  array{id:int,scope_type:string,room_id:int|null,faculty_user_id:int|null,event_type:string,process_key:string|null,day_of_week:int,starts_at:string,ends_at:string}  $block
     * @param  array{fixed_faculty_user_id:int|null,fixed_room_id:int|null,fixed_day_of_week:int|null,fixed_start_time:string|null}  $fixedInputs
     */
    private function calendarBlockAppliesToFixedInputs(array $block, array $fixedInputs): bool
    {
        if ($block['scope_type'] === CalendarEvent::ScopeInstitution) {
            return true;
        }

        if ($block['scope_type'] === CalendarEvent::ScopeRoom) {
            return $fixedInputs['fixed_room_id'] !== null && $block['room_id'] === $fixedInputs['fixed_room_id'];
        }

        if ($block['scope_type'] === CalendarEvent::ScopeFaculty) {
            return $fixedInputs['fixed_faculty_user_id'] !== null && $block['faculty_user_id'] === $fixedInputs['fixed_faculty_user_id'];
        }

        return false;
    }

    private function minutesFromTime(?string $time): ?int
    {
        if ($time === null || ! preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    private function timeString(mixed $time): string
    {
        if ($time instanceof \DateTimeInterface) {
            return $time->format('H:i:s');
        }

        return (string) $time;
    }
}
