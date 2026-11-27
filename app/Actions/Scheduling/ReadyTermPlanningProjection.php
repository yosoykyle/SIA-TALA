<?php

namespace App\Actions\Scheduling;

use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\Enrollment;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\SchedulingCommitment;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Illuminate\Support\Collection;

final class ReadyTermPlanningProjection
{
    public function __construct(
        private readonly ?FacultyAvailabilityStatusProjection $availabilityProjection = null,
    ) {}

    /**
     * Evaluate whether a term satisfies all calendar, class, teacher, and room prerequisites for scheduling.
     *
     * @return array{
     *     ready: bool,
     *     term_id: int,
     *     blockers: list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>,
     *     advisories: list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>
     * }
     */
    public function forTerm(Term $term): array
    {
        $blockers = [];
        $advisories = [];

        $availabilityStatus = ($this->availabilityProjection ?? app(FacultyAvailabilityStatusProjection::class))->forTerm($term, includeOfficialAssignments: false);
        /** @var TermCalendarPackage|null $activePackage */
        $activePackage = $availabilityStatus['active_calendar_package'];

        if (! $activePackage instanceof TermCalendarPackage) {
            $blockers[] = $this->blocker('calendar_not_active', 'Term calendar', 'Registrar', 'No active term calendar is set for this term.', 'Open Calendar and activate the term calendar.', 'Complete the draft term calendar and activate it.');
        }

        $recurringCalendarBlocks = $activePackage instanceof TermCalendarPackage
            ? CalendarEvent::query()
                ->where('term_id', $term->id)
                ->recurringSchedulingBlocks()
                ->where('state', CalendarEvent::StateActive)
                ->orderBy('day_of_week')
                ->orderBy('starts_at')
                ->get(['id', 'scope_type', 'room_id', 'faculty_user_id', 'day_of_week', 'starts_at', 'ends_at'])
            : collect();

        $classes = Section::query()
            ->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $term->id))
            ->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])
            ->with([
                'calendarPackage.teachingGridRows',
                'calendarPackage.datedExceptions',
                'cohorts',
                'courseRevision.course',
                'courseRevision.components',
                'termOffering.curriculumEntry.courseSpecification.course',
                'termOffering.curriculumEntry.courseSpecification.components',
                'deliveryGroups.schedulingDemands',
            ])
            ->orderBy('code')
            ->get()
            ->filter(fn (Section $section): bool => $activePackage instanceof TermCalendarPackage
                && $section->calendarPackage->isActivatedCoreSource()
                && $section->calendarPackage->coreSignature() === $activePackage->coreSignature());

        $hasPublishedTimetable = PublishedTimetableVersion::query()
            ->where('term_id', $term->id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->exists();

        $hasOfficialEnrollments = $hasPublishedTimetable && (
            CourseEnrollment::query()
                ->where('status', CourseEnrollment::StatusActive)
                ->where('is_current', true)
                ->whereHas('enrollment', fn ($query) => $query->where('term_id', $term->id))
                ->exists()
            || Enrollment::query()
                ->where('term_id', $term->id)
                ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
                ->exists()
        );

        if ($hasPublishedTimetable && $hasOfficialEnrollments) {
            $confirmedClasses = $classes->whereNotNull('confirmed_at')->values();
            if ($confirmedClasses->isNotEmpty()) {
                $classes = $confirmedClasses;
            }
        }

        if ($classes->isEmpty() || $classes->contains(fn (Section $section): bool => $section->confirmed_at === null)) {
            $blockers[] = $this->blocker('classes_not_confirmed', 'Classes & sections', 'Registrar', 'All active classes must be confirmed before generating a timetable.', 'Review each draft class, check its student sections and capacity, and confirm it.', 'Finish setting up each draft class in Classes & sections and confirm it.');
        }

        $requiredCourses = [];
        foreach ($classes as $section) {
            $specification = $section->courseRevision ?? $section->termOffering?->courseSpecification();
            if (! $specification instanceof CourseSpecification
                || $specification->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged
                || $specification->components->isEmpty()) {
                continue;
            }

            $hasGroups = $section->deliveryGroups
                ->whereNotIn('state', [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled])
                ->isNotEmpty();
            if (! $hasGroups) {
                continue;
            }

            $course = $specification->course;
            if ($course instanceof Course) {
                $requiredCourses[$course->id] = (string) $course->code;
            }
        }

        $qualifiedFacultyByCourse = [];

        if ($requiredCourses !== []) {
            $activeQualifications = FacultyQualification::query()
                ->whereIn('course_id', array_keys($requiredCourses))
                ->where('is_active', true)
                ->with('faculty')
                ->orderBy('faculty_user_id')
                ->get();

            foreach ($activeQualifications as $qualification) {
                if (! $qualification->faculty instanceof User || $qualification->faculty->status !== User::StatusActive) {
                    continue;
                }

                $qualifiedFacultyByCourse[(int) $qualification->course_id][] = $qualification->faculty;
            }

            $missingCourseCodes = [];
            foreach ($requiredCourses as $courseId => $courseCode) {
                if (($qualifiedFacultyByCourse[(int) $courseId] ?? []) === []) {
                    $missingCourseCodes[] = $courseCode;
                }
            }

            if ($missingCourseCodes !== []) {
                sort($missingCourseCodes);
                $blockers[] = $this->blocker(
                    'faculty_qualifications_missing',
                    'Faculty & rooms',
                    'Registrar',
                    'Assign at least one active qualified teacher for: '.implode(', ', $missingCourseCodes).'.',
                    'Open Faculty & rooms to assign qualified teachers for each offered subject.',
                    'Assign active qualified teachers for the listed subjects, then check readiness again.',
                );
            }
        }

        $allFacultyRows = $availabilityStatus['all_faculty_rows'];
        $zeroUnitOverrideFacultyIds = collect($allFacultyRows)
            ->filter(fn (array $row): bool => (bool) ($row['registrar_not_teaching'] ?? false))
            ->keys()
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true])
            ->all();

        $commitmentsBySection = SchedulingCommitment::query()
            ->where('term_id', $term->id)
            ->whereNotNull('section_id')
            ->with('faculty')
            ->get()
            ->groupBy(fn (SchedulingCommitment $commitment): int => (int) $commitment->section_id);

        $seenFacultyBlockers = [];

        foreach ($classes as $section) {
            $specification = $section->courseRevision ?? $section->termOffering?->courseSpecification();
            if (! $specification instanceof CourseSpecification
                || $specification->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged
                || $specification->components->isEmpty()) {
                continue;
            }

            $course = $specification->course;
            if (! $course instanceof Course) {
                continue;
            }

            $groups = $section->deliveryGroups->whereNotIn('state', [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled]);
            if ($groups->isEmpty()) {
                continue;
            }

            $sectionCommitments = $commitmentsBySection->get((int) $section->id, collect());
            $unscopedCommitments = $sectionCommitments
                ->filter(function (SchedulingCommitment $commitment): bool {
                    $scope = GenerateSchedulingDemand::parseCommitmentScope($commitment);

                    return $scope['demand_id'] === null && $scope['component_id'] === null;
                })
                ->values();
            $committedFacultyIds = $unscopedCommitments
                ->pluck('faculty_user_id')
                ->filter(fn (mixed $id): bool => $id !== null)
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();
            $sectionFixedFacultyId = $committedFacultyIds->count() === 1 ? (int) $committedFacultyIds->first() : null;
            $qualifiedTeachers = $qualifiedFacultyByCourse[(int) $course->id] ?? [];

            if ($committedFacultyIds->count() > 1) {
                $blockerKey = "fixed-conflict:{$section->id}:{$course->id}";
                if (! isset($seenFacultyBlockers[$blockerKey])) {
                    $seenFacultyBlockers[$blockerKey] = true;
                    $blockers[] = $this->blocker(
                        'faculty_demand_uncovered',
                        'Faculty & rooms',
                        'Registrar',
                        "Class {$section->code} ({$course->code}) has conflicting fixed teacher assignments.",
                        "Keep only one fixed teacher assignment on {$section->code}.",
                        "Resolve conflicting fixed teacher assignments on {$section->code} before generating.",
                    );
                }
            }

            foreach ($groups as $group) {
                $demandsByComponent = $group->schedulingDemands->keyBy('course_component_id');
                $override = is_array($group->delivery_override) ? $group->delivery_override : [];
                $overrideFacultyId = $this->nullableInt($override['fixed_faculty_user_id'] ?? $override['faculty_user_id'] ?? null);
                $componentRequirements = [];

                foreach ($specification->components as $component) {
                    /** @var SchedulingDemand|null $demand */
                    $demand = $demandsByComponent->get($component->id);
                    $durationMinutes = $demand instanceof SchedulingDemand
                        ? max(30, (int) $demand->required_duration_minutes)
                        : $this->componentDurationMinutes($component);
                    $meetingPattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);
                    $meetingCount = $demand instanceof SchedulingDemand
                        ? max(1, (int) $demand->meeting_count)
                        : max(1, (int) ($meetingPattern['count'] ?? 1));

                    $componentCommitments = $sectionCommitments
                        ->filter(fn (SchedulingCommitment $commitment): bool => GenerateSchedulingDemand::commitmentAppliesToDemand(
                            $commitment,
                            $demand?->id !== null ? (int) $demand->id : null,
                            (int) $component->id,
                        ))
                        ->values();
                    $componentCommittedFacultyIds = $componentCommitments
                        ->pluck('faculty_user_id')
                        ->filter(fn (mixed $id): bool => $id !== null)
                        ->map(fn (mixed $id): int => (int) $id)
                        ->unique()
                        ->values();
                    $componentFixedFacultyId = $componentCommittedFacultyIds->count() === 1
                        ? (int) $componentCommittedFacultyIds->first()
                        : null;

                    $fixedFacultyId = $componentFixedFacultyId
                        ?? $sectionFixedFacultyId
                        ?? ($demand?->fixed_faculty_user_id !== null
                            ? (int) $demand->fixed_faculty_user_id
                            : $overrideFacultyId);
                    $sameFacultyRequired = (bool) ($section->termOffering?->same_faculty_override
                        ?? $specification->same_faculty_default
                        ?? $component->same_faculty);

                    if ($componentCommittedFacultyIds->count() > 1 && $sameFacultyRequired) {
                        $blockerKey = "fixed-conflict:{$section->id}:{$course->id}:{$component->id}";
                        if (! isset($seenFacultyBlockers[$blockerKey]) && ! isset($seenFacultyBlockers["fixed-conflict:{$section->id}:{$course->id}"])) {
                            $seenFacultyBlockers[$blockerKey] = true;
                            $blockers[] = $this->blocker(
                                'faculty_demand_uncovered',
                                'Faculty & rooms',
                                'Registrar',
                                "Class {$section->code} ({$course->code}) has conflicting fixed teacher assignments.",
                                "Keep only one fixed teacher assignment on {$section->code}.",
                                "Resolve conflicting fixed teacher assignments on {$section->code} before generating.",
                            );
                        }
                    }

                    $componentRequirements[] = [
                        'duration_minutes' => $durationMinutes,
                        'meeting_count' => $meetingCount,
                        'fixed_faculty_id' => $fixedFacultyId,
                        'same_faculty_required' => $sameFacultyRequired,
                    ];

                    if ($fixedFacultyId !== null) {
                        $blockerKey = "fixed:{$section->id}:{$course->id}:{$fixedFacultyId}:{$durationMinutes}";
                        if (! isset($seenFacultyBlockers[$blockerKey])) {
                            $fixedBlocker = $this->fixedFacultyBlocker(
                                section: $section,
                                course: $course,
                                fixedFacultyId: $fixedFacultyId,
                                durationMinutes: $durationMinutes,
                                activePackage: $activePackage,
                                allFacultyRows: $allFacultyRows,
                                zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                                recurringCalendarBlocks: $recurringCalendarBlocks,
                            );
                            if ($fixedBlocker !== null) {
                                $seenFacultyBlockers[$blockerKey] = true;
                                $blockers[] = $fixedBlocker;
                            }
                        }

                        continue;
                    }

                    if ($qualifiedTeachers === []) {
                        continue;
                    }

                    $hasConfirmedEligibleTeacher = collect($qualifiedTeachers)->contains(
                        fn (User $teacher): bool => $this->teacherIsUsableForDuration(
                            facultyId: (int) $teacher->id,
                            durationMinutes: $durationMinutes,
                            activePackage: $activePackage,
                            allFacultyRows: $allFacultyRows,
                            zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                            recurringCalendarBlocks: $recurringCalendarBlocks,
                        ),
                    );

                    if (! $hasConfirmedEligibleTeacher) {
                        $blockerKey = "uncovered:{$section->id}:{$course->id}:{$durationMinutes}";
                        if (! isset($seenFacultyBlockers[$blockerKey])) {
                            $seenFacultyBlockers[$blockerKey] = true;
                            $blockers[] = $this->uncoveredDemandBlocker(
                                section: $section,
                                course: $course,
                                qualifiedTeachers: $qualifiedTeachers,
                                durationMinutes: $durationMinutes,
                                activePackage: $activePackage,
                                allFacultyRows: $allFacultyRows,
                                zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                                recurringCalendarBlocks: $recurringCalendarBlocks,
                            );
                        }
                    }
                }

                $linkedRequirements = array_values(array_filter(
                    $componentRequirements,
                    fn (array $requirement): bool => $requirement['same_faculty_required'],
                ));
                $linkedMeetingTotal = array_sum(array_map(
                    fn (array $requirement): int => (int) $requirement['meeting_count'],
                    $linkedRequirements,
                ));

                if ($linkedMeetingTotal > 1) {
                    $this->appendSameFacultyBlockers(
                        section: $section,
                        course: $course,
                        linkedRequirements: $linkedRequirements,
                        qualifiedTeachers: $qualifiedTeachers,
                        activePackage: $activePackage,
                        allFacultyRows: $allFacultyRows,
                        zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                        recurringCalendarBlocks: $recurringCalendarBlocks,
                        seenFacultyBlockers: $seenFacultyBlockers,
                        blockers: $blockers,
                    );
                }

                $unlinkedMultiMeetingRequirements = array_values(array_filter(
                    $componentRequirements,
                    fn (array $requirement): bool => ! $requirement['same_faculty_required'] && $requirement['meeting_count'] > 1,
                ));

                foreach ($unlinkedMultiMeetingRequirements as $unlinkedRequirement) {
                    $this->appendUnlinkedMultiMeetingBlockers(
                        section: $section,
                        course: $course,
                        requirement: $unlinkedRequirement,
                        qualifiedTeachers: $qualifiedTeachers,
                        activePackage: $activePackage,
                        allFacultyRows: $allFacultyRows,
                        zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                        recurringCalendarBlocks: $recurringCalendarBlocks,
                        seenFacultyBlockers: $seenFacultyBlockers,
                        blockers: $blockers,
                    );
                }
            }
        }

        $activeRooms = Room::query()->with('features')->where('is_active', true)->get(['id', 'code', 'name', 'room_type', 'capacity']);
        $invalidCountLabels = [];

        if ($activeRooms->isEmpty()) {
            $blockers[] = $this->blocker('rooms_unavailable', 'Faculty & rooms', 'Registrar', 'No active room is available for on-campus classes.', 'Add or activate rooms with seat capacity and room type in Faculty & rooms.', 'Activate at least one room in Faculty & rooms and check readiness again.');
        } else {
            $unmatchedRoomNeeds = [];
            $roomTypeLabels = Room::typeOptions();

            foreach ($classes as $section) {
                $specification = $section->courseRevision ?? $section->termOffering?->courseSpecification();
                if (! $specification instanceof CourseSpecification
                    || $specification->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged
                    || $specification->components->isEmpty()) {
                    continue;
                }

                foreach ($section->deliveryGroups->whereNotIn('state', [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled]) as $group) {
                    if ($section->confirmed_at !== null
                        && ! $hasOfficialEnrollments
                        && $group->state === SectionDeliveryGroup::StateReady
                        && (int) $group->expected_count <= 0) {
                        $invalidCountLabels[] = $section->code.' ('.$group->name.')';
                    }

                    $modality = filled($group->modality)
                        ? (string) $group->modality
                        : (string) ($section->termOffering?->modality ?? $section->delivery_mode);

                    if ($modality !== TermOffering::ModalityFaceToFace) {
                        continue;
                    }

                    $expectedCount = max((int) $group->expected_count, 0);

                    foreach ($specification->components as $component) {
                        $roomType = GenerateSchedulingDemand::resolveRoomType($section->termOffering?->room_type_override, $component);
                        if (blank($roomType)) {
                            continue;
                        }

                        $requiredFeatures = collect($component->required_room_feature_keys ?? [])
                            ->map(fn (mixed $feature): string => mb_strtoupper(trim((string) $feature)))
                            ->filter()
                            ->unique()
                            ->values();

                        $hasMatch = $activeRooms->contains(function (Room $room) use ($roomType, $expectedCount, $requiredFeatures): bool {
                            if ($room->room_type !== (string) $roomType || (int) $room->capacity < $expectedCount) {
                                return false;
                            }

                            if ($requiredFeatures->isEmpty()) {
                                return true;
                            }

                            $roomFeatures = $room->features
                                ->pluck('feature_key')
                                ->map(fn (mixed $key): string => mb_strtoupper(trim((string) $key)))
                                ->filter()
                                ->unique();

                            return $requiredFeatures->diff($roomFeatures)->isEmpty();
                        });

                        if (! $hasMatch) {
                            $label = $roomTypeLabels[(string) $roomType] ?? (string) $roomType;
                            $unmatchedRoomNeeds[] = $section->code.' ('.$label.', '.$expectedCount.' seats)';
                            break;
                        }
                    }
                }
            }

            if ($unmatchedRoomNeeds !== []) {
                $preview = array_slice($unmatchedRoomNeeds, 0, 3);
                $remainder = count($unmatchedRoomNeeds) - count($preview);
                $blockers[] = $this->blocker(
                    'rooms_unsuitable',
                    'Faculty & rooms',
                    'Registrar',
                    'No active room matches the required type and seat capacity for: '.implode('; ', $preview).($remainder > 0 ? ' (+'.$remainder.' more)' : '').'.',
                    'Activate or update a matching room in Faculty & rooms.',
                    'Ensure an active room matches each face-to-face class room type and expected seat count.',
                );
            }
        }

        if ($invalidCountLabels !== []) {
            $preview = array_slice(array_values(array_unique($invalidCountLabels)), 0, 3);
            $blockers[] = $this->blocker(
                'delivery_group_expected_count_invalid',
                'Classes & sections',
                'Registrar',
                'Confirmed class delivery groups must have at least 1 expected student: '.implode(', ', $preview).'.',
                'Open Sections & classes and set a positive expected student count for each confirmed class.',
                'Update expected student counts to at least 1 and check readiness again.',
            );
        }

        if ($activePackage instanceof TermCalendarPackage && ! $hasOfficialEnrollments) {
            $confirmedSchedulableClasses = $classes
                ->filter(function (Section $section): bool {
                    if ($section->confirmed_at === null || $section->termOffering?->state === TermOffering::StateCancelled) {
                        return false;
                    }

                    $specification = $section->courseRevision ?? $section->termOffering?->courseSpecification();

                    return $specification instanceof CourseSpecification
                        && $specification->scheduling_treatment !== CourseSpecification::SchedulingExternallyArranged
                        && $specification->components->isNotEmpty()
                        && $section->deliveryGroups
                            ->whereNotIn('state', [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled])
                            ->isNotEmpty();
                })
                ->values();

            if ($confirmedSchedulableClasses->isNotEmpty()) {
                $this->appendMathematicalFeasibilityBlockers(
                    term: $term,
                    activePackage: $activePackage,
                    confirmedClasses: $confirmedSchedulableClasses,
                    qualifiedFacultyByCourse: $qualifiedFacultyByCourse,
                    allFacultyRows: $allFacultyRows,
                    zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                    activeRooms: $activeRooms,
                    commitmentsBySection: $commitmentsBySection,
                    recurringCalendarBlocks: $recurringCalendarBlocks,
                    blockers: $blockers,
                );
            }
        }

        if ($availabilityStatus['needs_grid_review_count'] > 0) {
            $reviewNames = collect($availabilityStatus['faculty_rows'])
                ->where('needs_grid_review', true)
                ->pluck('name')
                ->values()
                ->all();

            $advisories[] = $this->blocker(
                'faculty_availability_grid_review_needed',
                'Faculty & rooms',
                'Registrar',
                'Saved teaching times for '.implode(', ', $reviewNames).' fall outside the updated school teaching hours or overlap a break. Outside hours are excluded.',
                'Use Edit availability in Faculty & rooms if more hours are needed, or ask the teacher to review My Availability if open.',
                'Update teaching times via Edit availability in Faculty & rooms if more hours are needed.',
            );
        }

        if ($availabilityStatus['late_impact_count'] > 0) {
            $impactLabels = collect($availabilityStatus['unresolved_late_impacts'])
                ->map(function (array $impact): string {
                    $meetings = collect($impact['affected_meetings'] ?? [])
                        ->map(fn (array $meeting): string => trim(($meeting['course_code'] ?? '').' '.($meeting['section_code'] ?? '')))
                        ->filter()
                        ->unique()
                        ->implode(', ');

                    return $impact['faculty_name'].($meetings !== '' ? ' ('.$meetings.')' : '');
                })
                ->implode('; ');

            $advisories[] = $this->blocker(
                'faculty_late_availability_official_impact',
                'Official timetable',
                'Registrar',
                'Updated faculty availability conflicts with published classes for: '.$impactLabels.'. The current official timetable stays active until revised.',
                'Open Timetable to review and publish an official timetable revision.',
                'Use Revise official schedule on Timetable to reassign or reschedule affected meetings.',
            );
        }

        return [
            'ready' => $blockers === [],
            'term_id' => (int) $term->id,
            'blockers' => $blockers,
            'advisories' => $advisories,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
     * @return array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}|null
     */
    private function fixedFacultyBlocker(
        Section $section,
        Course $course,
        int $fixedFacultyId,
        int $durationMinutes,
        ?TermCalendarPackage $activePackage,
        array $allFacultyRows,
        array $zeroUnitOverrideFacultyIds = [],
        ?Collection $recurringCalendarBlocks = null,
    ): ?array {
        $row = $allFacultyRows[$fixedFacultyId] ?? null;
        $teacher = $row['faculty'] ?? User::query()->find($fixedFacultyId);
        $teacherName = $teacher instanceof User ? (string) $teacher->name : 'Faculty #'.$fixedFacultyId;
        /** @var FacultyAvailabilityDeclaration|null $declaration */
        $declaration = $row['declaration'] ?? null;

        if ($this->isRegistrarNotTeaching($fixedFacultyId, $allFacultyRows, $zeroUnitOverrideFacultyIds)) {
            return $this->blocker(
                'fixed_faculty_cannot_teach',
                'Faculty & rooms',
                'Registrar',
                "Class {$section->code} ({$course->code}) is assigned to {$teacherName}, who is marked Not teaching (0 units) for this term.",
                "Reassign {$section->code} or restore {$teacherName} in Faculty & rooms.",
                "Remove the fixed teacher assignment on {$section->code} or restore {$teacherName} in Faculty & rooms.",
            );
        }

        if (! $declaration instanceof FacultyAvailabilityDeclaration) {
            return $this->blocker(
                'fixed_faculty_availability_missing',
                'Faculty & rooms',
                'Faculty',
                "Class {$section->code} ({$course->code}) requires {$teacherName}, who has not responded yet for this term.",
                "Remind {$teacherName} to submit availability on My Availability, or change the fixed teacher assignment.",
                "Have {$teacherName} submit availability on My Availability or remove the fixed teacher assignment for {$section->code}.",
            );
        }

        if ($declaration->effectiveMode() === FacultyAvailabilityDeclaration::ModeCannotTeach) {
            return $this->blocker(
                'fixed_faculty_cannot_teach',
                'Faculty & rooms',
                'Registrar',
                "Class {$section->code} ({$course->code}) is assigned to {$teacherName}, who declared Unavailable this term.",
                "Reassign {$section->code} or update {$teacherName}'s availability using Edit availability in Faculty & rooms.",
                "Remove the fixed teacher assignment on {$section->code} or update {$teacherName}'s availability via Edit availability in Faculty & rooms.",
            );
        }

        if ($activePackage instanceof TermCalendarPackage
            && ! $this->declarationHasUsableTime($declaration, $activePackage, $durationMinutes, $recurringCalendarBlocks)) {
            return $this->blocker(
                'fixed_faculty_no_usable_time',
                'Faculty & rooms',
                'Registrar',
                "Class {$section->code} ({$course->code}) requires {$teacherName}, whose availability leaves no usable {$durationMinutes}-minute window inside school teaching hours.",
                "Use Edit availability in Faculty & rooms to expand {$teacherName}'s hours, or adjust the fixed teacher assignment.",
                "Update {$teacherName}'s availability via Edit availability in Faculty & rooms or change the fixed teacher assignment for {$section->code}.",
            );
        }

        return null;
    }

    /**
     * @param  list<User>  $qualifiedTeachers
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
     * @param  Collection<int, CalendarEvent>|null  $recurringCalendarBlocks
     * @return array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}
     */
    private function uncoveredDemandBlocker(
        Section $section,
        Course $course,
        array $qualifiedTeachers,
        int $durationMinutes,
        ?TermCalendarPackage $activePackage,
        array $allFacultyRows,
        array $zeroUnitOverrideFacultyIds = [],
        ?Collection $recurringCalendarBlocks = null,
    ): array {
        $teacherSummaries = collect($qualifiedTeachers)
            ->map(function (User $teacher) use ($durationMinutes, $activePackage, $allFacultyRows, $zeroUnitOverrideFacultyIds, $recurringCalendarBlocks): string {
                $teacherId = (int) $teacher->id;
                $row = $allFacultyRows[$teacherId] ?? null;
                /** @var FacultyAvailabilityDeclaration|null $declaration */
                $declaration = $row['declaration'] ?? null;

                if ($this->isRegistrarNotTeaching($teacherId, $allFacultyRows, $zeroUnitOverrideFacultyIds)) {
                    return $teacher->name.': Not teaching (Registrar)';
                }

                if (! $declaration instanceof FacultyAvailabilityDeclaration) {
                    return $teacher->name.': No response';
                }

                if ($declaration->effectiveMode() === FacultyAvailabilityDeclaration::ModeCannotTeach) {
                    return $teacher->name.': Cannot teach this Term';
                }

                if ($activePackage instanceof TermCalendarPackage
                    && ! $this->declarationHasUsableTime($declaration, $activePackage, $durationMinutes, $recurringCalendarBlocks)) {
                    return $teacher->name.': No usable grid time';
                }

                return $teacher->name.': Unavailable';
            })
            ->implode('; ');

        return $this->blocker(
            'faculty_demand_uncovered',
            'Faculty & rooms',
            'Faculty',
            "Class {$section->code} ({$course->code}) has no eligible teacher available ({$teacherSummaries}).",
            "Remind a qualified teacher for {$course->code} to submit availability on My Availability, or assign another qualified teacher.",
            "Ensure at least one qualified teacher for {$course->code} submits usable availability on My Availability.",
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
     * @param  Collection<int, CalendarEvent>|null  $recurringCalendarBlocks
     */
    private function teacherIsUsableForDuration(
        int $facultyId,
        int $durationMinutes,
        ?TermCalendarPackage $activePackage,
        array $allFacultyRows,
        array $zeroUnitOverrideFacultyIds = [],
        ?Collection $recurringCalendarBlocks = null,
    ): bool {
        if ($this->isRegistrarNotTeaching($facultyId, $allFacultyRows, $zeroUnitOverrideFacultyIds)) {
            return false;
        }

        $row = $allFacultyRows[$facultyId] ?? null;
        /** @var FacultyAvailabilityDeclaration|null $declaration */
        $declaration = $row['declaration'] ?? null;

        if (! $declaration instanceof FacultyAvailabilityDeclaration) {
            return false;
        }

        if ($declaration->effectiveMode() === FacultyAvailabilityDeclaration::ModeCannotTeach) {
            return false;
        }

        if ($activePackage instanceof TermCalendarPackage) {
            return $this->declarationHasUsableTime($declaration, $activePackage, $durationMinutes, $recurringCalendarBlocks);
        }

        return true;
    }

    /**
     * @param  list<array{duration_minutes:int,meeting_count:int,fixed_faculty_id:int|null,same_faculty_required:bool}>  $linkedRequirements
     * @param  list<User>  $qualifiedTeachers
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
     * @param  Collection<int, CalendarEvent>|null  $recurringCalendarBlocks
     * @param  array<string, bool>  $seenFacultyBlockers
     * @param  list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>  $blockers
     */
    private function appendSameFacultyBlockers(
        Section $section,
        Course $course,
        array $linkedRequirements,
        array $qualifiedTeachers,
        ?TermCalendarPackage $activePackage,
        array $allFacultyRows,
        array $zeroUnitOverrideFacultyIds,
        ?Collection $recurringCalendarBlocks,
        array &$seenFacultyBlockers,
        array &$blockers,
    ): void {
        $requiredDurations = [];
        foreach ($linkedRequirements as $requirement) {
            for ($i = 0; $i < $requirement['meeting_count']; $i++) {
                $requiredDurations[] = $requirement['duration_minutes'];
            }
        }
        rsort($requiredDurations);

        $fixedFacultyIds = collect($linkedRequirements)
            ->pluck('fixed_faculty_id')
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($fixedFacultyIds->count() > 1) {
            $blockerKey = "same-faculty-fixed-conflict:{$section->id}:{$course->id}";
            if (! isset($seenFacultyBlockers[$blockerKey])) {
                $seenFacultyBlockers[$blockerKey] = true;
                $blockers[] = $this->blocker(
                    'faculty_demand_uncovered',
                    'Faculty & rooms',
                    'Registrar',
                    "Class {$section->code} ({$course->code}) requires the same teacher across linked components, but conflicting fixed teachers are assigned.",
                    "Assign the same fixed teacher across linked components of {$section->code}, or clear the conflicting override.",
                    "Resolve conflicting fixed teacher assignments for {$section->code}.",
                );
            }

            return;
        }

        if ($fixedFacultyIds->count() === 1) {
            $soleFixedId = (int) $fixedFacultyIds->first();

            foreach (array_unique($requiredDurations) as $durationMinutes) {
                $blockerKey = "fixed:{$section->id}:{$course->id}:{$soleFixedId}:{$durationMinutes}";
                if (isset($seenFacultyBlockers[$blockerKey])) {
                    return;
                }

                $fixedBlocker = $this->fixedFacultyBlocker(
                    section: $section,
                    course: $course,
                    fixedFacultyId: $soleFixedId,
                    durationMinutes: $durationMinutes,
                    activePackage: $activePackage,
                    allFacultyRows: $allFacultyRows,
                    zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                    recurringCalendarBlocks: $recurringCalendarBlocks,
                );
                if ($fixedBlocker !== null) {
                    $seenFacultyBlockers[$blockerKey] = true;
                    $blockers[] = $fixedBlocker;

                    return;
                }
            }

            if ($activePackage instanceof TermCalendarPackage && ! $this->teacherCanCoverLinkedMeetings(
                facultyId: $soleFixedId,
                requiredDurations: $requiredDurations,
                activePackage: $activePackage,
                allFacultyRows: $allFacultyRows,
                zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                recurringCalendarBlocks: $recurringCalendarBlocks,
            )) {
                $row = $allFacultyRows[$soleFixedId] ?? null;
                $teacher = $row['faculty'] ?? User::query()->find($soleFixedId);
                $teacherName = $teacher instanceof User ? (string) $teacher->name : 'Faculty #'.$soleFixedId;
                $blockerKey = "same-faculty-fixed-capacity:{$section->id}:{$course->id}:{$soleFixedId}";
                if (! isset($seenFacultyBlockers[$blockerKey])) {
                    $seenFacultyBlockers[$blockerKey] = true;
                    $blockers[] = $this->blocker(
                        'fixed_faculty_no_usable_time',
                        'Faculty & rooms',
                        'Registrar',
                        "Class {$section->code} ({$course->code}) requires {$teacherName} across all linked components, whose availability cannot fit all required meetings in school teaching hours.",
                        "Use Edit availability in Faculty & rooms to expand {$teacherName}'s hours, or adjust the fixed teacher assignment.",
                        "Update {$teacherName}'s availability via Edit availability in Faculty & rooms or remove the fixed teacher assignment for {$section->code}.",
                    );
                }
            }

            return;
        }

        if ($qualifiedTeachers === []) {
            return;
        }

        $hasUnifiedTeacher = collect($qualifiedTeachers)->contains(
            fn (User $teacher): bool => $this->teacherCanCoverLinkedMeetings(
                facultyId: (int) $teacher->id,
                requiredDurations: $requiredDurations,
                activePackage: $activePackage,
                allFacultyRows: $allFacultyRows,
                zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                recurringCalendarBlocks: $recurringCalendarBlocks,
            ),
        );

        if ($hasUnifiedTeacher) {
            return;
        }

        $alreadyBlocked = collect($requiredDurations)
            ->unique()
            ->contains(fn (int $duration): bool => isset($seenFacultyBlockers["uncovered:{$section->id}:{$course->id}:{$duration}"]));

        if ($alreadyBlocked) {
            return;
        }

        $blockerKey = "same-faculty-uncovered:{$section->id}:{$course->id}";
        if (! isset($seenFacultyBlockers[$blockerKey])) {
            $seenFacultyBlockers[$blockerKey] = true;
            $maxDuration = max($requiredDurations);
            $blockers[] = $this->uncoveredDemandBlocker(
                section: $section,
                course: $course,
                qualifiedTeachers: $qualifiedTeachers,
                durationMinutes: $maxDuration,
                activePackage: $activePackage,
                allFacultyRows: $allFacultyRows,
                zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                recurringCalendarBlocks: $recurringCalendarBlocks,
            );
        }
    }

    /**
     * @param  array{duration_minutes:int,meeting_count:int,fixed_faculty_id:int|null,same_faculty_required:bool}  $requirement
     * @param  list<User>  $qualifiedTeachers
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
     * @param  Collection<int, CalendarEvent>|null  $recurringCalendarBlocks
     * @param  array<string, bool>  $seenFacultyBlockers
     * @param  list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>  $blockers
     */
    private function appendUnlinkedMultiMeetingBlockers(
        Section $section,
        Course $course,
        array $requirement,
        array $qualifiedTeachers,
        ?TermCalendarPackage $activePackage,
        array $allFacultyRows,
        array $zeroUnitOverrideFacultyIds,
        ?Collection $recurringCalendarBlocks,
        array &$seenFacultyBlockers,
        array &$blockers,
    ): void {
        if (! $activePackage instanceof TermCalendarPackage) {
            return;
        }

        $requiredDurations = array_fill(0, max(1, $requirement['meeting_count']), $requirement['duration_minutes']);
        $fixedFacultyId = $requirement['fixed_faculty_id'];

        if ($fixedFacultyId !== null) {
            if (isset($seenFacultyBlockers["fixed:{$section->id}:{$course->id}:{$fixedFacultyId}:{$requirement['duration_minutes']}"])) {
                return;
            }

            if (! $this->teacherCanCoverLinkedMeetings(
                facultyId: $fixedFacultyId,
                requiredDurations: $requiredDurations,
                activePackage: $activePackage,
                allFacultyRows: $allFacultyRows,
                zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                recurringCalendarBlocks: $recurringCalendarBlocks,
            )) {
                $row = $allFacultyRows[$fixedFacultyId] ?? null;
                $teacher = $row['faculty'] ?? User::query()->find($fixedFacultyId);
                $teacherName = $teacher instanceof User ? (string) $teacher->name : 'Faculty #'.$fixedFacultyId;
                $blockerKey = "multi-meeting-fixed-capacity:{$section->id}:{$course->id}:{$fixedFacultyId}";
                if (! isset($seenFacultyBlockers[$blockerKey])) {
                    $seenFacultyBlockers[$blockerKey] = true;
                    $blockers[] = $this->blocker(
                        'fixed_faculty_no_usable_time',
                        'Faculty & rooms',
                        'Faculty',
                        "Class {$section->code} ({$course->code}) requires {$teacherName}, whose availability cannot fit all required meetings in school teaching hours.",
                        "Ask {$teacherName} to expand available times on My Availability or adjust the fixed teacher assignment.",
                        "Update {$teacherName}'s availability on My Availability or remove the fixed teacher assignment for {$section->code}.",
                    );
                }
            }

            return;
        }

        if ($qualifiedTeachers === [] || isset($seenFacultyBlockers["uncovered:{$section->id}:{$course->id}:{$requirement['duration_minutes']}"])) {
            return;
        }

        $pooledIntervals = [];
        foreach ($qualifiedTeachers as $teacher) {
            $teacherId = (int) $teacher->id;
            if (! $this->teacherIsUsableForDuration(
                facultyId: $teacherId,
                durationMinutes: $requirement['duration_minutes'],
                activePackage: $activePackage,
                allFacultyRows: $allFacultyRows,
                zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                recurringCalendarBlocks: $recurringCalendarBlocks,
            )) {
                continue;
            }

            $declaration = $allFacultyRows[$teacherId]['declaration'] ?? null;
            if ($declaration instanceof FacultyAvailabilityDeclaration) {
                $pooledIntervals = [...$pooledIntervals, ...$this->usableIntervalLengths($declaration, $activePackage, $recurringCalendarBlocks)];
            }
        }

        rsort($pooledIntervals);

        if (array_sum($pooledIntervals) >= array_sum($requiredDurations)
            && $this->canPackDurationsIntoIntervals($pooledIntervals, $requiredDurations)) {
            return;
        }

        $blockerKey = "multi-meeting-uncovered:{$section->id}:{$course->id}:{$requirement['duration_minutes']}";
        if (! isset($seenFacultyBlockers[$blockerKey])) {
            $seenFacultyBlockers[$blockerKey] = true;
            $blockers[] = $this->uncoveredDemandBlocker(
                section: $section,
                course: $course,
                qualifiedTeachers: $qualifiedTeachers,
                durationMinutes: $requirement['duration_minutes'],
                activePackage: $activePackage,
                allFacultyRows: $allFacultyRows,
                zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                recurringCalendarBlocks: $recurringCalendarBlocks,
            );
        }
    }

    /**
     * @param  list<int>  $requiredDurations
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
     * @param  Collection<int, CalendarEvent>|null  $recurringCalendarBlocks
     */
    private function teacherCanCoverLinkedMeetings(
        int $facultyId,
        array $requiredDurations,
        ?TermCalendarPackage $activePackage,
        array $allFacultyRows,
        array $zeroUnitOverrideFacultyIds,
        ?Collection $recurringCalendarBlocks = null,
    ): bool {
        if ($this->isRegistrarNotTeaching($facultyId, $allFacultyRows, $zeroUnitOverrideFacultyIds)) {
            return false;
        }

        $row = $allFacultyRows[$facultyId] ?? null;
        /** @var FacultyAvailabilityDeclaration|null $declaration */
        $declaration = $row['declaration'] ?? null;

        if (! $declaration instanceof FacultyAvailabilityDeclaration
            || $declaration->effectiveMode() === FacultyAvailabilityDeclaration::ModeCannotTeach) {
            return false;
        }

        if (! $activePackage instanceof TermCalendarPackage) {
            return true;
        }

        foreach (array_unique($requiredDurations) as $duration) {
            if (! $this->declarationHasUsableTime($declaration, $activePackage, $duration, $recurringCalendarBlocks)) {
                return false;
            }
        }

        $usableLengths = $this->usableIntervalLengths($declaration, $activePackage, $recurringCalendarBlocks);

        return array_sum($usableLengths) >= array_sum($requiredDurations)
            && $this->canPackDurationsIntoIntervals($usableLengths, $requiredDurations);
    }

    /**
     * @param  Collection<int, Section>  $confirmedClasses
     * @param  array<int, list<User>>  $qualifiedFacultyByCourse
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
     * @param  Collection<int, Room>  $activeRooms
     * @param  Collection<int, Collection<int, SchedulingCommitment>>  $commitmentsBySection
     * @param  Collection<int, CalendarEvent>  $recurringCalendarBlocks
     * @param  list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>  $blockers
     */
    private function appendMathematicalFeasibilityBlockers(
        Term $term,
        TermCalendarPackage $activePackage,
        Collection $confirmedClasses,
        array $qualifiedFacultyByCourse,
        array $allFacultyRows,
        array $zeroUnitOverrideFacultyIds,
        Collection $activeRooms,
        Collection $commitmentsBySection,
        Collection $recurringCalendarBlocks,
        array &$blockers,
    ): void {
        $calendarOpenIntervalsByDay = $this->calendarOpenIntervalsByDay($activePackage, $recurringCalendarBlocks);
        $calendarWeeklyOpenMinutes = $this->totalMinutesInDayIntervals($calendarOpenIntervalsByDay);

        $this->appendFacultyUnitAndHoursBlockers(
            term: $term,
            activePackage: $activePackage,
            confirmedClasses: $confirmedClasses,
            qualifiedFacultyByCourse: $qualifiedFacultyByCourse,
            allFacultyRows: $allFacultyRows,
            zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
            commitmentsBySection: $commitmentsBySection,
            recurringCalendarBlocks: $recurringCalendarBlocks,
            blockers: $blockers,
        );

        $this->appendRoomHoursBlockers(
            confirmedClasses: $confirmedClasses,
            activeRooms: $activeRooms,
            calendarOpenIntervalsByDay: $calendarOpenIntervalsByDay,
            calendarWeeklyOpenMinutes: $calendarWeeklyOpenMinutes,
            recurringCalendarBlocks: $recurringCalendarBlocks,
            blockers: $blockers,
        );

        $this->appendSectionWeeklyHoursBlockers(
            confirmedClasses: $confirmedClasses,
            calendarWeeklyOpenMinutes: $calendarWeeklyOpenMinutes,
            blockers: $blockers,
        );

        $this->appendPinnedSlotConflictBlockers(
            activePackage: $activePackage,
            confirmedClasses: $confirmedClasses,
            commitmentsBySection: $commitmentsBySection,
            allFacultyRows: $allFacultyRows,
            recurringCalendarBlocks: $recurringCalendarBlocks,
            blockers: $blockers,
        );
    }

    /**
     * @param  Collection<int, Section>  $confirmedClasses
     * @param  array<int, list<User>>  $qualifiedFacultyByCourse
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
     * @param  Collection<int, Collection<int, SchedulingCommitment>>  $commitmentsBySection
     * @param  Collection<int, CalendarEvent>  $recurringCalendarBlocks
     * @param  list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>  $blockers
     */
    private function appendFacultyUnitAndHoursBlockers(
        Term $term,
        TermCalendarPackage $activePackage,
        Collection $confirmedClasses,
        array $qualifiedFacultyByCourse,
        array $allFacultyRows,
        array $zeroUnitOverrideFacultyIds,
        Collection $commitmentsBySection,
        Collection $recurringCalendarBlocks,
        array &$blockers,
    ): void {
        $resolveCap = function (int $facultyId) use ($allFacultyRows, $term): ?float {
            if (array_key_exists($facultyId, $allFacultyRows) && array_key_exists('load_limit', $allFacultyRows[$facultyId])) {
                $limit = $allFacultyRows[$facultyId]['load_limit'];

                return $limit !== null ? (float) $limit : null;
            }

            return $term->default_max_units !== null ? (float) $term->default_max_units : null;
        };

        $facultyName = function (int $facultyId) use ($allFacultyRows): string {
            $faculty = $allFacultyRows[$facultyId]['faculty'] ?? User::query()->find($facultyId);

            return $faculty instanceof User ? (string) $faculty->name : 'Faculty #'.$facultyId;
        };

        $openMinutesCache = [];
        $facultyOpenMinutes = function (int $facultyId) use (&$openMinutesCache, $allFacultyRows, $zeroUnitOverrideFacultyIds, $activePackage, $recurringCalendarBlocks): int {
            if (array_key_exists($facultyId, $openMinutesCache)) {
                return $openMinutesCache[$facultyId];
            }

            if ($this->isRegistrarNotTeaching($facultyId, $allFacultyRows, $zeroUnitOverrideFacultyIds)) {
                return $openMinutesCache[$facultyId] = 0;
            }

            $declaration = $allFacultyRows[$facultyId]['declaration'] ?? null;
            if (! $declaration instanceof FacultyAvailabilityDeclaration
                || $declaration->effectiveMode() === FacultyAvailabilityDeclaration::ModeCannotTeach) {
                return $openMinutesCache[$facultyId] = 0;
            }

            $dayIntervals = $this->usableIntervalsByDayForFaculty($declaration, $activePackage, $recurringCalendarBlocks);

            return $openMinutesCache[$facultyId] = $this->totalMinutesInDayIntervals($dayIntervals);
        };

        $exclusiveUnitsByTeacher = [];
        $exclusiveMinutesByTeacher = [];
        $coursePools = [];
        $allPoolTeacherIds = [];
        $totalTermUnits = 0.0;
        $totalConfirmedClassGroups = 0;
        $everyGroupHasAvailableTeacher = true;

        foreach ($confirmedClasses as $section) {
            $specification = $section->courseRevision ?? $section->termOffering?->courseSpecification();
            if (! $specification instanceof CourseSpecification) {
                continue;
            }

            $course = $specification->course;
            if (! $course instanceof Course) {
                continue;
            }

            $units = is_numeric($specification->credit_units) ? (float) $specification->credit_units : 0.0;
            $qualifiedTeachers = $qualifiedFacultyByCourse[(int) $course->id] ?? [];
            $sectionCommitments = $commitmentsBySection->get((int) $section->id, collect());
            $unscopedFacultyIds = $sectionCommitments
                ->filter(function (SchedulingCommitment $commitment): bool {
                    $scope = GenerateSchedulingDemand::parseCommitmentScope($commitment);

                    return $scope['demand_id'] === null && $scope['component_id'] === null;
                })
                ->pluck('faculty_user_id')
                ->filter(fn (mixed $id): bool => $id !== null)
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();
            $sectionFixedFacultyId = $unscopedFacultyIds->count() === 1 ? (int) $unscopedFacultyIds->first() : null;

            foreach ($section->deliveryGroups->whereNotIn('state', [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled]) as $group) {
                $totalConfirmedClassGroups++;
                $totalTermUnits += $units;
                $demandsByComponent = $group->schedulingDemands->keyBy('course_component_id');
                $override = is_array($group->delivery_override) ? $group->delivery_override : [];
                $overrideFacultyId = $this->nullableInt($override['fixed_faculty_user_id'] ?? $override['faculty_user_id'] ?? null);

                $groupTeacherPool = [];
                $groupFixedTeachers = [];
                $groupHasUncoveredComponent = false;

                foreach ($specification->components as $component) {
                    /** @var SchedulingDemand|null $demand */
                    $demand = $demandsByComponent->get($component->id);
                    $durationMinutes = $demand instanceof SchedulingDemand
                        ? max(30, (int) $demand->required_duration_minutes)
                        : $this->componentDurationMinutes($component);
                    $meetingPattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);
                    $meetingCount = $demand instanceof SchedulingDemand
                        ? max(1, (int) $demand->meeting_count)
                        : max(1, (int) ($meetingPattern['count'] ?? 1));
                    $weeklyMinutes = $meetingCount * $durationMinutes;

                    $componentCommittedFacultyIds = $sectionCommitments
                        ->filter(fn (SchedulingCommitment $commitment): bool => GenerateSchedulingDemand::commitmentAppliesToDemand(
                            $commitment,
                            $demand?->id !== null ? (int) $demand->id : null,
                            (int) $component->id,
                        ))
                        ->pluck('faculty_user_id')
                        ->filter(fn (mixed $id): bool => $id !== null)
                        ->map(fn (mixed $id): int => (int) $id)
                        ->unique()
                        ->values();
                    $componentFixedFacultyId = $componentCommittedFacultyIds->count() === 1
                        ? (int) $componentCommittedFacultyIds->first()
                        : null;

                    $fixedFacultyId = $componentFixedFacultyId
                        ?? $sectionFixedFacultyId
                        ?? ($demand?->fixed_faculty_user_id !== null
                            ? (int) $demand->fixed_faculty_user_id
                            : $overrideFacultyId);

                    if ($fixedFacultyId !== null) {
                        $fixedIsUsable = $this->teacherIsUsableForDuration(
                            facultyId: $fixedFacultyId,
                            durationMinutes: $durationMinutes,
                            activePackage: $activePackage,
                            allFacultyRows: $allFacultyRows,
                            zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                            recurringCalendarBlocks: $recurringCalendarBlocks,
                        );

                        if (! $fixedIsUsable) {
                            $groupHasUncoveredComponent = true;

                            continue;
                        }

                        $groupFixedTeachers[$fixedFacultyId] = $fixedFacultyId;
                        $groupTeacherPool[$fixedFacultyId] = $fixedFacultyId;
                        $exclusiveMinutesByTeacher[$fixedFacultyId]['minutes'] = ($exclusiveMinutesByTeacher[$fixedFacultyId]['minutes'] ?? 0) + $weeklyMinutes;
                        $exclusiveMinutesByTeacher[$fixedFacultyId]['classes'][] = $section->code.' ('.$course->code.')';

                        continue;
                    }

                    $usableIds = [];
                    foreach ($qualifiedTeachers as $teacher) {
                        $tid = (int) $teacher->id;
                        if ($this->teacherIsUsableForDuration(
                            facultyId: $tid,
                            durationMinutes: $durationMinutes,
                            activePackage: $activePackage,
                            allFacultyRows: $allFacultyRows,
                            zeroUnitOverrideFacultyIds: $zeroUnitOverrideFacultyIds,
                            recurringCalendarBlocks: $recurringCalendarBlocks,
                        )) {
                            $usableIds[] = $tid;
                            $groupTeacherPool[$tid] = $tid;
                        }
                    }

                    if ($usableIds === []) {
                        $groupHasUncoveredComponent = true;

                        continue;
                    }

                    if (count($usableIds) === 1) {
                        $soleTid = $usableIds[0];
                        $exclusiveMinutesByTeacher[$soleTid]['minutes'] = ($exclusiveMinutesByTeacher[$soleTid]['minutes'] ?? 0) + $weeklyMinutes;
                        $exclusiveMinutesByTeacher[$soleTid]['classes'][] = $section->code.' ('.$course->code.')';
                    }
                }

                if ($groupHasUncoveredComponent || $groupTeacherPool === []) {
                    $everyGroupHasAvailableTeacher = false;
                }

                $poolIds = array_values($groupTeacherPool);
                foreach ($poolIds as $tid) {
                    $allPoolTeacherIds[$tid] = $tid;
                }

                if ($units > 0.0) {
                    $coursePools[(int) $course->id]['course_code'] = (string) $course->code;
                    $coursePools[(int) $course->id]['units'] = ($coursePools[(int) $course->id]['units'] ?? 0.0) + $units;
                    $coursePools[(int) $course->id]['has_uncovered'] = ($coursePools[(int) $course->id]['has_uncovered'] ?? false) || $groupHasUncoveredComponent || $poolIds === [];
                    foreach ($poolIds as $tid) {
                        $coursePools[(int) $course->id]['teachers'][$tid] = $tid;
                    }

                    if ($groupFixedTeachers !== []) {
                        foreach ($groupFixedTeachers as $fid) {
                            $exclusiveUnitsByTeacher[$fid]['units'] = ($exclusiveUnitsByTeacher[$fid]['units'] ?? 0.0) + $units;
                            $exclusiveUnitsByTeacher[$fid]['classes'][] = $section->code.' ('.$course->code.')';
                        }
                    } elseif (count($poolIds) === 1 && ! $groupHasUncoveredComponent) {
                        $soleTid = $poolIds[0];
                        $exclusiveUnitsByTeacher[$soleTid]['units'] = ($exclusiveUnitsByTeacher[$soleTid]['units'] ?? 0.0) + $units;
                        $exclusiveUnitsByTeacher[$soleTid]['classes'][] = $section->code.' ('.$course->code.')';
                    }
                }
            }
        }

        // (a.i) Exclusive single-teacher unit overload
        foreach ($exclusiveUnitsByTeacher as $facultyId => $usage) {
            $cap = $resolveCap((int) $facultyId);
            if ($cap === null || $usage['units'] <= $cap + 0.0001) {
                continue;
            }

            $name = $facultyName((int) $facultyId);
            $reqStr = $this->formatUnits($usage['units']);
            $capStr = $this->formatUnits($cap);
            $classList = implode(', ', array_slice(array_values(array_unique($usage['classes'])), 0, 3));

            $blockers[] = $this->blocker(
                'faculty_unit_capacity_exceeded',
                'Faculty & rooms',
                'Registrar',
                "{$name} is the only available teacher for {$classList} ({$reqStr} units needed vs {$capStr} units max).",
                "Approve a term load override for {$name} or qualify another available teacher in Faculty & rooms.",
                "Increase {$name}'s unit limit or qualify another teacher for those subjects before generating.",
            );
        }

        // (a.ii) Subject-pool unit overload
        foreach ($coursePools as $pool) {
            $teacherIds = array_values($pool['teachers'] ?? []);
            if (($pool['has_uncovered'] ?? false) || count($teacherIds) < 2 || ($pool['units'] ?? 0.0) <= 0.0) {
                continue;
            }

            $allCapped = true;
            $combinedCap = 0.0;
            foreach ($teacherIds as $tid) {
                $cap = $resolveCap((int) $tid);
                if ($cap === null) {
                    $allCapped = false;
                    break;
                }
                $combinedCap += $cap;
            }

            if (! $allCapped || $pool['units'] <= $combinedCap + 0.0001) {
                continue;
            }

            $reqStr = $this->formatUnits($pool['units']);
            $capStr = $this->formatUnits($combinedCap);
            $teacherCount = count($teacherIds);

            $blockers[] = $this->blocker(
                'faculty_unit_capacity_exceeded',
                'Faculty & rooms',
                'Registrar',
                "{$pool['course_code']} classes require {$reqStr} units, exceeding the {$capStr} units combined capacity of its {$teacherCount} available teachers.",
                "Qualify another available teacher for {$pool['course_code']} or approve load overrides in Faculty & rooms.",
                "Add qualified teaching capacity for {$pool['course_code']} before generating.",
            );
        }

        // (a.iii) Whole-term unit overload
        if ($everyGroupHasAvailableTeacher && $allPoolTeacherIds !== [] && $totalTermUnits > 0.0) {
            $allCapped = true;
            $totalCombinedCap = 0.0;
            foreach ($allPoolTeacherIds as $tid) {
                $cap = $resolveCap((int) $tid);
                if ($cap === null) {
                    $allCapped = false;
                    break;
                }
                $totalCombinedCap += $cap;
            }

            if ($allCapped && $totalTermUnits > $totalCombinedCap + 0.0001) {
                $reqStr = $this->formatUnits($totalTermUnits);
                $capStr = $this->formatUnits($totalCombinedCap);
                $teacherCount = count($allPoolTeacherIds);

                $blockers[] = $this->blocker(
                    'faculty_unit_capacity_exceeded',
                    'Faculty & rooms',
                    'Registrar',
                    "Whole-term teaching load ({$reqStr} units across {$totalConfirmedClassGroups} classes) exceeds available faculty capacity ({$capStr} units across {$teacherCount} teachers).",
                    'Approve term load overrides, increase the term default unit limit, or qualify more teachers in Faculty & rooms.',
                    'Ensure total available faculty unit capacity meets or exceeds total planned class units.',
                );
            }
        }

        // (b) Exclusive teacher weekly hours exceeded
        foreach ($exclusiveMinutesByTeacher as $facultyId => $usage) {
            $openMinutes = $facultyOpenMinutes((int) $facultyId);
            if ($openMinutes <= 0 || $usage['minutes'] <= $openMinutes) {
                continue;
            }

            $name = $facultyName((int) $facultyId);
            $reqHours = $this->formatHours($usage['minutes']);
            $openHours = $this->formatHours($openMinutes);

            $blockers[] = $this->blocker(
                'faculty_hours_exceeded',
                'Faculty & rooms',
                'Registrar',
                "{$name} only has {$openHours} of open teaching time per week, but exclusive classes require {$reqHours}.",
                "Ask {$name} to expand availability on My Availability or qualify another teacher in Faculty & rooms.",
                "Ensure {$name}'s weekly open hours can fit all classes assigned exclusively to them.",
            );
        }
    }

    /**
     * @param  Collection<int, Section>  $confirmedClasses
     * @param  Collection<int, Room>  $activeRooms
     * @param  array<int, list<array{start:int,end:int}>>  $calendarOpenIntervalsByDay
     * @param  Collection<int, CalendarEvent>  $recurringCalendarBlocks
     * @param  list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>  $blockers
     */
    private function appendRoomHoursBlockers(
        Collection $confirmedClasses,
        Collection $activeRooms,
        array $calendarOpenIntervalsByDay,
        int $calendarWeeklyOpenMinutes,
        Collection $recurringCalendarBlocks,
        array &$blockers,
    ): void {
        if ($activeRooms->isEmpty() || $calendarWeeklyOpenMinutes <= 0) {
            return;
        }

        $roomBlocksByRoom = [];
        foreach ($recurringCalendarBlocks as $block) {
            if ($block->scope_type !== CalendarEvent::ScopeRoom || $block->room_id === null) {
                continue;
            }

            $day = (int) $block->day_of_week;
            $bStart = $this->calendarEventMinutes($block->starts_at);
            $bEnd = $this->calendarEventMinutes($block->ends_at);
            if ($bStart === null || $bEnd === null || $bStart >= $bEnd) {
                continue;
            }

            foreach ($calendarOpenIntervalsByDay[$day] ?? [] as $interval) {
                $overlap = max(0, min($bEnd, $interval['end']) - max($bStart, $interval['start']));
                $roomBlocksByRoom[(int) $block->room_id] = ($roomBlocksByRoom[(int) $block->room_id] ?? 0) + $overlap;
            }
        }

        $roomOpenMinutes = [];
        foreach ($activeRooms as $room) {
            $roomOpenMinutes[(int) $room->id] = max(0, $calendarWeeklyOpenMinutes - ($roomBlocksByRoom[(int) $room->id] ?? 0));
        }

        $demandsByType = [];
        foreach ($confirmedClasses as $section) {
            $specification = $section->courseRevision ?? $section->termOffering?->courseSpecification();
            if (! $specification instanceof CourseSpecification) {
                continue;
            }

            foreach ($section->deliveryGroups->whereNotIn('state', [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled]) as $group) {
                $modality = filled($group->modality)
                    ? (string) $group->modality
                    : (string) ($section->termOffering?->modality ?? $section->delivery_mode);

                if ($modality !== TermOffering::ModalityFaceToFace) {
                    continue;
                }

                $demandsByComponent = $group->schedulingDemands->keyBy('course_component_id');
                $seats = max(0, (int) $group->expected_count);

                foreach ($specification->components as $component) {
                    $roomType = GenerateSchedulingDemand::resolveRoomType($section->termOffering?->room_type_override, $component);
                    if (blank($roomType)) {
                        continue;
                    }

                    /** @var SchedulingDemand|null $demand */
                    $demand = $demandsByComponent->get($component->id);
                    $durationMinutes = $demand instanceof SchedulingDemand
                        ? max(30, (int) $demand->required_duration_minutes)
                        : $this->componentDurationMinutes($component);
                    $meetingPattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);
                    $meetingCount = $demand instanceof SchedulingDemand
                        ? max(1, (int) $demand->meeting_count)
                        : max(1, (int) ($meetingPattern['count'] ?? 1));
                    $weeklyMinutes = $meetingCount * $durationMinutes;

                    $typeKey = (string) $roomType;
                    $demandsByType[$typeKey]['minutes'] = ($demandsByType[$typeKey]['minutes'] ?? 0) + $weeklyMinutes;
                    $demandsByType[$typeKey]['demands'][] = ['minutes' => $weeklyMinutes, 'seats' => $seats];
                }
            }
        }

        $roomTypeLabels = Room::typeOptions();

        foreach ($demandsByType as $roomType => $usage) {
            $matchingRooms = $activeRooms->filter(fn (Room $room): bool => $room->room_type === $roomType)->values();
            if ($matchingRooms->isEmpty()) {
                continue;
            }

            $availableMinutes = $matchingRooms->sum(fn (Room $room): int => $roomOpenMinutes[(int) $room->id] ?? 0);
            $typeLabel = $roomTypeLabels[$roomType] ?? $roomType;

            if ($usage['minutes'] > $availableMinutes) {
                $reqHours = $this->formatHours($usage['minutes']);
                $availHours = $this->formatHours($availableMinutes);
                $roomCount = $matchingRooms->count();

                $blockers[] = $this->blocker(
                    'room_hours_exceeded',
                    'Faculty & rooms',
                    'Registrar',
                    "On-campus {$typeLabel} classes require {$reqHours}/week, exceeding the {$availHours}/week available across {$roomCount} active room(s).",
                    "Add another active {$typeLabel} room in Faculty & rooms, switch some classes to Online, or widen teaching hours in Calendar.",
                    "Ensure combined open weekly hours of active {$typeLabel} rooms meet class demand.",
                );

                continue;
            }

            $seatTiers = array_values(array_unique(array_filter(
                array_column($usage['demands'], 'seats'),
                fn (int $seats): bool => $seats > 0,
            )));
            rsort($seatTiers);

            foreach ($seatTiers as $seatThreshold) {
                $tierDemands = array_values(array_filter(
                    $usage['demands'],
                    fn (array $item): bool => $item['seats'] >= $seatThreshold,
                ));
                $tierMinutes = array_sum(array_column($tierDemands, 'minutes'));
                $tierRooms = $matchingRooms->filter(fn (Room $room): bool => (int) $room->capacity >= $seatThreshold);

                if ($tierRooms->isEmpty()) {
                    continue;
                }

                $tierAvailMinutes = $tierRooms->sum(fn (Room $room): int => $roomOpenMinutes[(int) $room->id] ?? 0);

                if ($tierMinutes > $tierAvailMinutes) {
                    $reqHours = $this->formatHours($tierMinutes);
                    $availHours = $this->formatHours($tierAvailMinutes);

                    $blockers[] = $this->blocker(
                        'room_hours_exceeded',
                        'Faculty & rooms',
                        'Registrar',
                        "{$typeLabel} classes needing {$seatThreshold}+ seats require {$reqHours}/week, exceeding the {$availHours}/week available in large matching rooms.",
                        "Add or expand a {$typeLabel} room with {$seatThreshold}+ seats in Faculty & rooms, or split large sections.",
                        'Ensure large-room weekly hours cover large-section demand.',
                    );

                    break;
                }
            }
        }
    }

    /**
     * @param  Collection<int, Section>  $confirmedClasses
     * @param  list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>  $blockers
     */
    private function appendSectionWeeklyHoursBlockers(
        Collection $confirmedClasses,
        int $calendarWeeklyOpenMinutes,
        array &$blockers,
    ): void {
        if ($calendarWeeklyOpenMinutes <= 0) {
            return;
        }

        $minutesByBucket = [];

        foreach ($confirmedClasses as $section) {
            $specification = $section->courseRevision ?? $section->termOffering?->courseSpecification();
            if (! $specification instanceof CourseSpecification) {
                continue;
            }

            $sectionWeeklyMinutes = 0;
            foreach ($section->deliveryGroups->whereNotIn('state', [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled]) as $group) {
                $demandsByComponent = $group->schedulingDemands->keyBy('course_component_id');
                foreach ($specification->components as $component) {
                    /** @var SchedulingDemand|null $demand */
                    $demand = $demandsByComponent->get($component->id);
                    $durationMinutes = $demand instanceof SchedulingDemand
                        ? max(30, (int) $demand->required_duration_minutes)
                        : $this->componentDurationMinutes($component);
                    $meetingPattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);
                    $meetingCount = $demand instanceof SchedulingDemand
                        ? max(1, (int) $demand->meeting_count)
                        : max(1, (int) ($meetingPattern['count'] ?? 1));
                    $sectionWeeklyMinutes += $meetingCount * $durationMinutes;
                }
            }

            if ($sectionWeeklyMinutes <= 0) {
                continue;
            }

            if ($section->cohorts->isEmpty()) {
                $key = 'section:'.$section->id;
                $minutesByBucket[$key]['label'] = (string) $section->code;
                $minutesByBucket[$key]['minutes'] = ($minutesByBucket[$key]['minutes'] ?? 0) + $sectionWeeklyMinutes;

                continue;
            }

            foreach ($section->cohorts as $cohort) {
                /** @var TermCohort $cohort */
                $key = 'cohort:'.$cohort->id;
                $minutesByBucket[$key]['label'] = (string) $cohort->reference;
                $minutesByBucket[$key]['minutes'] = ($minutesByBucket[$key]['minutes'] ?? 0) + $sectionWeeklyMinutes;
            }
        }

        foreach ($minutesByBucket as $usage) {
            if ($usage['minutes'] <= $calendarWeeklyOpenMinutes) {
                continue;
            }

            $reqHours = $this->formatHours($usage['minutes']);
            $availHours = $this->formatHours($calendarWeeklyOpenMinutes);

            $blockers[] = $this->blocker(
                'section_weekly_hours_exceeded',
                'Sections & classes',
                'Registrar',
                "Student section {$usage['label']} has {$reqHours}/week of confirmed classes, exceeding the {$availHours}/week open in the term calendar.",
                'Extend daily teaching hours in Calendar or remove duplicate/excess class assignments in Sections & classes.',
                "Ensure {$usage['label']}'s weekly class hours fit inside open calendar teaching hours.",
            );
        }
    }

    /**
     * @param  Collection<int, Section>  $confirmedClasses
     * @param  Collection<int, Collection<int, SchedulingCommitment>>  $commitmentsBySection
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  Collection<int, CalendarEvent>  $recurringCalendarBlocks
     * @param  list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>  $blockers
     */
    private function appendPinnedSlotConflictBlockers(
        TermCalendarPackage $activePackage,
        Collection $confirmedClasses,
        Collection $commitmentsBySection,
        array $allFacultyRows,
        Collection $recurringCalendarBlocks,
        array &$blockers,
    ): void {
        $activePackage->loadMissing('teachingGridRows');
        $gridByDay = $activePackage->teachingGridRows->keyBy('day_of_week');
        $confirmedById = $confirmedClasses->keyBy('id');
        $pinnedSlots = [];
        $seenReasons = [];

        $addPinConflict = function (string $key, string $reason) use (&$blockers, &$seenReasons): void {
            if (isset($seenReasons[$key])) {
                return;
            }
            $seenReasons[$key] = true;
            $blockers[] = $this->blocker(
                'pinned_slot_conflict',
                'Sections & classes',
                'Registrar',
                $reason,
                'Open Sections & classes to adjust or clear conflicting pinned schedules.',
                'Resolve pinned slot overlaps with breaks, teacher availability, or other pinned classes.',
            );
        };

        foreach ($confirmedClasses as $section) {
            $sectionCommitments = $commitmentsBySection->get((int) $section->id, collect());
            if ($sectionCommitments->isEmpty()) {
                continue;
            }

            $specification = $section->courseRevision ?? $section->termOffering?->courseSpecification();
            $courseCode = (string) ($specification?->course?->code ?? $section->code);
            $cohortIds = $section->cohorts->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
            $unscopedFacultyId = $sectionCommitments
                ->filter(function (SchedulingCommitment $commitment): bool {
                    $scope = GenerateSchedulingDemand::parseCommitmentScope($commitment);

                    return $scope['demand_id'] === null && $scope['component_id'] === null && $commitment->faculty_user_id !== null;
                })
                ->pluck('faculty_user_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique();
            $fallbackFacultyId = $unscopedFacultyId->count() === 1 ? (int) $unscopedFacultyId->first() : null;

            foreach ($sectionCommitments as $commitment) {
                $day = $commitment->day_of_week !== null ? (int) $commitment->day_of_week : null;
                $start = FacultyAvailabilityDeclaration::clockToMinutes($commitment->starts_at !== null ? (string) $commitment->starts_at : null);

                if ($day === null || $start === null) {
                    continue;
                }

                $scope = GenerateSchedulingDemand::parseCommitmentScope($commitment);
                $end = FacultyAvailabilityDeclaration::clockToMinutes($commitment->ends_at !== null ? (string) $commitment->ends_at : null);
                if ($end === null || $end <= $start) {
                    $component = $scope['component_id'] !== null
                        ? $specification?->components->firstWhere('id', $scope['component_id'])
                        : $specification?->components->first();
                    $duration = $component instanceof CourseComponent ? $this->componentDurationMinutes($component) : 30;
                    $end = $start + $duration;
                }

                $facultyId = $commitment->faculty_user_id !== null ? (int) $commitment->faculty_user_id : $fallbackFacultyId;
                $roomId = $commitment->room_id !== null ? (int) $commitment->room_id : null;
                $label = "{$section->code} ({$courseCode})";
                $startsClock = FacultyAvailabilityDeclaration::minutesToClock($start);
                $endsClock = FacultyAvailabilityDeclaration::minutesToClock($end);

                // Check against calendar grid bounds and daily breaks
                $gridRow = $gridByDay->get($day);
                if (! $gridRow instanceof TermTeachingGridRow) {
                    $addPinConflict("pin-grid:{$commitment->id}", "Pinned schedule for {$label} falls on a non-teaching day.");
                } else {
                    $gridStart = FacultyAvailabilityDeclaration::clockToMinutes((string) $gridRow->starts_at);
                    $gridEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) $gridRow->ends_at);
                    if ($gridStart === null || $gridEnd === null || $start < $gridStart || $end > $gridEnd) {
                        $addPinConflict("pin-bounds:{$commitment->id}", "Pinned schedule for {$label} falls outside approved daily teaching hours.");
                    }

                    foreach (is_array($gridRow->breaks) ? $gridRow->breaks : [] as $break) {
                        if (! is_array($break)) {
                            continue;
                        }
                        $bStart = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['starts_at'] ?? ''));
                        $bEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['ends_at'] ?? ''));
                        if ($bStart !== null && $bEnd !== null && $bStart < $bEnd && $start < $bEnd && $end > $bStart) {
                            $addPinConflict("pin-break:{$commitment->id}", "Pinned schedule for {$label} overlaps a mandatory daily break.");
                            break;
                        }
                    }
                }

                // Check against active CalendarEvent blocks
                foreach ($recurringCalendarBlocks as $block) {
                    if ((int) $block->day_of_week !== $day) {
                        continue;
                    }
                    $bStart = $this->calendarEventMinutes($block->starts_at);
                    $bEnd = $this->calendarEventMinutes($block->ends_at);
                    if ($bStart === null || $bEnd === null || ! ($start < $bEnd && $end > $bStart)) {
                        continue;
                    }

                    if ($block->scope_type === CalendarEvent::ScopeInstitution
                        || ($roomId !== null && $block->scope_type === CalendarEvent::ScopeRoom && (int) $block->room_id === $roomId)
                        || ($facultyId !== null && $block->scope_type === CalendarEvent::ScopeFaculty && (int) $block->faculty_user_id === $facultyId)) {
                        $addPinConflict("pin-event:{$commitment->id}:{$block->id}", "Pinned schedule for {$label} overlaps an active calendar block.");
                    }
                }

                // Check against teacher availability declaration
                if ($facultyId !== null) {
                    $declaration = $allFacultyRows[$facultyId]['declaration'] ?? null;
                    if ($declaration instanceof FacultyAvailabilityDeclaration
                        && $declaration->conflictsWithMeeting($day, $startsClock, $endsClock)) {
                        $teacher = $allFacultyRows[$facultyId]['faculty'] ?? User::query()->find($facultyId);
                        $teacherName = $teacher instanceof User ? (string) $teacher->name : 'Faculty #'.$facultyId;
                        $addPinConflict("pin-avail:{$commitment->id}", "Pinned schedule for {$label} conflicts with {$teacherName}'s declared availability.");
                    }
                }

                $pinnedSlots[] = [
                    'commitment_id' => (int) $commitment->id,
                    'section_id' => (int) $section->id,
                    'component_id' => $scope['component_id'],
                    'meeting_sequence' => $scope['meeting_sequence'],
                    'label' => $label,
                    'day' => $day,
                    'start' => $start,
                    'end' => $end,
                    'faculty_id' => $facultyId,
                    'room_id' => $roomId,
                    'cohort_ids' => $cohortIds,
                ];
            }
        }

        // Pairwise pinned-slot overlaps
        $count = count($pinnedSlots);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $pinnedSlots[$i];
                $right = $pinnedSlots[$j];

                if ($left['day'] !== $right['day'] || ! ($left['start'] < $right['end'] && $left['end'] > $right['start'])) {
                    continue;
                }

                // Same section + same component + same sequence is the same meeting slot (e.g. merged pin), skip
                if ($left['section_id'] === $right['section_id']
                    && $left['component_id'] === $right['component_id']
                    && $left['meeting_sequence'] === $right['meeting_sequence']) {
                    continue;
                }

                if ($left['faculty_id'] !== null && $left['faculty_id'] === $right['faculty_id']) {
                    $addPinConflict(
                        "pin-pair-fac:{$left['commitment_id']}:{$right['commitment_id']}",
                        "Pinned classes {$left['label']} and {$right['label']} overlap for the same teacher.",
                    );
                }

                if ($left['room_id'] !== null && $left['room_id'] === $right['room_id']) {
                    $addPinConflict(
                        "pin-pair-room:{$left['commitment_id']}:{$right['commitment_id']}",
                        "Pinned classes {$left['label']} and {$right['label']} overlap in the same room.",
                    );
                }

                if ($left['cohort_ids'] !== [] && array_intersect($left['cohort_ids'], $right['cohort_ids']) !== []) {
                    $addPinConflict(
                        "pin-pair-cohort:{$left['commitment_id']}:{$right['commitment_id']}",
                        "Pinned classes {$left['label']} and {$right['label']} overlap for the same student section.",
                    );
                }
            }
        }
    }

    /**
     * @param  Collection<int, CalendarEvent>|null  $recurringCalendarBlocks
     */
    private function declarationHasUsableTime(
        FacultyAvailabilityDeclaration $declaration,
        TermCalendarPackage $calendar,
        int $minimumMinutes = 30,
        ?Collection $recurringCalendarBlocks = null,
    ): bool {
        if ($declaration->effectiveMode() === FacultyAvailabilityDeclaration::ModeCannotTeach) {
            return false;
        }

        foreach ($this->usableIntervalsByDayForFaculty($declaration, $calendar, $recurringCalendarBlocks) as $intervals) {
            foreach ($intervals as $interval) {
                if (($interval['end'] - $interval['start']) >= $minimumMinutes) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, CalendarEvent>|null  $recurringCalendarBlocks
     * @return list<int>
     */
    private function usableIntervalLengths(
        FacultyAvailabilityDeclaration $declaration,
        TermCalendarPackage $calendar,
        ?Collection $recurringCalendarBlocks = null,
    ): array {
        $lengths = [];

        foreach ($this->usableIntervalsByDayForFaculty($declaration, $calendar, $recurringCalendarBlocks) as $intervals) {
            foreach ($intervals as $usable) {
                $length = $usable['end'] - $usable['start'];
                if ($length >= 30) {
                    $lengths[] = $length;
                }
            }
        }

        rsort($lengths);

        return $lengths;
    }

    /**
     * @param  Collection<int, CalendarEvent>|null  $recurringCalendarBlocks
     * @return array<int, list<array{start:int,end:int}>>
     */
    private function usableIntervalsByDayForFaculty(
        FacultyAvailabilityDeclaration $declaration,
        TermCalendarPackage $calendar,
        ?Collection $recurringCalendarBlocks = null,
    ): array {
        $calendar->loadMissing('teachingGridRows');
        $unavailableByDay = collect($declaration->compileUnavailableBlocks($calendar))->groupBy('day_of_week');
        $facultyId = (int) $declaration->faculty_user_id;
        $byDay = [];

        foreach ($calendar->teachingGridRows as $row) {
            $day = (int) $row->day_of_week;
            $gridStart = FacultyAvailabilityDeclaration::clockToMinutes((string) $row->starts_at);
            $gridEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) $row->ends_at);

            if ($gridStart === null || $gridEnd === null || $gridStart >= $gridEnd) {
                continue;
            }

            $cuts = [];
            foreach (is_array($row->breaks) ? $row->breaks : [] as $break) {
                if (! is_array($break)) {
                    continue;
                }

                $bStart = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['starts_at'] ?? ''));
                $bEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['ends_at'] ?? ''));
                if ($bStart !== null && $bEnd !== null && $bStart < $bEnd) {
                    $cuts[] = ['start' => $bStart, 'end' => $bEnd];
                }
            }

            foreach ($unavailableByDay->get($day, []) as $block) {
                $uStart = FacultyAvailabilityDeclaration::clockToMinutes((string) ($block['starts_at'] ?? ''));
                $uEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) ($block['ends_at'] ?? ''));
                if ($uStart !== null && $uEnd !== null && $uStart < $uEnd) {
                    $cuts[] = ['start' => $uStart, 'end' => $uEnd];
                }
            }

            if ($recurringCalendarBlocks instanceof Collection) {
                foreach ($recurringCalendarBlocks as $block) {
                    if ((int) $block->day_of_week !== $day) {
                        continue;
                    }

                    if ($block->scope_type !== CalendarEvent::ScopeInstitution
                        && ! ($block->scope_type === CalendarEvent::ScopeFaculty && (int) $block->faculty_user_id === $facultyId)) {
                        continue;
                    }

                    $eStart = $this->calendarEventMinutes($block->starts_at);
                    $eEnd = $this->calendarEventMinutes($block->ends_at);
                    if ($eStart !== null && $eEnd !== null && $eStart < $eEnd) {
                        $cuts[] = ['start' => $eStart, 'end' => $eEnd];
                    }
                }
            }

            $byDay[$day] = [
                ...($byDay[$day] ?? []),
                ...FacultyAvailabilityDeclaration::subtractIntervals([['start' => $gridStart, 'end' => $gridEnd]], $cuts),
            ];
        }

        return $byDay;
    }

    /**
     * @param  Collection<int, CalendarEvent>|null  $recurringCalendarBlocks
     * @return array<int, list<array{start:int,end:int}>>
     */
    private function calendarOpenIntervalsByDay(
        TermCalendarPackage $calendar,
        ?Collection $recurringCalendarBlocks = null,
    ): array {
        $calendar->loadMissing('teachingGridRows');
        $byDay = [];

        foreach ($calendar->teachingGridRows as $row) {
            $day = (int) $row->day_of_week;
            $gridStart = FacultyAvailabilityDeclaration::clockToMinutes((string) $row->starts_at);
            $gridEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) $row->ends_at);

            if ($gridStart === null || $gridEnd === null || $gridStart >= $gridEnd) {
                continue;
            }

            $cuts = [];
            foreach (is_array($row->breaks) ? $row->breaks : [] as $break) {
                if (! is_array($break)) {
                    continue;
                }

                $bStart = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['starts_at'] ?? ''));
                $bEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['ends_at'] ?? ''));
                if ($bStart !== null && $bEnd !== null && $bStart < $bEnd) {
                    $cuts[] = ['start' => $bStart, 'end' => $bEnd];
                }
            }

            if ($recurringCalendarBlocks instanceof Collection) {
                foreach ($recurringCalendarBlocks as $block) {
                    if ((int) $block->day_of_week !== $day || $block->scope_type !== CalendarEvent::ScopeInstitution) {
                        continue;
                    }

                    $eStart = $this->calendarEventMinutes($block->starts_at);
                    $eEnd = $this->calendarEventMinutes($block->ends_at);
                    if ($eStart !== null && $eEnd !== null && $eStart < $eEnd) {
                        $cuts[] = ['start' => $eStart, 'end' => $eEnd];
                    }
                }
            }

            $byDay[$day] = [
                ...($byDay[$day] ?? []),
                ...FacultyAvailabilityDeclaration::subtractIntervals([['start' => $gridStart, 'end' => $gridEnd]], $cuts),
            ];
        }

        return $byDay;
    }

    /**
     * @param  array<int, list<array{start:int,end:int}>>  $intervalsByDay
     */
    private function totalMinutesInDayIntervals(array $intervalsByDay): int
    {
        $total = 0;

        foreach ($intervalsByDay as $intervals) {
            foreach ($intervals as $interval) {
                $total += max(0, $interval['end'] - $interval['start']);
            }
        }

        return $total;
    }

    private function calendarEventMinutes(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return FacultyAvailabilityDeclaration::clockToMinutes($value->format('H:i:s'));
        }

        return FacultyAvailabilityDeclaration::clockToMinutes(is_string($value) ? $value : null);
    }

    private function formatUnits(float $units): string
    {
        $rounded = round($units, 2);

        return abs($rounded - round($rounded)) < 0.001
            ? (string) (int) round($rounded)
            : rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }

    private function formatHours(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($mins === 0) {
            return $hours === 1 ? '1 hr' : "{$hours} hrs";
        }

        return $hours === 0 ? "{$mins} min" : "{$hours}h {$mins}m";
    }

    /**
     * @param  list<int>  $intervals
     * @param  list<int>  $durations
     */
    private function canPackDurationsIntoIntervals(array $intervals, array $durations, int $index = 0): bool
    {
        if ($index >= count($durations)) {
            return true;
        }

        $needed = $durations[$index];
        $triedSizes = [];

        foreach ($intervals as $slotIndex => $available) {
            if ($available < $needed || isset($triedSizes[$available])) {
                continue;
            }

            $triedSizes[$available] = true;
            $nextIntervals = $intervals;
            $nextIntervals[$slotIndex] = $available - $needed;

            if ($this->canPackDurationsIntoIntervals($nextIntervals, $durations, $index + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
     */
    private function isRegistrarNotTeaching(int $facultyId, array $allFacultyRows, array $zeroUnitOverrideFacultyIds): bool
    {
        $row = $allFacultyRows[$facultyId] ?? null;

        return (bool) ($row['registrar_not_teaching'] ?? false)
            || isset($zeroUnitOverrideFacultyIds[$facultyId]);
    }

    private function componentDurationMinutes(CourseComponent $component): int
    {
        $pattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);

        return $pattern['duration_minutes']
            ?? max(30, (int) round((float) $component->weekly_contact_hours * 60));
    }

    private function nullableInt(mixed $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed === false ? null : (int) $parsed;
    }

    /** @return array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string} */
    private function blocker(string $code, string $source, string $owner, string $reason, string $nextAction, string $recovery): array
    {
        return compact('code', 'source', 'owner', 'reason') + ['next_action' => $nextAction, 'recovery' => $recovery];
    }
}
