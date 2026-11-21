<?php

namespace App\Actions\Scheduling;

use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\FacultyAvailabilityDeclaration;
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

        $availabilityStatus = ($this->availabilityProjection ?? app(FacultyAvailabilityStatusProjection::class))->forTerm($term);
        /** @var TermCalendarPackage|null $activePackage */
        $activePackage = $availabilityStatus['active_calendar_package'];

        if (! $activePackage instanceof TermCalendarPackage) {
            $blockers[] = $this->blocker('calendar_not_active', 'Term Calendar Package', 'Registrar', 'No active package exists for this exact Term.', 'Activate a passing package.', 'Correct the Draft package and retry.');
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

        if ($classes->isEmpty() || $classes->contains(fn (Section $section): bool => $section->confirmed_at === null)) {
            $blockers[] = $this->blocker('classes_not_confirmed', 'Cohorts and Class Offerings', 'Registrar', 'Every ready Class Offering must be confirmed.', 'Resolve class authority, cohorts, capacity, and meeting requirements.', 'Correct the affected Draft class and confirm it.');
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
                    'Teaching resources',
                    'Registrar',
                    'Record at least one active qualified teacher for: '.implode(', ', $missingCourseCodes).'.',
                    'Open Teaching resources to assign qualified teachers for each offered course.',
                    'Add active Faculty Qualifications for the listed courses, then retry readiness.',
                );
            }
        }

        $allFacultyRows = $availabilityStatus['all_faculty_rows'];
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
            $sectionCommitment = $sectionCommitments->count() === 1 ? $sectionCommitments->first() : null;
            $qualifiedTeachers = $qualifiedFacultyByCourse[(int) $course->id] ?? [];

            foreach ($groups as $group) {
                $demandsByComponent = $group->schedulingDemands->keyBy('course_component_id');
                $override = is_array($group->delivery_override) ? $group->delivery_override : [];
                $overrideFacultyId = $this->nullableInt($override['fixed_faculty_user_id'] ?? $override['faculty_user_id'] ?? null);

                foreach ($specification->components as $component) {
                    /** @var SchedulingDemand|null $demand */
                    $demand = $demandsByComponent->get($component->id);
                    $durationMinutes = $demand instanceof SchedulingDemand
                        ? max(30, (int) $demand->required_duration_minutes)
                        : $this->componentDurationMinutes($component);

                    $fixedFacultyId = $sectionCommitment instanceof SchedulingCommitment && $sectionCommitment->faculty_user_id !== null
                        ? (int) $sectionCommitment->faculty_user_id
                        : ($demand?->fixed_faculty_user_id !== null
                            ? (int) $demand->fixed_faculty_user_id
                            : $overrideFacultyId);

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
                            );
                        }
                    }
                }
            }
        }

        $activeRooms = Room::query()->where('is_active', true)->get(['id', 'room_type', 'capacity']);

        if ($activeRooms->isEmpty()) {
            $blockers[] = $this->blocker('rooms_unavailable', 'Teaching resources', 'Registrar', 'No active room is available for on-campus planning.', 'Record current room capacity, type, features, and unavailability.', 'Correct the room authority and retry readiness.');
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
                        $roomType = $section->termOffering?->room_type_override ?: $component->room_type_default;
                        if (blank($roomType)) {
                            continue;
                        }

                        $hasMatch = $activeRooms->contains(
                            fn (Room $room): bool => $room->room_type === (string) $roomType
                                && (int) $room->capacity >= $expectedCount,
                        );

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
                    'Teaching resources',
                    'Registrar',
                    'No active room matches the required type and seat capacity for: '.implode('; ', $preview).($remainder > 0 ? ' (+'.$remainder.' more)' : '').'.',
                    'Activate or update a matching room in Teaching resources.',
                    'Ensure an active room matches each face-to-face class room type and expected count.',
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
                'Teaching resources',
                'Faculty',
                'Chosen teaching windows for '.implode(', ', $reviewNames).' fall outside the updated teaching grid or overlap a break. Outside hours remain excluded.',
                'Have the affected Faculty review and update their windows on My Availability if needed.',
                'Update availability on My Availability if additional in-grid hours are needed.',
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
                'Late availability changes affect active official meetings for: '.$impactLabels.'. The current official timetable stays active until revised.',
                'Open Timetable to review and publish a controlled official timetable revision.',
                'Use the controlled official timetable revision workflow to reassign or reschedule affected meetings.',
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
     * @return array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}|null
     */
    private function fixedFacultyBlocker(
        Section $section,
        Course $course,
        int $fixedFacultyId,
        int $durationMinutes,
        ?TermCalendarPackage $activePackage,
        array $allFacultyRows,
    ): ?array {
        $row = $allFacultyRows[$fixedFacultyId] ?? null;
        $teacher = $row['faculty'] ?? User::query()->find($fixedFacultyId);
        $teacherName = $teacher instanceof User ? (string) $teacher->name : 'Faculty #'.$fixedFacultyId;
        /** @var FacultyAvailabilityDeclaration|null $declaration */
        $declaration = $row['declaration'] ?? null;

        if (! $declaration instanceof FacultyAvailabilityDeclaration) {
            return $this->blocker(
                'fixed_faculty_availability_missing',
                'Teaching resources',
                'Faculty',
                "Class {$section->code} ({$course->code}) requires {$teacherName}, who has No response for this Term.",
                "Remind {$teacherName} to declare availability on My Availability, or change the fixed teacher assignment.",
                "Have {$teacherName} submit availability on My Availability or remove the fixed teacher commitment for {$section->code}.",
            );
        }

        if ($declaration->effectiveMode() === FacultyAvailabilityDeclaration::ModeCannotTeach) {
            return $this->blocker(
                'fixed_faculty_cannot_teach',
                'Teaching resources',
                'Registrar',
                "Class {$section->code} ({$course->code}) is assigned to {$teacherName}, who declared Cannot teach this Term.",
                "Reassign {$section->code} or have {$teacherName} update availability on My Availability.",
                "Remove the fixed teacher commitment on {$section->code} or have {$teacherName} update their declaration.",
            );
        }

        if ($activePackage instanceof TermCalendarPackage && ! $declaration->hasUsableTimeInGrid($activePackage, $durationMinutes)) {
            return $this->blocker(
                'fixed_faculty_no_usable_time',
                'Teaching resources',
                'Faculty',
                "Class {$section->code} ({$course->code}) requires {$teacherName}, whose declared availability leaves no usable {$durationMinutes}-minute window inside the approved teaching grid.",
                "Have {$teacherName} expand chosen windows on My Availability or adjust the fixed teacher assignment.",
                "Update {$teacherName}'s availability on My Availability or change the fixed teacher commitment for {$section->code}.",
            );
        }

        return null;
    }

    /**
     * @param  list<User>  $qualifiedTeachers
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     * @return array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}
     */
    private function uncoveredDemandBlocker(
        Section $section,
        Course $course,
        array $qualifiedTeachers,
        int $durationMinutes,
        ?TermCalendarPackage $activePackage,
        array $allFacultyRows,
    ): array {
        $teacherSummaries = collect($qualifiedTeachers)
            ->map(function (User $teacher) use ($durationMinutes, $activePackage, $allFacultyRows): string {
                $row = $allFacultyRows[(int) $teacher->id] ?? null;
                /** @var FacultyAvailabilityDeclaration|null $declaration */
                $declaration = $row['declaration'] ?? null;

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
            'Teaching resources',
            'Faculty',
            "Class {$section->code} ({$course->code}) has no confirmed-eligible teacher available ({$teacherSummaries}).",
            "Remind a qualified teacher for {$course->code} to submit usable availability on My Availability, or qualify another available teacher.",
            "Ensure at least one qualified teacher for {$course->code} records usable availability on My Availability.",
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $allFacultyRows
     */
    private function teacherIsUsableForDuration(
        int $facultyId,
        int $durationMinutes,
        ?TermCalendarPackage $activePackage,
        array $allFacultyRows,
    ): bool {
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
