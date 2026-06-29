<?php

namespace App\Actions\Scheduling;

use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Room;
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
                                'demand_key' => $attributes['demand_key'],
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
        $durationMinutes = max(1, (int) round((float) $component->weekly_contact_hours * 60));
        $fixedInputs = $this->fixedInputs($group);
        $roomTypeRequirement = $offering->room_type_override ?: $component->room_type_default;
        $facultyLoadOptions = $course instanceof Course
            ? $this->facultyLoadOptions($term, $course)
            : [];
        $suitableRoomCount = $this->suitableRoomCount($modality, $roomTypeRequirement, (int) $group->expected_count);
        $schedulingWindowCount = $this->schedulingWindowCount($term);
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
            'blocking_calendar_event_count' => $this->blockingCalendarEventCount($term),
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
            facultyLoadOptions: $facultyLoadOptions,
            suitableRoomCount: $suitableRoomCount,
            schedulingWindowCount: $schedulingWindowCount,
        );

        return [
            'term_offering_id' => $offering->id,
            'course_component_id' => $component->id,
            'section_delivery_group_id' => $group->id,
            'demand_key' => $this->demandKey($offering, $group, $component),
            'required_duration_minutes' => $durationMinutes,
            'meeting_count' => 1,
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

    private function blockingCalendarEventCount(Term $term): int
    {
        return CalendarEvent::query()
            ->whereBelongsTo($term)
            ->where('blocks_scheduling', true)
            ->where('state', CalendarEvent::StateActive)
            ->count();
    }

    /**
     * @param  list<array{faculty_user_id:int<0, max>,qualification_id:int,term_load_override_id:int|null,max_allowed_units:string|null}>  $facultyLoadOptions
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
        array $facultyLoadOptions,
        int $suitableRoomCount,
        int $schedulingWindowCount,
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
}
