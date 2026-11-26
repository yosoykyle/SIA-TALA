<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\CourseSpecification;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\OperationalEvent;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

final class FacultyAvailabilityStatusProjection
{
    /**
     * Build the single-source-of-truth Faculty availability projection for a Term.
     *
     * @return array{
     *     term_id: int,
     *     has_active_core_calendar: bool,
     *     active_calendar_package: TermCalendarPackage|null,
     *     total_faculty: int,
     *     replied_count: int,
     *     no_response_count: int,
     *     cannot_teach_count: int,
     *     needs_grid_review_count: int,
     *     needs_hours_review_count: int,
     *     needs_review_count: int,
     *     late_impact_count: int,
     *     faculty_rows: array<int, array<string, mixed>>,
     *     all_faculty_rows: array<int, array<string, mixed>>,
     *     unresolved_late_impacts: list<array<string, mixed>>
     * }
     */
    public function forTerm(Term $term, bool $includeOfficialAssignments = true): array
    {
        $activePackage = TermCalendarPackage::query()
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->with('teachingGridRows')
            ->first();

        $offerings = TermOffering::query()
            ->where('term_id', $term->id)
            ->where('state', '!=', TermOffering::StateCancelled)
            ->with(['curriculumEntry.courseSpecification.course'])
            ->get();

        $termCourseIds = $offerings
            ->map(fn (TermOffering $offering): ?int => $offering->course()?->id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $courseTitles = $offerings
            ->groupBy(fn (TermOffering $offering): ?int => $offering->course()?->id)
            ->map(fn (Collection $courseOfferings): string => $courseOfferings
                ->pluck('curriculumEntry.courseSpecification.title')
                ->filter()
                ->unique()
                ->implode(' / '));

        $qualifications = FacultyQualification::query()
            ->active()
            ->with([
                'course.specifications' => fn ($query) => $query
                    ->where('state', CourseSpecification::StateActive)
                    ->latest('id'),
            ])
            ->get()
            ->groupBy('faculty_user_id');

        $overrides = FacultyTermLoadOverride::query()
            ->where('term_id', $term->id)
            ->where('is_active', true)
            ->get()
            ->keyBy('faculty_user_id');

        $declarations = FacultyAvailabilityDeclaration::query()
            ->where('term_id', $term->id)
            ->orderByDesc('version')
            ->get()
            ->unique('faculty_user_id')
            ->keyBy('faculty_user_id');

        $packageIds = $activePackage instanceof TermCalendarPackage
            ? $activePackage->compatibleCoreSourceIds()
            : [];

        $requests = $packageIds === []
            ? collect()
            : OperationalEvent::query()
                ->where('event_type', OperationalEvent::TypeFacultyAvailabilityRequestedEmail)
                ->where('related_record_type', TermCalendarPackage::class)
                ->whereIn('related_record_id', $packageIds)
                ->latest('id')
                ->get()
                ->unique('user_id')
                ->keyBy('user_id');

        $officialVersion = $includeOfficialAssignments
            ? PublishedTimetableVersion::query()
                ->where('term_id', $term->id)
                ->where('state', PublishedTimetableVersion::StatePublished)
                ->with('meetings.classOffering.courseRevision')
                ->first()
            : null;

        $officialAssignmentsByFaculty = $officialVersion?->meetings->groupBy('faculty_user_id') ?? collect();

        $draftRunId = (! $officialVersion instanceof PublishedTimetableVersion && $includeOfficialAssignments)
            ? ScheduleGenerationRun::query()
                ->where('term_id', $term->id)
                ->where('status', ScheduleGenerationRun::StatusUnderReview)
                ->where(function ($query): void {
                    $query->whereNull('candidate_state')
                        ->orWhereNotIn('candidate_state', ['Rejected', 'Stale', 'Superseded']);
                })
                ->max('id')
            : null;

        $draftRowsByFaculty = $draftRunId !== null
            ? CandidateScheduleRow::query()
                ->where('schedule_run_id', (int) $draftRunId)
                ->with([
                    'schedulingDemand.courseComponent.courseSpecification',
                    'schedulingDemand.sectionDeliveryGroup.section.courseRevision',
                ])
                ->get()
                ->groupBy('faculty_user_id')
            : collect();

        $activeOfficialMeetingsByFaculty = SectionMeeting::query()
            ->activeOfficial()
            ->whereHas('scheduleRun', fn ($query) => $query->where('term_id', $term->id))
            ->with([
                'schedulingDemand.courseComponent.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section',
            ])
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('faculty_user_id');

        $latestDeclarationIds = $declarations
            ->map(fn (FacultyAvailabilityDeclaration $declaration): int => (int) $declaration->id)
            ->values()
            ->all();

        $impactActivitiesByDeclaration = $latestDeclarationIds === []
            ? collect()
            : Activity::query()
                ->where('event', 'faculty_availability_revision_required')
                ->where('subject_type', FacultyAvailabilityDeclaration::class)
                ->whereIn('subject_id', $latestDeclarationIds)
                ->with('causer')
                ->orderByDesc('id')
                ->get()
                ->filter(fn (Activity $activity): bool => (int) ($activity->properties->get('term_id') ?? 0) === (int) $term->id)
                ->unique('subject_id')
                ->keyBy('subject_id');

        $facultyUsers = User::query()
            ->where('status', User::StatusActive)
            ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleFaculty))
            ->orderBy('name')
            ->get();

        $unresolvedLateImpacts = [];
        $allRows = collect();

        $workloadProjection = app(FacultyTermWorkloadProjection::class);
        $workloadContext = $activePackage instanceof TermCalendarPackage
            ? $workloadProjection->buildTermContext(
                term: $term,
                confirmedOnly: true,
                preloadedPackage: $activePackage,
                preloadedQualificationsByFaculty: $qualifications,
                preloadedOverridesByFaculty: $overrides,
                preloadedDeclarationsByFaculty: $declarations,
                preloadedActiveFacultyIds: $facultyUsers->pluck('id')->all(),
            )
            : null;

        foreach ($facultyUsers as $faculty) {
            $facultyId = (int) $faculty->id;
            /** @var FacultyAvailabilityDeclaration|null $declaration */
            $declaration = $declarations->get($facultyId);
            /** @var OperationalEvent|null $request */
            $request = $requests->get($facultyId);

            $eligibleQualifications = $qualifications->get($facultyId, collect())->unique('course_id');
            $qualifiedCourseIds = $eligibleQualifications
                ->pluck('course_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all();
            $termCourseCount = $eligibleQualifications->whereIn('course_id', $termCourseIds)->count();

            $officialClasses = $officialAssignmentsByFaculty->get($facultyId, collect())
                ->pluck('classOffering')
                ->filter()
                ->unique('id');
            $draftClasses = (! $officialVersion instanceof PublishedTimetableVersion && $draftRunId !== null)
                ? $draftRowsByFaculty->get($facultyId, collect())
                    ->unique(function (CandidateScheduleRow $candidateRow): string {
                        $sectionId = $candidateRow->schedulingDemand?->sectionDeliveryGroup?->section_id;
                        if ($sectionId !== null) {
                            return 'section:'.$sectionId;
                        }

                        $groupId = $candidateRow->schedulingDemand?->section_delivery_group_id;
                        if ($groupId !== null) {
                            return 'group:'.$groupId;
                        }

                        return 'demand:'.$candidateRow->scheduling_demand_id;
                    })
                    ->values()
                : collect();
            $facultyOfficialMeetings = $activeOfficialMeetingsByFaculty->get($facultyId, collect());

            $inTerm = $termCourseCount > 0
                || $declaration !== null
                || $request !== null
                || $officialClasses->isNotEmpty()
                || $draftClasses->isNotEmpty()
                || $facultyOfficialMeetings->isNotEmpty();

            $outsideGridWindows = $declaration instanceof FacultyAvailabilityDeclaration
                ? $declaration->windowsOutsideGrid($activePackage)
                : [];
            $needsGridReview = $outsideGridWindows !== [];

            $hoursShortfall = $workloadContext !== null
                ? $workloadProjection->availabilityHoursShortfall($term, $facultyId, $qualifiedCourseIds, $workloadContext)
                : [
                    'needs_hours_review' => false,
                    'summary' => null,
                ];
            $needsHoursReview = (bool) $hoursShortfall['needs_hours_review'];
            $hoursReviewSummary = $hoursShortfall['summary'];
            $needsReview = $needsGridReview || $needsHoursReview;

            $affectedMeetings = [];
            if ($declaration instanceof FacultyAvailabilityDeclaration && $facultyOfficialMeetings->isNotEmpty()) {
                /** @var Activity|null $impactActivity */
                $impactActivity = $impactActivitiesByDeclaration->get($declaration->id);
                $loggedMeetingIds = $impactActivity instanceof Activity
                    ? collect($impactActivity->properties->get('affected_section_meeting_ids', []))
                        ->map(fn (mixed $id): int => (int) $id)
                        ->all()
                    : [];

                $conflictingMeetings = $facultyOfficialMeetings
                    ->filter(function (SectionMeeting $meeting) use ($declaration, $loggedMeetingIds): bool {
                        if ($loggedMeetingIds !== [] && ! in_array((int) $meeting->id, $loggedMeetingIds, true)) {
                            return false;
                        }

                        return $declaration->conflictsWithMeeting(
                            (int) $meeting->day_of_week,
                            (string) $meeting->starts_at,
                            (string) $meeting->ends_at,
                        );
                    })
                    ->values();

                if ($impactActivity instanceof Activity && $conflictingMeetings->isNotEmpty()) {
                    $affectedMeetings = $conflictingMeetings
                        ->map(fn (SectionMeeting $meeting): array => $this->formatOfficialMeeting($meeting))
                        ->all();

                    $unresolvedLateImpacts[] = [
                        'faculty_user_id' => $facultyId,
                        'faculty_name' => (string) $faculty->name,
                        'declaration_id' => (int) $declaration->id,
                        'declaration_version' => (int) $declaration->version,
                        'effective_mode' => $declaration->effectiveMode(),
                        'mode_label' => $declaration->modeLabel(),
                        'reason' => $declaration->correction_reason ?? $impactActivity->properties->get('reason'),
                        'recorded_at' => $declaration->declared_at ?? $impactActivity->created_at,
                        'actor_id' => $impactActivity->causer_id !== null ? (int) $impactActivity->causer_id : $facultyId,
                        'actor_name' => $impactActivity->causer?->name ?? (string) $faculty->name,
                        'affected_meeting_ids' => array_column($affectedMeetings, 'section_meeting_id'),
                        'affected_meetings' => $affectedMeetings,
                    ];
                }
            }

            [$reminderStatus, $reminderAt] = $this->resolveReminderStatus($request);

            $override = $overrides->get($facultyId);
            $limit = $override instanceof FacultyTermLoadOverride
                ? $override->allowedLoadUnits()
                : ($term->default_max_units !== null ? (float) $term->default_max_units : null);

            if ($officialVersion instanceof PublishedTimetableVersion) {
                $loadStage = 'official';
                $load = $officialClasses->sum(fn ($class): float => (float) ($class->courseRevision?->credit_units ?? 0));
                $assignedClassCount = $officialClasses->count();
            } elseif ($draftRunId !== null) {
                $loadStage = 'draft';
                $load = $draftClasses->sum(function (CandidateScheduleRow $candidateRow): float {
                    $spec = $candidateRow->schedulingDemand?->sectionDeliveryGroup?->section?->courseRevision
                        ?? $candidateRow->schedulingDemand?->courseComponent?->courseSpecification;

                    return (float) ($spec?->credit_units ?? 0);
                });
                $assignedClassCount = $draftClasses->count();
            } else {
                $loadStage = 'none';
                $load = 0.0;
                $assignedClassCount = 0;
            }

            $courseLabels = $eligibleQualifications
                ->map(function (FacultyQualification $qualification) use ($courseTitles): string {
                    $course = $qualification->course;
                    if ($course === null) {
                        return '';
                    }

                    $title = $courseTitles->get($qualification->course_id)
                        ?: $course->specifications->first()?->title
                        ?: $course->code;

                    return $title.' ('.$course->code.')';
                })
                ->filter()
                ->values()
                ->all();

            $effectiveMode = $declaration?->effectiveMode();
            $registrarNotTeaching = $override instanceof FacultyTermLoadOverride && $override->allowedLoadUnits() <= 0.0;
            $statusLabel = match (true) {
                $registrarNotTeaching => 'Not teaching (Registrar)',
                $declaration instanceof FacultyAvailabilityDeclaration => $declaration->modeLabel(),
                default => 'No response',
            };

            $allRows->put($facultyId, [
                'kind' => 'Faculty',
                'faculty_user_id' => $facultyId,
                'faculty_id' => $facultyId,
                'faculty' => $faculty,
                'name' => (string) $faculty->name,
                'in_term' => $inTerm || $registrarNotTeaching,
                'qualified_course_ids' => $qualifiedCourseIds,
                'eligible_course_count' => count($qualifiedCourseIds),
                'term_course_count' => $termCourseCount,
                'course_labels' => $courseLabels,
                'detail' => implode(', ', $courseLabels) ?: 'No eligible courses recorded',
                'load_limit' => $limit,
                'load_units' => $load,
                'load_stage' => $loadStage,
                'assigned_class_count' => $assignedClassCount,
                'load' => $loadStage !== 'none'
                    ? $load.' / '.($limit ?? 'not recorded').' units'
                    : 'Unassigned · limit '.($limit ?? 'not recorded').' units',
                'load_source' => $override instanceof FacultyTermLoadOverride ? 'Faculty-specific approved load' : 'Term teaching-load default',
                'course_count' => match ($loadStage) {
                    'official' => $officialClasses->pluck('courseRevision.course_id')->unique()->count().' courses · official schedule',
                    'draft' => $assignedClassCount.' '.str('class')->plural($assignedClassCount).' · draft timetable',
                    default => 'No official assignments yet',
                },
                'declaration' => $declaration,
                'declaration_id' => $declaration?->id !== null ? (int) $declaration->id : null,
                'declaration_version' => $declaration?->version !== null ? (int) $declaration->version : null,
                'declared_at' => $declaration?->declared_at,
                'effective_mode' => $effectiveMode,
                'registrar_not_teaching' => $registrarNotTeaching,
                'registrar_not_teaching_reason' => $registrarNotTeaching ? $override->reason : null,
                'load_override_id' => $override instanceof FacultyTermLoadOverride ? (int) $override->id : null,
                'status_label' => $statusLabel,
                'status' => $statusLabel,
                'teaching_windows' => $declaration?->normalizedTeachingWindows() ?? [],
                'hard_unavailability' => is_array($declaration?->hard_unavailability) ? $declaration->hard_unavailability : [],
                'reminder_status' => $reminderStatus,
                'reminder_at' => $reminderAt,
                'reminder_event' => $request,
                'needs_grid_review' => $needsGridReview,
                'needs_hours_review' => $needsHoursReview,
                'hours_review_summary' => $hoursReviewSummary,
                'needs_review' => $needsReview,
                'outside_grid_windows' => $outsideGridWindows,
                'has_official_impact' => $affectedMeetings !== [],
                'affected_meetings' => $affectedMeetings,
            ]);
        }

        $inTermRows = $allRows->filter(fn (array $row): bool => $row['in_term']);

        return [
            'term_id' => (int) $term->id,
            'has_active_core_calendar' => $activePackage instanceof TermCalendarPackage,
            'active_calendar_package' => $activePackage,
            'total_faculty' => $inTermRows->count(),
            'replied_count' => $inTermRows->filter(fn (array $row): bool => $row['declaration'] !== null)->count(),
            'no_response_count' => $inTermRows->filter(fn (array $row): bool => $row['declaration'] === null && ! $row['registrar_not_teaching'])->count(),
            'cannot_teach_count' => $inTermRows->filter(fn (array $row): bool => $row['registrar_not_teaching'] || $row['effective_mode'] === FacultyAvailabilityDeclaration::ModeCannotTeach)->count(),
            'needs_grid_review_count' => $inTermRows->filter(fn (array $row): bool => $row['needs_grid_review'])->count(),
            'needs_hours_review_count' => $inTermRows->filter(fn (array $row): bool => $row['needs_hours_review'])->count(),
            'needs_review_count' => $inTermRows->filter(fn (array $row): bool => $row['needs_review'])->count(),
            'late_impact_count' => count($unresolvedLateImpacts),
            'faculty_rows' => $inTermRows->all(),
            'all_faculty_rows' => $allRows->all(),
            'unresolved_late_impacts' => $unresolvedLateImpacts,
        ];
    }

    /**
     * Build the single-source-of-truth projection for a single Faculty member in a Term.
     *
     * @return array{
     *     term_id: int,
     *     faculty_user_id: int,
     *     has_active_core_calendar: bool,
     *     active_calendar_package: TermCalendarPackage|null,
     *     has_declaration: bool,
     *     latest_declaration: FacultyAvailabilityDeclaration|null,
     *     effective_mode: string|null,
     *     registrar_not_teaching: bool,
     *     registrar_not_teaching_reason: string|null,
     *     status_label: string,
     *     teaching_windows: list<array{day_of_week:int,start_time:string,end_time:string}>,
     *     hard_unavailability: list<array{day_of_week:int,starts_at:string,ends_at:string}>,
     *     needs_grid_review: bool,
     *     needs_hours_review: bool,
     *     hours_review_summary: string|null,
     *     needs_review: bool,
     *     outside_grid_windows: list<array{day_of_week:int,start_time:string,end_time:string,reason:string}>,
     *     has_official_impact: bool,
     *     late_impact: array<string, mixed>|null,
     *     late_impact_meetings: list<array<string, mixed>>,
     *     revision_events: list<array<string, mixed>>,
     *     affected_meetings: list<array<string, mixed>>
     * }
     */
    public function forFaculty(Term $term, User $faculty): array
    {
        $termProjection = $this->forTerm($term);
        $facultyId = (int) $faculty->id;
        $row = $termProjection['all_faculty_rows'][$facultyId] ?? null;

        /** @var FacultyAvailabilityDeclaration|null $latestDeclaration */
        $latestDeclaration = $row['declaration'] ?? FacultyAvailabilityDeclaration::query()
            ->where('term_id', $term->id)
            ->where('faculty_user_id', $facultyId)
            ->latest('version')
            ->first();

        $activePackage = $termProjection['active_calendar_package'];
        $outsideGridWindows = $latestDeclaration instanceof FacultyAvailabilityDeclaration
            ? $latestDeclaration->windowsOutsideGrid($activePackage)
            : [];

        $lateImpact = collect($termProjection['unresolved_late_impacts'])
            ->firstWhere('faculty_user_id', $facultyId);

        $revisionEvents = ScheduleRevisionEvent::query()
            ->where('term_id', $term->id)
            ->with([
                'sectionMeeting.schedulingDemand.courseComponent.courseSpecification.course',
                'sectionMeeting.schedulingDemand.sectionDeliveryGroup.section',
                'changedBy',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->filter(function (ScheduleRevisionEvent $event) use ($facultyId): bool {
                $meetingFacultyId = $event->sectionMeeting?->faculty_user_id;
                $oldFacultyId = $event->old_snapshot_json['faculty_user_id'] ?? null;
                $newFacultyId = $event->new_snapshot_json['faculty_user_id'] ?? null;

                return (int) $meetingFacultyId === $facultyId
                    || (int) $oldFacultyId === $facultyId
                    || (int) $newFacultyId === $facultyId;
            })
            ->map(fn (ScheduleRevisionEvent $event): array => $this->formatRevisionEvent($event))
            ->values()
            ->all();

        $lateImpactMeetings = is_array($lateImpact)
            ? array_map(
                fn (array $meeting): array => [
                    ...$meeting,
                    'source' => 'late_availability_impact',
                    'reason' => $lateImpact['reason'],
                    'recorded_at' => $lateImpact['recorded_at'],
                    'declaration_version' => $lateImpact['declaration_version'],
                ],
                $lateImpact['affected_meetings'] ?? [],
            )
            : [];

        $fallbackOverride = $row === null
            ? FacultyTermLoadOverride::query()
                ->where('term_id', $term->id)
                ->where('faculty_user_id', $facultyId)
                ->where('is_active', true)
                ->first()
            : null;
        $registrarNotTeaching = $row !== null
            ? (bool) ($row['registrar_not_teaching'] ?? false)
            : ($fallbackOverride instanceof FacultyTermLoadOverride && $fallbackOverride->allowedLoadUnits() <= 0.0);
        $registrarNotTeachingReason = $row !== null
            ? ($row['registrar_not_teaching_reason'] ?? null)
            : ($registrarNotTeaching ? $fallbackOverride->reason : null);

        $statusLabel = match (true) {
            $registrarNotTeaching => 'Not teaching (Registrar)',
            $latestDeclaration instanceof FacultyAvailabilityDeclaration => $latestDeclaration->modeLabel(),
            default => 'No response',
        };

        return [
            'term_id' => (int) $term->id,
            'faculty_user_id' => $facultyId,
            'has_active_core_calendar' => $termProjection['has_active_core_calendar'],
            'active_calendar_package' => $activePackage,
            'has_declaration' => $latestDeclaration instanceof FacultyAvailabilityDeclaration,
            'latest_declaration' => $latestDeclaration,
            'effective_mode' => $latestDeclaration?->effectiveMode(),
            'registrar_not_teaching' => $registrarNotTeaching,
            'registrar_not_teaching_reason' => is_string($registrarNotTeachingReason) && trim($registrarNotTeachingReason) !== ''
                ? trim($registrarNotTeachingReason)
                : null,
            'status_label' => $statusLabel,
            'teaching_windows' => $latestDeclaration?->normalizedTeachingWindows() ?? [],
            'hard_unavailability' => is_array($latestDeclaration?->hard_unavailability) ? $latestDeclaration->hard_unavailability : [],
            'needs_grid_review' => $outsideGridWindows !== [],
            'needs_hours_review' => (bool) ($row['needs_hours_review'] ?? false),
            'hours_review_summary' => $row['hours_review_summary'] ?? null,
            'needs_review' => $outsideGridWindows !== [] || (bool) ($row['needs_hours_review'] ?? false),
            'outside_grid_windows' => $outsideGridWindows,
            'has_official_impact' => is_array($lateImpact),
            'late_impact' => is_array($lateImpact) ? $lateImpact : null,
            'late_impact_meetings' => $lateImpactMeetings,
            'revision_events' => $revisionEvents,
            'affected_meetings' => [...$lateImpactMeetings, ...$revisionEvents],
        ];
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private function resolveReminderStatus(?OperationalEvent $request): array
    {
        if (! $request instanceof OperationalEvent) {
            return ['Not sent', null];
        }

        if ($request->status === OperationalEvent::StatusFailed) {
            return ['Reminder failed', $request->failed_at ?? $request->processed_at ?? $request->occurred_at];
        }

        return ['Reminder sent', $request->sent_at ?? $request->processed_at ?? $request->occurred_at];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOfficialMeeting(SectionMeeting $meeting): array
    {
        $specification = $meeting->schedulingDemand?->courseComponent?->courseSpecification;
        $course = $specification?->course;
        $section = $meeting->schedulingDemand?->sectionDeliveryGroup?->section;
        $day = (int) $meeting->day_of_week;

        return [
            'section_meeting_id' => (int) $meeting->id,
            'section_id' => $section?->id !== null ? (int) $section->id : null,
            'course_code' => $course?->code,
            'course_title' => $specification?->title,
            'section_code' => $section?->code,
            'day_of_week' => $day,
            'day_label' => SectionMeeting::dayOptions()[$day] ?? 'Unknown day',
            'starts_at' => (string) $meeting->starts_at,
            'ends_at' => (string) $meeting->ends_at,
            'time_label' => $this->formatClock((string) $meeting->starts_at).'–'.$this->formatClock((string) $meeting->ends_at),
            'modality' => $meeting->modality,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRevisionEvent(ScheduleRevisionEvent $event): array
    {
        $meeting = $event->sectionMeeting;
        $specification = $meeting?->schedulingDemand?->courseComponent?->courseSpecification;
        $course = $specification?->course;
        $section = $meeting?->schedulingDemand?->sectionDeliveryGroup?->section;
        $snapshot = is_array($event->new_snapshot_json) && $event->new_snapshot_json !== []
            ? $event->new_snapshot_json
            : (is_array($event->old_snapshot_json) ? $event->old_snapshot_json : []);
        $day = (int) ($meeting?->day_of_week ?? $snapshot['day_of_week'] ?? 0);
        $startsAt = (string) ($meeting?->starts_at ?? $snapshot['starts_at'] ?? '');
        $endsAt = (string) ($meeting?->ends_at ?? $snapshot['ends_at'] ?? '');

        return [
            'source' => 'schedule_revision',
            'event_id' => (int) $event->id,
            'section_meeting_id' => (int) $event->section_meeting_id,
            'change_type' => (string) $event->change_type,
            'change_type_label' => ScheduleRevisionEvent::changeTypeOptions()[$event->change_type] ?? $event->change_type,
            'reason' => (string) $event->reason,
            'effective_date' => $event->effective_date,
            'recorded_at' => $event->created_at,
            'changed_by_name' => $event->changedBy?->name,
            'course_code' => $course?->code,
            'course_title' => $specification?->title,
            'section_code' => $section?->code,
            'day_of_week' => $day,
            'day_label' => SectionMeeting::dayOptions()[$day] ?? 'Unknown day',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'time_label' => ($startsAt !== '' && $endsAt !== '') ? $this->formatClock($startsAt).'–'.$this->formatClock($endsAt) : '-',
            'old_snapshot' => $event->old_snapshot_json,
            'new_snapshot' => $event->new_snapshot_json,
        ];
    }

    private function formatClock(string $time): string
    {
        return CarbonImmutable::createFromFormat(
            '!H:i',
            substr($time, 0, 5),
            (string) config('app.display_timezone', 'Asia/Manila'),
        )->format('g:i A');
    }
}
