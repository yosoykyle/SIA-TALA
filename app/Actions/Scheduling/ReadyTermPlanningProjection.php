<?php

namespace App\Actions\Scheduling;

use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
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
use App\Models\TermOffering;
use App\Models\User;

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

        $classes = Section::query()
            ->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $term->id))
            ->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])
            ->with([
                'calendarPackage.teachingGridRows',
                'calendarPackage.datedExceptions',
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

        if ($hasPublishedTimetable) {
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
                        seenFacultyBlockers: $seenFacultyBlockers,
                        blockers: $blockers,
                    );
                }
            }
        }

        $activeRooms = Room::query()->with('features')->where('is_active', true)->get(['id', 'room_type', 'capacity']);

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

        if ($availabilityStatus['needs_grid_review_count'] > 0) {
            $reviewNames = collect($availabilityStatus['faculty_rows'])
                ->where('needs_grid_review', true)
                ->pluck('name')
                ->values()
                ->all();

            $advisories[] = $this->blocker(
                'faculty_availability_grid_review_needed',
                'Faculty & rooms',
                'Faculty',
                'Saved teaching times for '.implode(', ', $reviewNames).' fall outside the updated school teaching hours or overlap a break. Outside hours are excluded.',
                'Ask the affected faculty to review and update their times on My Availability if needed.',
                'Update teaching times on My Availability if more hours are needed.',
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
                "Reassign {$section->code} or ask {$teacherName} to update availability on My Availability.",
                "Remove the fixed teacher assignment on {$section->code} or ask {$teacherName} to update their availability.",
            );
        }

        if ($activePackage instanceof TermCalendarPackage && ! $declaration->hasUsableTimeInGrid($activePackage, $durationMinutes)) {
            return $this->blocker(
                'fixed_faculty_no_usable_time',
                'Faculty & rooms',
                'Faculty',
                "Class {$section->code} ({$course->code}) requires {$teacherName}, whose availability leaves no usable {$durationMinutes}-minute window inside school teaching hours.",
                "Ask {$teacherName} to expand available times on My Availability or adjust the fixed teacher assignment.",
                "Update {$teacherName}'s availability on My Availability or change the fixed teacher assignment for {$section->code}.",
            );
        }

        return null;
    }

    /**
     * @param  list<User>  $qualifiedTeachers
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
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
    ): array {
        $teacherSummaries = collect($qualifiedTeachers)
            ->map(function (User $teacher) use ($durationMinutes, $activePackage, $allFacultyRows, $zeroUnitOverrideFacultyIds): string {
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

                if ($activePackage instanceof TermCalendarPackage && ! $declaration->hasUsableTimeInGrid($activePackage, $durationMinutes)) {
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
     */
    private function teacherIsUsableForDuration(
        int $facultyId,
        int $durationMinutes,
        ?TermCalendarPackage $activePackage,
        array $allFacultyRows,
        array $zeroUnitOverrideFacultyIds = [],
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
            return $declaration->hasUsableTimeInGrid($activePackage, $durationMinutes);
        }

        return true;
    }

    /**
     * @param  list<array{duration_minutes:int,meeting_count:int,fixed_faculty_id:int|null,same_faculty_required:bool}>  $linkedRequirements
     * @param  list<User>  $qualifiedTeachers
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
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
                        'Faculty',
                        "Class {$section->code} ({$course->code}) requires {$teacherName} across all linked components, whose availability cannot fit all required meetings in school teaching hours.",
                        "Ask {$teacherName} to expand available times on My Availability or adjust the fixed teacher assignment.",
                        "Update {$teacherName}'s availability on My Availability or remove the fixed teacher assignment for {$section->code}.",
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
            );
        }
    }

    /**
     * @param  array{duration_minutes:int,meeting_count:int,fixed_faculty_id:int|null,same_faculty_required:bool}  $requirement
     * @param  list<User>  $qualifiedTeachers
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
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
            )) {
                continue;
            }

            $declaration = $allFacultyRows[$teacherId]['declaration'] ?? null;
            if ($declaration instanceof FacultyAvailabilityDeclaration) {
                $pooledIntervals = [...$pooledIntervals, ...$this->usableIntervalLengths($declaration, $activePackage)];
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
            );
        }
    }

    /**
     * @param  list<int>  $requiredDurations
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @param  array<int, int>  $zeroUnitOverrideFacultyIds
     */
    private function teacherCanCoverLinkedMeetings(
        int $facultyId,
        array $requiredDurations,
        ?TermCalendarPackage $activePackage,
        array $allFacultyRows,
        array $zeroUnitOverrideFacultyIds,
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
            if (! $declaration->hasUsableTimeInGrid($activePackage, $duration)) {
                return false;
            }
        }

        $usableLengths = $this->usableIntervalLengths($declaration, $activePackage);

        return array_sum($usableLengths) >= array_sum($requiredDurations)
            && $this->canPackDurationsIntoIntervals($usableLengths, $requiredDurations);
    }

    /**
     * @return list<int>
     */
    private function usableIntervalLengths(
        FacultyAvailabilityDeclaration $declaration,
        TermCalendarPackage $calendar,
    ): array {
        $calendar->loadMissing('teachingGridRows');
        $unavailableByDay = collect($declaration->compileUnavailableBlocks($calendar))->groupBy('day_of_week');
        $lengths = [];

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

            foreach (FacultyAvailabilityDeclaration::subtractIntervals([['start' => $gridStart, 'end' => $gridEnd]], $cuts) as $usable) {
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
