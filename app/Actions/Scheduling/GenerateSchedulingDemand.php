<?php

namespace App\Actions\Scheduling;

use App\Actions\Calendar\TermCalendarPackageReadinessService;
use App\Actions\Calendar\TermTeachingGrid;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\FacultyQualification;
use App\Models\Room;
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
use Illuminate\Support\Facades\Gate;

class GenerateSchedulingDemand
{
    public static function resolveRoomType(?string $rawOverride, CourseComponent $component): ?string
    {
        $raw = is_string($rawOverride) ? trim($rawOverride) : '';

        if ($raw !== '') {
            if (str_starts_with($raw, '{')) {
                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    $byComponentId = $decoded[(string) $component->id] ?? null;

                    if (is_string($byComponentId) && trim($byComponentId) !== '') {
                        return trim($byComponentId);
                    }

                    $byType = $decoded[(string) $component->component_type] ?? null;

                    if (is_string($byType) && trim($byType) !== '') {
                        return trim($byType);
                    }

                    return filled($component->room_type_default) ? (string) $component->room_type_default : null;
                }
            }

            return $raw;
        }

        return filled($component->room_type_default) ? (string) $component->room_type_default : null;
    }

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

            if ($offerings->isEmpty()) {
                return $summary;
            }

            $activePackage = TermCalendarPackage::query()
                ->where('term_id', $term->id)
                ->where('state', TermCalendarPackage::StateActive)
                ->with(['windows', 'teachingGridRows', 'datedExceptions'])
                ->first();
            $activePackage?->setRelation('term', $term);

            $schedulingWindowCount = $activePackage instanceof TermCalendarPackage
                && app(TermCalendarPackageReadinessService::class)->for($activePackage)['ready']
                ? 1
                : 0;

            $gridRows = $activePackage instanceof TermCalendarPackage
                ? $activePackage->teachingGridRows->sortBy('day_of_week')->map(fn ($row): array => [
                    'day_of_week' => (int) $row->day_of_week,
                    'starts_at' => substr((string) $row->starts_at, 0, 8),
                    'ends_at' => substr((string) $row->ends_at, 0, 8),
                ])->values()
                : collect();
            $slotMinutes = app(TermTeachingGrid::class)->slotMinutes($term);
            $blockingCalendarBlocks = $this->recurringBlockingCalendarBlocks($term, $activePackage);

            $activeRooms = Room::query()->where('is_active', true)->get();
            $activeRoomsById = $activeRooms->keyBy(fn (Room $room): int => (int) $room->id);
            $activeRoomsByType = $activeRooms->groupBy(fn (Room $room): string => (string) $room->room_type);

            $sectionIds = $offerings
                ->flatMap(fn (TermOffering $offering) => $offering->sections)
                ->map(fn (Section $section): int => (int) $section->id)
                ->unique()
                ->values()
                ->all();

            $commitmentsBySection = $sectionIds === []
                ? collect()
                : SchedulingCommitment::query()
                    ->where('term_id', $term->id)
                    ->whereIn('section_id', $sectionIds)
                    ->lockForUpdate()
                    ->get()
                    ->groupBy(fn (SchedulingCommitment $commitment): int => (int) $commitment->section_id);

            $courseIds = $offerings
                ->map(fn (TermOffering $offering): ?int => $offering->courseSpecification()?->course_id !== null
                    ? (int) $offering->courseSpecification()->course_id
                    : null)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $facultyLoadOptionsByCourse = $this->precomputedFacultyLoadOptionsByCourse($term, $courseIds);

            $existingDemands = SchedulingDemand::query()
                ->whereIn('term_offering_id', $offerings->modelKeys())
                ->get()
                ->keyBy(fn (SchedulingDemand $demand): string => "{$demand->term_offering_id}:{$demand->course_component_id}:{$demand->section_delivery_group_id}");

            $now = now();
            $nowString = $now->toDateTimeString();
            $rowsToInsert = [];
            $validComponentIdsByOffering = [];

            foreach ($offerings as $offering) {
                $components = $this->courseComponents($offering);
                $validComponentIdsByOffering[(int) $offering->id] = $components->modelKeys();
                $sections = $this->sectionsForOffering($offering);

                foreach ($sections as $section) {
                    if (in_array($section->state, [Section::StateClosed, Section::StateCancelled], true)) {
                        continue;
                    }

                    foreach ($this->deliveryGroupsForSection($section) as $group) {
                        if (in_array($group->state, [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled], true)) {
                            continue;
                        }

                        $group->setRelation('section', $section);

                        foreach ($components as $component) {
                            $attributes = $this->attributesForDemand(
                                actor: $actor,
                                term: $term,
                                offering: $offering,
                                section: $section,
                                group: $group,
                                component: $component,
                                commitmentsBySection: $commitmentsBySection,
                                facultyLoadOptionsByCourse: $facultyLoadOptionsByCourse,
                                activeRooms: $activeRooms,
                                activeRoomsByType: $activeRoomsByType,
                                activeRoomsById: $activeRoomsById,
                                schedulingWindowCount: $schedulingWindowCount,
                                blockingCalendarBlocks: $blockingCalendarBlocks,
                                gridRows: $gridRows,
                                slotMinutes: $slotMinutes,
                            );

                            $lookupKey = "{$attributes['term_offering_id']}:{$attributes['course_component_id']}:{$attributes['section_delivery_group_id']}";
                            $demand = $existingDemands->get($lookupKey);

                            $summary['total']++;
                            $summary['findings'] += count($attributes['readiness_findings']);

                            if ($attributes['validation_state'] === SchedulingDemand::ValidationReadyForReview) {
                                $summary['ready']++;
                            } else {
                                $summary['action_required']++;
                            }

                            if (! $demand instanceof SchedulingDemand) {
                                $rowsToInsert[] = [
                                    ...$attributes,
                                    'source_snapshot' => json_encode($attributes['source_snapshot'], JSON_THROW_ON_ERROR),
                                    'readiness_findings' => json_encode($attributes['readiness_findings'], JSON_THROW_ON_ERROR),
                                    'readiness_checked_at' => $nowString,
                                    'created_at' => $nowString,
                                    'updated_at' => $nowString,
                                ];
                                $summary['created']++;

                                continue;
                            }

                            if ($this->demandChanged($demand, $attributes)) {
                                $demand->fill($attributes);
                                $demand->readiness_checked_at = $now;
                                $demand->save();
                                $summary['updated']++;
                            } else {
                                $summary['skipped']++;
                            }
                        }
                    }
                }
            }

            if ($rowsToInsert !== []) {
                foreach (array_chunk($rowsToInsert, 1000) as $chunk) {
                    SchedulingDemand::query()->insert($chunk);
                }
            }

            $obsoleteComponentDemandIds = $existingDemands
                ->filter(fn (SchedulingDemand $demand): bool => ($validComponentIdsByOffering[(int) $demand->term_offering_id] ?? []) !== []
                    && ! in_array((int) $demand->course_component_id, $validComponentIdsByOffering[(int) $demand->term_offering_id], true))
                ->map(fn (SchedulingDemand $demand): int => (int) $demand->id)
                ->values()
                ->all();

            if ($obsoleteComponentDemandIds !== []) {
                SchedulingDemand::query()
                    ->whereIn('id', $obsoleteComponentDemandIds)
                    ->whereDoesntHave('sectionMeetings')
                    ->delete();
            }

            return $summary;
        }, 3);
    }

    /**
     * @return EloquentCollection<int, TermOffering>
     */
    private function offeringsForTerm(Term $term): EloquentCollection
    {
        $rows = TermOffering::query()
            ->select([
                'term_offerings.*',
                'ce.id as joined_entry_id',
                'ce.course_specification_id as joined_spec_id',
                'cs.course_id as joined_course_id',
                'cs.state as joined_spec_state',
                'cs.same_faculty_default as joined_spec_same_faculty_default',
                'c.code as joined_course_code',
                'cc.id as joined_component_id',
                'cc.component_type as joined_component_type',
                'cc.weekly_contact_hours as joined_component_weekly_contact_hours',
                'cc.meeting_pattern as joined_component_meeting_pattern',
                'cc.room_type_default as joined_component_room_type_default',
                'cc.required_room_feature_keys as joined_component_required_room_feature_keys',
                'cc.modality_restriction as joined_component_modality_restriction',
                'cc.requires_consecutive_block as joined_component_requires_consecutive_block',
                'cc.same_faculty as joined_component_same_faculty',
                'cc.sequence as joined_component_sequence',
            ])
            ->leftJoin('curriculum_entries as ce', 'ce.id', '=', 'term_offerings.curriculum_entry_id')
            ->leftJoin('course_specifications as cs', 'cs.id', '=', 'ce.course_specification_id')
            ->leftJoin('courses as c', 'c.id', '=', 'cs.course_id')
            ->leftJoin('course_components as cc', 'cc.course_specification_id', '=', 'cs.id')
            ->where('term_offerings.term_id', $term->id)
            ->where('term_offerings.state', TermOffering::StatePendingScheduling)
            ->orderBy('term_offerings.id')
            ->orderBy('cc.sequence')
            ->orderBy('cc.id')
            ->get();

        if ($rows->isEmpty()) {
            return new EloquentCollection;
        }

        $offeringsById = [];
        $componentsBySpecId = [];

        foreach ($rows as $row) {
            $offeringId = (int) $row->id;

            if (! isset($offeringsById[$offeringId])) {
                $offeringsById[$offeringId] = $row;
            }

            if ($row->joined_spec_id !== null && $row->joined_component_id !== null) {
                $specId = (int) $row->joined_spec_id;
                $componentId = (int) $row->joined_component_id;

                if (! isset($componentsBySpecId[$specId][$componentId])) {
                    $featureKeys = $row->joined_component_required_room_feature_keys;
                    if (is_string($featureKeys)) {
                        $decodedKeys = json_decode($featureKeys, true);
                        $featureKeys = is_array($decodedKeys) ? $decodedKeys : null;
                    }

                    $component = (new CourseComponent)->forceFill([
                        'id' => $componentId,
                        'course_specification_id' => $specId,
                        'component_type' => $row->joined_component_type,
                        'weekly_contact_hours' => $row->joined_component_weekly_contact_hours,
                        'meeting_pattern' => $row->joined_component_meeting_pattern,
                        'room_type_default' => $row->joined_component_room_type_default,
                        'required_room_feature_keys' => $featureKeys,
                        'modality_restriction' => $row->joined_component_modality_restriction,
                        'requires_consecutive_block' => (bool) $row->joined_component_requires_consecutive_block,
                        'same_faculty' => (bool) $row->joined_component_same_faculty,
                        'sequence' => (int) $row->joined_component_sequence,
                    ]);
                    $component->exists = true;
                    $componentsBySpecId[$specId][$componentId] = $component;
                }
            }
        }

        $offerings = new EloquentCollection(array_values($offeringsById));
        $offeringIds = $offerings->modelKeys();

        $sectionRows = Section::query()
            ->select([
                'sections.*',
                'sdg.id as joined_group_id',
                'sdg.name as joined_group_name',
                'sdg.expected_count as joined_group_expected_count',
                'sdg.modality as joined_group_modality',
                'sdg.delivery_override as joined_group_delivery_override',
                'sdg.state as joined_group_state',
            ])
            ->leftJoin('section_delivery_groups as sdg', 'sdg.section_id', '=', 'sections.id')
            ->whereIn('sections.term_offering_id', $offeringIds)
            ->orderBy('sections.id')
            ->orderBy('sdg.id')
            ->get();

        $sectionsById = [];
        $groupsBySectionId = [];

        foreach ($sectionRows as $sectionRow) {
            $sectionId = (int) $sectionRow->id;

            if (! isset($sectionsById[$sectionId])) {
                $sectionsById[$sectionId] = $sectionRow;
            }

            if ($sectionRow->joined_group_id !== null) {
                $groupId = (int) $sectionRow->joined_group_id;
                $rawOverride = $sectionRow->joined_group_delivery_override;
                if (is_string($rawOverride)) {
                    $decodedOverride = json_decode($rawOverride, true);
                    $rawOverride = is_array($decodedOverride) ? $decodedOverride : null;
                }

                $group = (new SectionDeliveryGroup)->forceFill([
                    'id' => $groupId,
                    'section_id' => $sectionId,
                    'name' => $sectionRow->joined_group_name,
                    'expected_count' => (int) $sectionRow->joined_group_expected_count,
                    'modality' => $sectionRow->joined_group_modality,
                    'delivery_override' => $rawOverride,
                    'state' => $sectionRow->joined_group_state,
                ]);
                $group->exists = true;
                $groupsBySectionId[$sectionId][] = $group;
            }
        }

        $sections = new EloquentCollection(array_values($sectionsById));

        foreach ($sections as $section) {
            $section->setRelation('deliveryGroups', new EloquentCollection(
                $groupsBySectionId[(int) $section->id] ?? [],
            ));
        }

        $sectionsByOfferingId = $sections->groupBy(fn (Section $section): int => (int) $section->term_offering_id);

        foreach ($offerings as $offering) {
            $offering->setRelation('sections', new EloquentCollection(
                $sectionsByOfferingId->get((int) $offering->id, collect())->all(),
            ));

            if ($offering->joined_entry_id === null) {
                $offering->setRelation('curriculumEntry', null);

                continue;
            }

            $entry = (new CurriculumEntry)->forceFill([
                'id' => (int) $offering->joined_entry_id,
                'course_specification_id' => $offering->joined_spec_id !== null ? (int) $offering->joined_spec_id : null,
            ]);
            $entry->exists = true;

            if ($offering->joined_spec_id !== null) {
                $specId = (int) $offering->joined_spec_id;
                $spec = (new CourseSpecification)->forceFill([
                    'id' => $specId,
                    'course_id' => $offering->joined_course_id !== null ? (int) $offering->joined_course_id : null,
                    'state' => $offering->joined_spec_state,
                    'same_faculty_default' => $offering->joined_spec_same_faculty_default !== null
                        ? (bool) $offering->joined_spec_same_faculty_default
                        : null,
                ]);
                $spec->exists = true;

                if ($offering->joined_course_id !== null) {
                    $course = (new Course)->forceFill([
                        'id' => (int) $offering->joined_course_id,
                        'code' => $offering->joined_course_code,
                    ]);
                    $course->exists = true;
                    $spec->setRelation('course', $course);
                } else {
                    $spec->setRelation('course', null);
                }

                $spec->setRelation('components', new EloquentCollection(
                    array_values($componentsBySpecId[$specId] ?? []),
                ));
                $entry->setRelation('courseSpecification', $spec);
            } else {
                $entry->setRelation('courseSpecification', null);
            }

            $offering->setRelation('curriculumEntry', $entry);
        }

        return $offerings;
    }

    /**
     * @return EloquentCollection<int, Section>
     */
    private function sectionsForOffering(TermOffering $offering): EloquentCollection
    {
        if ($offering->relationLoaded('sections')) {
            return $offering->sections;
        }

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
        if ($section->relationLoaded('deliveryGroups')) {
            return $section->deliveryGroups;
        }

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

        if ($specification->relationLoaded('components')) {
            return $specification->components;
        }

        return CourseComponent::query()
            ->whereBelongsTo($specification)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, EloquentCollection<int, SchedulingCommitment>>  $commitmentsBySection
     * @param  array<int, list<array{faculty_user_id:int<0, max>,qualification_id:int,term_load_override_id:int|null,max_allowed_units:string|null}>>  $facultyLoadOptionsByCourse
     * @param  EloquentCollection<int, Room>  $activeRooms
     * @param  Collection<string, EloquentCollection<int, Room>>  $activeRoomsByType
     * @param  Collection<int, Room>  $activeRoomsById
     * @param  list<array{id:int,source_type:string,scope_type:string,room_id:int|null,faculty_user_id:int|null,event_type:string,process_key:string|null,day_of_week:int,starts_at:string,ends_at:string}>  $blockingCalendarBlocks
     * @param  Collection<int, array{day_of_week:int,starts_at:string,ends_at:string}>  $gridRows
     * @return array<string, mixed>
     */
    private function attributesForDemand(
        User $actor,
        Term $term,
        TermOffering $offering,
        Section $section,
        SectionDeliveryGroup $group,
        CourseComponent $component,
        Collection $commitmentsBySection,
        array $facultyLoadOptionsByCourse,
        EloquentCollection $activeRooms,
        Collection $activeRoomsByType,
        Collection $activeRoomsById,
        int $schedulingWindowCount,
        array $blockingCalendarBlocks,
        Collection $gridRows,
        int $slotMinutes,
    ): array {
        $specification = $offering->courseSpecification();
        $course = $specification?->course;
        $modality = filled($group->modality) ? (string) $group->modality : (string) $offering->modality;
        $meetingPattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);
        $durationMinutes = $meetingPattern['duration_minutes']
            ?? max(1, (int) round((float) $component->weekly_contact_hours * 60));
        $meetingCount = $meetingPattern['count'] ?? 1;
        $commitments = $commitmentsBySection->get((int) $section->id, collect());
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

        $roomTypeRequirement = self::resolveRoomType($offering->room_type_override, $component);
        $facultyLoadOptions = $course instanceof Course
            ? ($facultyLoadOptionsByCourse[(int) $course->id] ?? [])
            : [];
        $suitableRoomCount = $this->suitableRoomCount($modality, $roomTypeRequirement, (int) $group->expected_count, $activeRooms, $activeRoomsByType);
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
            activeRoomsById: $activeRoomsById,
            gridRows: $gridRows,
            slotMinutes: $slotMinutes,
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function demandChanged(SchedulingDemand $demand, array $attributes): bool
    {
        $scalarFields = [
            'demand_key',
            'required_duration_minutes',
            'meeting_count',
            'modality',
            'fixed_faculty_user_id',
            'fixed_room_id',
            'fixed_day_of_week',
            'fixed_start_time',
            'validation_state',
            'generated_by',
        ];

        foreach ($scalarFields as $field) {
            if ($demand->getAttribute($field) != ($attributes[$field] ?? null)) {
                return true;
            }
        }

        return $demand->source_snapshot != $attributes['source_snapshot']
            || $demand->readiness_findings != $attributes['readiness_findings'];
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
     * @param  list<int>  $courseIds
     * @return array<int, list<array{faculty_user_id:int<0, max>,qualification_id:int,term_load_override_id:int|null,max_allowed_units:string|null}>>
     */
    private function precomputedFacultyLoadOptionsByCourse(Term $term, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $rows = FacultyQualification::query()
            ->select([
                'faculty_qualifications.*',
                'ftlo.id as joined_override_id',
                'ftlo.default_max_units_snapshot as joined_override_default_units',
                'ftlo.approved_overload_units as joined_override_overload_units',
            ])
            ->leftJoin('faculty_term_load_overrides as ftlo', function ($join) use ($term): void {
                $join->on('ftlo.faculty_user_id', '=', 'faculty_qualifications.faculty_user_id')
                    ->where('ftlo.term_id', '=', $term->id)
                    ->where('ftlo.is_active', '=', true);
            })
            ->whereIn('faculty_qualifications.course_id', $courseIds)
            ->where('faculty_qualifications.is_active', true)
            ->orderBy('faculty_qualifications.faculty_user_id')
            ->orderBy('faculty_qualifications.id')
            ->orderBy('ftlo.id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $byCourseAndQual = [];

        foreach ($rows as $row) {
            $courseId = (int) $row->course_id;
            $qualId = (int) $row->id;
            $overrideId = $row->joined_override_id !== null ? (int) $row->joined_override_id : null;
            $maxAllowedUnits = $overrideId !== null
                ? ((float) $row->joined_override_default_units + (float) $row->joined_override_overload_units)
                : ($term->default_max_units !== null ? (float) $term->default_max_units : null);

            $byCourseAndQual[$courseId][$qualId] = [
                'faculty_user_id' => (int) $row->faculty_user_id,
                'qualification_id' => $qualId,
                'term_load_override_id' => $overrideId,
                'max_allowed_units' => $this->decimalString($maxAllowedUnits),
            ];
        }

        $result = [];

        foreach ($byCourseAndQual as $courseId => $options) {
            $result[$courseId] = array_values($options);
        }

        return $result;
    }

    private function decimalString(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 2, '.', '');
    }

    /**
     * @param  EloquentCollection<int, Room>  $activeRooms
     * @param  Collection<string, EloquentCollection<int, Room>>  $activeRoomsByType
     */
    private function suitableRoomCount(
        string $modality,
        mixed $roomTypeRequirement,
        int $expectedCount,
        EloquentCollection $activeRooms,
        Collection $activeRoomsByType,
    ): int {
        if ($modality !== TermOffering::ModalityFaceToFace) {
            return 0;
        }

        $pool = filled($roomTypeRequirement)
            ? $activeRoomsByType->get((string) $roomTypeRequirement, collect())
            : $activeRooms;

        return $pool
            ->filter(fn (Room $room): bool => (int) $room->capacity >= $expectedCount)
            ->count();
    }

    /**
     * @return list<array{id:int,source_type:string,scope_type:string,room_id:int|null,faculty_user_id:int|null,event_type:string,process_key:string|null,day_of_week:int,starts_at:string,ends_at:string}>
     */
    private function recurringBlockingCalendarBlocks(Term $term, ?TermCalendarPackage $package): array
    {
        $legacyBlocks = CalendarEvent::query()
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
                'source_type' => 'calendar_event',
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

        $packageBlocks = [];

        foreach ($package?->teachingGridRows ?? [] as $row) {
            foreach ($row->breaks ?? [] as $break) {
                if (! is_array($break) || blank($break['starts_at'] ?? null) || blank($break['ends_at'] ?? null)) {
                    continue;
                }

                $packageBlocks[] = [
                    'id' => (int) $row->id,
                    'source_type' => 'term_teaching_grid_row',
                    'scope_type' => CalendarEvent::ScopeInstitution,
                    'room_id' => null,
                    'faculty_user_id' => null,
                    'event_type' => 'TeachingGridBreak',
                    'process_key' => null,
                    'day_of_week' => (int) $row->day_of_week,
                    'starts_at' => $this->timeString($break['starts_at']),
                    'ends_at' => $this->timeString($break['ends_at']),
                ];
            }
        }

        return [...$packageBlocks, ...$legacyBlocks];
    }

    /**
     * @param  array{fixed_faculty_user_id:int|null,fixed_room_id:int|null,fixed_day_of_week:int|null,fixed_start_time:string|null}  $fixedInputs
     * @param  list<array{faculty_user_id:int<0, max>,qualification_id:int,term_load_override_id:int|null,max_allowed_units:string|null}>  $facultyLoadOptions
     * @param  list<array{id:int,source_type:string,scope_type:string,room_id:int|null,faculty_user_id:int|null,event_type:string,process_key:string|null,day_of_week:int,starts_at:string,ends_at:string}>  $blockingCalendarBlocks
     * @param  Collection<int, Room>  $activeRoomsById
     * @param  Collection<int, array{day_of_week:int,starts_at:string,ends_at:string}>  $gridRows
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
        Collection $activeRoomsById,
        Collection $gridRows,
        int $slotMinutes,
    ): array {
        $findings = [];

        if ($term->state !== Term::StateActive) {
            $findings[] = $this->finding('term_not_active', 'blocking', 'term', $term->id, 'The term must be active before solver readiness can pass.');
        }

        if ($schedulingWindowCount === 0) {
            $findings[] = $this->finding('missing_active_core_calendar', 'blocking', 'term', $term->id, 'Activate a valid approved core calendar for this exact Term before demand preparation.');
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

            if ($fixedInputs['fixed_room_id'] !== null && ! $this->fixedRoomIsSuitable($fixedInputs['fixed_room_id'], $roomTypeRequirement, (int) $group->expected_count, $activeRoomsById)) {
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
        $dayRow = $gridRows->firstWhere('day_of_week', $fixedDay);
        $schedulingDays = $gridRows->pluck('day_of_week')->all();
        $dayStart = $this->minutesFromTime($dayRow['starts_at'] ?? null);
        $dayEnd = $this->minutesFromTime($dayRow['ends_at'] ?? null);

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

        $conflictingBlock = $this->conflictingCalendarBlock($fixedInputs, $blockingCalendarBlocks, $durationMinutes);

        if ($conflictingBlock !== null) {
            $findings[] = $this->finding('fixed_time_conflicts_with_calendar_block', 'blocking', $conflictingBlock['source_type'], $conflictingBlock['id'], 'The fixed day/time override overlaps an active recurring scheduling block.');
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

    /**
     * @param  Collection<int, Room>  $activeRoomsById
     */
    private function fixedRoomIsSuitable(
        int $roomId,
        ?string $roomTypeRequirement,
        int $expectedCount,
        Collection $activeRoomsById,
    ): bool {
        $room = $activeRoomsById->get($roomId);

        if (! $room instanceof Room || (int) $room->capacity < $expectedCount) {
            return false;
        }

        if (filled($roomTypeRequirement) && (string) $room->room_type !== (string) $roomTypeRequirement) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{fixed_faculty_user_id:int|null,fixed_room_id:int|null,fixed_day_of_week:int|null,fixed_start_time:string|null}  $fixedInputs
     * @param  list<array{id:int,source_type:string,scope_type:string,room_id:int|null,faculty_user_id:int|null,event_type:string,process_key:string|null,day_of_week:int,starts_at:string,ends_at:string}>  $blockingCalendarBlocks
     * @return array{id:int,source_type:string,scope_type:string,room_id:int|null,faculty_user_id:int|null,event_type:string,process_key:string|null,day_of_week:int,starts_at:string,ends_at:string}|null
     */
    private function conflictingCalendarBlock(array $fixedInputs, array $blockingCalendarBlocks, int $durationMinutes): ?array
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
                return $block;
            }
        }

        return null;
    }

    /**
     * @param  array{id:int,source_type:string,scope_type:string,room_id:int|null,faculty_user_id:int|null,event_type:string,process_key:string|null,day_of_week:int,starts_at:string,ends_at:string}  $block
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
