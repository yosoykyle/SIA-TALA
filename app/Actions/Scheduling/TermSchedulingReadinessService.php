<?php

namespace App\Actions\Scheduling;

use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class TermSchedulingReadinessService
{
    /**
     * @return array{
     *     is_ready: bool,
     *     missing_term_fields: list<string>,
     *     section_issues: list<array{section_id:int, section_name:string, missing_fields:list<string>, has_curriculum_demand:bool}>,
     *     delivery_group_issues: list<array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, missing_fields:list<string>}>,
     *     faculty_input_issues: list<array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, subject_id:int, subject_code:?string, missing_inputs:list<string>, eligible_faculty_count:int, schedulable_faculty_count:int}>,
     *     room_input_issues: list<array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, course_component_id:int|null, component_type:string|null, missing_inputs:list<string>}>,
     *     room_catalog_mode: string
     * }
     */
    public function evaluateTerm(Term $term): array
    {
        $termOfferings = $this->termOfferings($term);
        $missingTermFields = $this->missingTermFields($term);
        $sectionIssues = $this->sectionIssues($termOfferings);
        $deliveryGroupIssues = $this->deliveryGroupIssues($termOfferings);
        $facultyInputIssues = $this->facultyInputIssues($term, $termOfferings);
        $roomInputIssues = $this->roomInputIssues($termOfferings);

        return [
            'is_ready' => $missingTermFields === []
                && $sectionIssues === []
                && $deliveryGroupIssues === []
                && $facultyInputIssues === []
                && $roomInputIssues === [],
            'missing_term_fields' => $missingTermFields,
            'section_issues' => $sectionIssues,
            'delivery_group_issues' => $deliveryGroupIssues,
            'faculty_input_issues' => $facultyInputIssues,
            'room_input_issues' => $roomInputIssues,
            'room_catalog_mode' => 'term_offerings + sections + section_delivery_groups; active rooms filtered by type and capacity',
        ];
    }

    /**
     * @return EloquentCollection<int, TermOffering>
     */
    private function termOfferings(Term $term): EloquentCollection
    {
        return TermOffering::query()
            ->whereBelongsTo($term)
            ->where('state', TermOffering::StatePendingScheduling)
            ->with([
                'curriculumEntry.courseSpecification.course',
                'curriculumEntry.courseSpecification.components',
                'sections.deliveryGroups',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function missingTermFields(Term $term): array
    {
        $missingFields = collect(['label', 'type', 'starts_on', 'ends_on', 'state'])
            ->filter(fn (string $field): bool => blank($term->{$field}))
            ->values()
            ->all();

        if ($term->state !== Term::StateActive) {
            $missingFields[] = 'active_state';
        }

        if ((int) $term->scheduling_slot_minutes < 1) {
            $missingFields[] = 'scheduling_slot_minutes';
        }

        if ($this->activeSchedulingWindowCount($term) === 0) {
            $missingFields[] = 'active_scheduling_window';
        }

        return array_values(array_unique($missingFields));
    }

    private function activeSchedulingWindowCount(Term $term): int
    {
        return CalendarEvent::query()
            ->whereBelongsTo($term)
            ->where('event_type', CalendarEvent::TypeWindow)
            ->where('process_key', CalendarEvent::ProcessScheduling)
            ->where('state', CalendarEvent::StateActive)
            ->whereNotNull('start_at')
            ->whereNotNull('end_at')
            ->count();
    }

    /**
     * @param  EloquentCollection<int, TermOffering>  $termOfferings
     * @return list<array{section_id:int, section_name:string, missing_fields:list<string>, has_curriculum_demand:bool}>
     */
    private function sectionIssues(EloquentCollection $termOfferings): array
    {
        if ($termOfferings->isEmpty()) {
            return [[
                'section_id' => 0,
                'section_name' => 'No pending term offerings',
                'missing_fields' => ['term_offerings'],
                'has_curriculum_demand' => false,
            ]];
        }

        return $termOfferings
            ->flatMap(function (TermOffering $offering): array {
                if ($offering->sections->isEmpty()) {
                    return [[
                        'section_id' => 0,
                        'section_name' => 'Term offering '.$offering->id,
                        'missing_fields' => ['sections'],
                        'has_curriculum_demand' => $this->offeringHasCurriculumDemand($offering),
                    ]];
                }

                return $offering->sections
                    ->map(fn (Section $section): ?array => $this->issueForSection($offering, $section))
                    ->filter()
                    ->values()
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @return array{section_id:int, section_name:string, missing_fields:list<string>, has_curriculum_demand:bool}|null
     */
    private function issueForSection(TermOffering $offering, Section $section): ?array
    {
        $missingFields = [];

        foreach (['term_offering_id', 'code', 'capacity', 'state'] as $field) {
            if (blank($section->{$field})) {
                $missingFields[] = $field;
            }
        }

        if ((int) $section->capacity < 1) {
            $missingFields[] = 'section_capacity';
        }

        if (in_array($section->state, [Section::StateClosed, Section::StateCancelled], true)) {
            $missingFields[] = 'section_schedulable_state';
        }

        if ($missingFields === []) {
            return null;
        }

        return [
            'section_id' => (int) $section->id,
            'section_name' => (string) $section->code,
            'missing_fields' => array_values(array_unique($missingFields)),
            'has_curriculum_demand' => $this->offeringHasCurriculumDemand($offering),
        ];
    }

    private function offeringHasCurriculumDemand(TermOffering $offering): bool
    {
        $specification = $offering->courseSpecification();

        return $specification instanceof CourseSpecification
            && $specification->state === CourseSpecification::StateActive
            && $specification->components->isNotEmpty();
    }

    /**
     * @param  EloquentCollection<int, TermOffering>  $termOfferings
     * @return list<array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, missing_fields:list<string>}>
     */
    private function deliveryGroupIssues(EloquentCollection $termOfferings): array
    {
        return $termOfferings
            ->flatMap(fn (TermOffering $offering): array => $offering->sections
                ->flatMap(fn (Section $section): array => $this->deliveryGroupIssuesForSection($section))
                ->values()
                ->all())
            ->values()
            ->all();
    }

    /**
     * @return list<array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, missing_fields:list<string>}>
     */
    private function deliveryGroupIssuesForSection(Section $section): array
    {
        if ($section->deliveryGroups->isEmpty()) {
            return [[
                'section_id' => (int) $section->id,
                'section_name' => (string) $section->code,
                'section_delivery_group_id' => null,
                'delivery_group_name' => null,
                'missing_fields' => ['section_delivery_groups'],
            ]];
        }

        $issues = [];

        if ($section->deliveryGroups->where('state', SectionDeliveryGroup::StateReady)->isEmpty()) {
            $issues[] = [
                'section_id' => (int) $section->id,
                'section_name' => (string) $section->code,
                'section_delivery_group_id' => null,
                'delivery_group_name' => null,
                'missing_fields' => ['ready_section_delivery_group'],
            ];
        }

        foreach ($section->deliveryGroups as $group) {
            $missingFields = $this->missingDeliveryGroupFields($section, $group);

            if ($missingFields !== []) {
                $issues[] = [
                    'section_id' => (int) $section->id,
                    'section_name' => (string) $section->code,
                    'section_delivery_group_id' => (int) $group->id,
                    'delivery_group_name' => (string) $group->name,
                    'missing_fields' => $missingFields,
                ];
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function missingDeliveryGroupFields(Section $section, SectionDeliveryGroup $group): array
    {
        $missingFields = [];

        foreach (['section_id', 'name', 'expected_count', 'modality', 'state'] as $field) {
            if (blank($group->{$field})) {
                $missingFields[] = $field;
            }
        }

        if ($group->state !== SectionDeliveryGroup::StateReady) {
            $missingFields[] = 'ready_state';
        }

        if ((int) $group->expected_count > (int) $section->capacity) {
            $missingFields[] = 'expected_count_above_section_capacity';
        }

        return array_values(array_unique($missingFields));
    }

    /**
     * @param  EloquentCollection<int, TermOffering>  $termOfferings
     * @return list<array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, subject_id:int, subject_code:?string, missing_inputs:list<string>, eligible_faculty_count:int, schedulable_faculty_count:int}>
     */
    private function facultyInputIssues(Term $term, EloquentCollection $termOfferings): array
    {
        return collect($this->demandSources($termOfferings))
            ->map(function (array $source) use ($term): ?array {
                /** @var Course|null $course */
                $course = $source['course'];

                if (! $course instanceof Course) {
                    return $this->facultyIssue($source, ['course'], 0, 0);
                }

                $qualifications = FacultyQualification::query()
                    ->whereBelongsTo($course)
                    ->where('is_active', true)
                    ->orderBy('faculty_user_id')
                    ->get(['id', 'faculty_user_id']);

                $missingInputs = [];

                if ($qualifications->isEmpty()) {
                    $missingInputs[] = 'active_faculty_qualification';
                }

                $schedulableFacultyCount = $this->schedulableFacultyCount($term, $qualifications);

                if ($qualifications->isNotEmpty() && $schedulableFacultyCount < $qualifications->count()) {
                    $missingInputs[] = 'missing_default_faculty_load';
                }

                if ($missingInputs === []) {
                    return null;
                }

                return $this->facultyIssue($source, $missingInputs, $qualifications->count(), $schedulableFacultyCount);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  EloquentCollection<int, FacultyQualification>  $qualifications
     */
    private function schedulableFacultyCount(Term $term, EloquentCollection $qualifications): int
    {
        if ($qualifications->isEmpty()) {
            return 0;
        }

        if ($term->default_max_units !== null) {
            return $qualifications->count();
        }

        return FacultyTermLoadOverride::query()
            ->whereBelongsTo($term)
            ->where('is_active', true)
            ->whereIn('faculty_user_id', $qualifications->pluck('faculty_user_id')->all())
            ->count();
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $missingInputs
     * @return array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, subject_id:int, subject_code:?string, missing_inputs:list<string>, eligible_faculty_count:int, schedulable_faculty_count:int}
     */
    private function facultyIssue(array $source, array $missingInputs, int $eligibleFacultyCount, int $schedulableFacultyCount): array
    {
        /** @var Section $section */
        $section = $source['section'];
        /** @var SectionDeliveryGroup $group */
        $group = $source['group'];
        /** @var Course|null $course */
        $course = $source['course'];

        return [
            'section_id' => (int) $section->id,
            'section_name' => (string) $section->code,
            'section_delivery_group_id' => (int) $group->id,
            'delivery_group_name' => (string) $group->name,
            'subject_id' => $course instanceof Course ? (int) $course->id : 0,
            'subject_code' => $course?->code,
            'missing_inputs' => array_values(array_unique($missingInputs)),
            'eligible_faculty_count' => $eligibleFacultyCount,
            'schedulable_faculty_count' => $schedulableFacultyCount,
        ];
    }

    /**
     * @param  EloquentCollection<int, TermOffering>  $termOfferings
     * @return list<array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, course_component_id:int|null, component_type:string|null, missing_inputs:list<string>}>
     */
    private function roomInputIssues(EloquentCollection $termOfferings): array
    {
        return collect($this->demandSources($termOfferings))
            ->map(function (array $source): ?array {
                /** @var TermOffering $offering */
                $offering = $source['offering'];
                /** @var Section $section */
                $section = $source['section'];
                /** @var SectionDeliveryGroup $group */
                $group = $source['group'];
                /** @var CourseComponent $component */
                $component = $source['component'];

                $modality = filled($group->modality) ? (string) $group->modality : (string) $offering->modality;

                if ($modality !== TermOffering::ModalityFaceToFace) {
                    return null;
                }

                $roomTypeRequirement = $offering->room_type_override ?: $component->room_type_default;
                $missingInputs = [];

                if (blank($roomTypeRequirement)) {
                    $missingInputs[] = 'missing_room_type_requirement';
                } elseif (! $this->hasSuitableRoom((string) $roomTypeRequirement, (int) $group->expected_count)) {
                    $missingInputs[] = 'missing_suitable_room';
                }

                if ($missingInputs === []) {
                    return null;
                }

                return [
                    'section_id' => (int) $section->id,
                    'section_name' => (string) $section->code,
                    'section_delivery_group_id' => (int) $group->id,
                    'delivery_group_name' => (string) $group->name,
                    'course_component_id' => (int) $component->id,
                    'component_type' => (string) $component->component_type,
                    'missing_inputs' => $missingInputs,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function hasSuitableRoom(string $roomTypeRequirement, int $expectedCount): bool
    {
        return Room::query()
            ->where('is_active', true)
            ->where('room_type', $roomTypeRequirement)
            ->where('capacity', '>=', $expectedCount)
            ->exists();
    }

    /**
     * @param  EloquentCollection<int, TermOffering>  $termOfferings
     * @return list<array{offering:TermOffering,section:Section,group:SectionDeliveryGroup,component:CourseComponent,course:Course|null}>
     */
    private function demandSources(EloquentCollection $termOfferings): array
    {
        return $termOfferings
            ->flatMap(function (TermOffering $offering): array {
                $specification = $offering->courseSpecification();

                if (! $specification instanceof CourseSpecification) {
                    return [];
                }

                return $offering->sections
                    ->flatMap(fn (Section $section): array => $section->deliveryGroups
                        ->where('state', SectionDeliveryGroup::StateReady)
                        ->flatMap(fn (SectionDeliveryGroup $group): array => $specification->components
                            ->map(fn (CourseComponent $component): array => [
                                'offering' => $offering,
                                'section' => $section,
                                'group' => $group,
                                'component' => $component,
                                'course' => $specification->course,
                            ])
                            ->values()
                            ->all())
                        ->values()
                        ->all())
                    ->values()
                    ->all();
            })
            ->values()
            ->all();
    }
}
