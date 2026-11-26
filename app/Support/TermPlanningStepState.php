<?php

namespace App\Support;

use App\Actions\Scheduling\FacultyAvailabilityStatusProjection;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermResources;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Support\Collection;

final class TermPlanningStepState
{
    public const RESOURCE_BLOCKER_CODES = [
        'faculty_qualifications_missing',
        'fixed_faculty_availability_missing',
        'fixed_faculty_cannot_teach',
        'fixed_faculty_no_usable_time',
        'faculty_demand_uncovered',
        'faculty_unit_capacity_exceeded',
        'faculty_hours_exceeded',
        'rooms_unavailable',
        'rooms_unsuitable',
        'room_hours_exceeded',
    ];

    /**
     * Evaluate unified completion and sequential hard-gating state for a Term's 4 planning steps.
     *
     * @param  array<string, mixed>|null  $preloaded
     * @return array{
     *   lifecycle_mode: string,
     *   is_live_or_closed: bool,
     *   calendar_done: bool,
     *   classes_done: bool,
     *   resources_done: bool,
     *   timetable_done: bool,
     *   has_confirmed_class: bool,
     *   steps: array<string, array{
     *     number: int,
     *     key: string,
     *     nav_key: string,
     *     label: string,
     *     done: bool,
     *     unlocked: bool,
     *     locked: bool,
     *     locked_reason: ?string,
     *     required_step_key: ?string,
     *     required_step_number: ?int,
     *     required_step_label: ?string,
     *     required_step_url: ?string,
     *   }>
     * }
     */
    public static function evaluate(Term $term, bool $readOnly = false, ?array $preloaded = null): array
    {
        $isClosedMode = $term->state === Term::StateClosed;
        $hasPublishedVersion = self::resolveHasPublishedVersion($term, $preloaded);
        $isLiveMode = ! $isClosedMode && $hasPublishedVersion;
        $lifecycleMode = $isClosedMode ? 'closed' : ($isLiveMode ? 'live' : 'draft');
        $isLiveOrClosed = $lifecycleMode !== 'draft';

        $activePackage = self::resolveActivePackage($term, $preloaded);
        $hasActiveCalendar = $activePackage instanceof TermCalendarPackage;
        $hasDraftCalendar = self::resolveHasDraftCalendar($term, $activePackage, $preloaded);
        $calendarDone = $isLiveOrClosed || ($hasActiveCalendar && ! $hasDraftCalendar);

        $cohortCount = self::resolveCohortCount($term, $preloaded);
        [$recordedClassesCount, $confirmedClassesCount, $contextConfirmedCount] = self::resolveClassCounts($term, $activePackage, $preloaded);
        $demandsCount = self::resolveDemandsCount($term, $preloaded);
        $unreadyClasses = $isLiveOrClosed ? 0 : max(0, $recordedClassesCount - $contextConfirmedCount);

        $hasConfirmedClass = $confirmedClassesCount >= 1;
        $classesDone = $isLiveOrClosed || ($cohortCount > 0 && $recordedClassesCount > 0 && $unreadyClasses === 0 && $demandsCount > 0);

        $hasRooms = self::resolveHasRooms($preloaded);
        $hasOfferings = self::resolveHasOfferings($term, $preloaded);
        $missingQualificationsCount = self::resolveMissingQualificationsCount($term, $isLiveMode, $preloaded);
        $effectiveMissingQualifications = $isLiveOrClosed ? 0 : $missingQualificationsCount;

        $resourceBlockerCodes = [];
        $totalFaculty = 0;

        if (! $isLiveOrClosed) {
            if ($preloaded !== null && array_key_exists('readiness', $preloaded)) {
                $resourceBlockerCodes = self::extractResourceBlockerCodes($preloaded['readiness']);
            } elseif ($calendarDone && $classesDone && $hasRooms && $effectiveMissingQualifications === 0) {
                $readiness = app(ReadyTermPlanningProjection::class)->forTerm($term);
                $resourceBlockerCodes = self::extractResourceBlockerCodes($readiness);
            }

            if ($preloaded !== null && array_key_exists('availabilityProjection', $preloaded)) {
                $totalFaculty = (int) ($preloaded['availabilityProjection']['total_faculty'] ?? 0);
            } elseif ($calendarDone && $classesDone && $hasRooms && $effectiveMissingQualifications === 0 && $resourceBlockerCodes === [] && ! $hasOfferings) {
                $availabilityProjection = app(FacultyAvailabilityStatusProjection::class)->forTerm($term, includeOfficialAssignments: false);
                $totalFaculty = (int) ($availabilityProjection['total_faculty'] ?? 0);
            }
        }

        $resourcesDone = $isLiveOrClosed || (
            $calendarDone
            && $classesDone
            && $hasRooms
            && $effectiveMissingQualifications === 0
            && $resourceBlockerCodes === []
            && ($totalFaculty > 0 || $hasOfferings)
        );

        $timetableDone = $hasPublishedVersion || $isClosedMode;
        $hasRunsOrPublished = $hasPublishedVersion || self::resolveHasRuns($term, $preloaded);

        $bypassLocks = $readOnly || $isLiveOrClosed;

        $calendarUrl = TermCalendar::getUrl(['termId' => $term->id]);
        $classesUrl = TermClasses::getUrl(['termId' => $term->id]);
        $resourcesUrl = TermResources::getUrl(['termId' => $term->id]);

        $classesUnlocked = $bypassLocks || $hasActiveCalendar;
        $resourcesUnlocked = $bypassLocks || ($hasActiveCalendar && $confirmedClassesCount >= 1);
        $timetableUnlocked = $bypassLocks || $hasRunsOrPublished || (
            $hasActiveCalendar
            && $confirmedClassesCount >= 1
            && $missingQualificationsCount === 0
            && $hasRooms
        );

        $classesStep = [
            'number' => 2,
            'key' => 'classes',
            'nav_key' => 'classes',
            'label' => 'Sections & classes',
            'done' => $classesDone,
            'unlocked' => $classesUnlocked,
            'locked' => ! $classesUnlocked,
            'locked_reason' => $classesUnlocked
                ? null
                : 'Activate the term calendar first before setting up student sections and classes.',
            'required_step_key' => $classesUnlocked ? null : 'calendar',
            'required_step_number' => $classesUnlocked ? null : 1,
            'required_step_label' => $classesUnlocked ? null : '1. Calendar',
            'required_step_url' => $classesUnlocked ? null : $calendarUrl,
        ];

        $resourcesRequiredIsCalendar = ! $hasActiveCalendar;
        $resourcesStep = [
            'number' => 3,
            'key' => 'resources',
            'nav_key' => 'resources',
            'label' => 'Faculty & rooms',
            'done' => $resourcesDone,
            'unlocked' => $resourcesUnlocked,
            'locked' => ! $resourcesUnlocked,
            'locked_reason' => $resourcesUnlocked
                ? null
                : ($resourcesRequiredIsCalendar
                    ? 'Activate the term calendar first before assigning faculty and rooms.'
                    : 'Add at least one student section and confirm its classes before assigning faculty and rooms.'),
            'required_step_key' => $resourcesUnlocked ? null : ($resourcesRequiredIsCalendar ? 'calendar' : 'classes'),
            'required_step_number' => $resourcesUnlocked ? null : ($resourcesRequiredIsCalendar ? 1 : 2),
            'required_step_label' => $resourcesUnlocked ? null : ($resourcesRequiredIsCalendar ? '1. Calendar' : '2. Sections & classes'),
            'required_step_url' => $resourcesUnlocked ? null : ($resourcesRequiredIsCalendar ? $calendarUrl : $classesUrl),
        ];

        if ($timetableUnlocked) {
            $timetableLockedReason = null;
            $timetableRequiredKey = null;
            $timetableRequiredNumber = null;
            $timetableRequiredLabel = null;
            $timetableRequiredUrl = null;
        } elseif (! $hasActiveCalendar) {
            $timetableLockedReason = 'Activate the term calendar first before opening Timetable.';
            $timetableRequiredKey = 'calendar';
            $timetableRequiredNumber = 1;
            $timetableRequiredLabel = '1. Calendar';
            $timetableRequiredUrl = $calendarUrl;
        } elseif ($confirmedClassesCount < 1) {
            $timetableLockedReason = 'Add at least one student section and confirm its classes before opening Timetable.';
            $timetableRequiredKey = 'classes';
            $timetableRequiredNumber = 2;
            $timetableRequiredLabel = '2. Sections & classes';
            $timetableRequiredUrl = $classesUrl;
        } else {
            $timetableLockedReason = 'Make sure every offered subject has a qualified teacher and at least one active room is available before opening Timetable.';
            $timetableRequiredKey = 'resources';
            $timetableRequiredNumber = 3;
            $timetableRequiredLabel = '3. Faculty & rooms';
            $timetableRequiredUrl = $resourcesUrl;
        }

        $timetableStep = [
            'number' => 4,
            'key' => 'timetable',
            'nav_key' => 'generate',
            'label' => 'Timetable',
            'done' => $timetableDone,
            'unlocked' => $timetableUnlocked,
            'locked' => ! $timetableUnlocked,
            'locked_reason' => $timetableLockedReason,
            'required_step_key' => $timetableRequiredKey,
            'required_step_number' => $timetableRequiredNumber,
            'required_step_label' => $timetableRequiredLabel,
            'required_step_url' => $timetableRequiredUrl,
        ];

        return [
            'lifecycle_mode' => $lifecycleMode,
            'is_live_or_closed' => $isLiveOrClosed,
            'calendar_done' => $calendarDone,
            'classes_done' => $classesDone,
            'resources_done' => $resourcesDone,
            'timetable_done' => $timetableDone,
            'has_confirmed_class' => $hasConfirmedClass,
            'steps' => [
                'calendar' => [
                    'number' => 1,
                    'key' => 'calendar',
                    'nav_key' => 'overview',
                    'label' => 'Calendar',
                    'done' => $calendarDone,
                    'unlocked' => true,
                    'locked' => false,
                    'locked_reason' => null,
                    'required_step_key' => null,
                    'required_step_number' => null,
                    'required_step_label' => null,
                    'required_step_url' => null,
                ],
                'classes' => $classesStep,
                'resources' => $resourcesStep,
                'timetable' => $timetableStep,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $preloaded
     */
    private static function resolveHasPublishedVersion(Term $term, ?array $preloaded): bool
    {
        if ($preloaded !== null && array_key_exists('currentVersion', $preloaded)) {
            return $preloaded['currentVersion'] instanceof PublishedTimetableVersion;
        }

        if ($preloaded !== null && array_key_exists('hasPublishedVersion', $preloaded)) {
            return (bool) $preloaded['hasPublishedVersion'];
        }

        if ($term->relationLoaded('timetableVersions')) {
            return $term->timetableVersions->contains('state', PublishedTimetableVersion::StatePublished);
        }

        return PublishedTimetableVersion::query()
            ->where('term_id', $term->id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $preloaded
     */
    private static function resolveActivePackage(Term $term, ?array $preloaded): ?TermCalendarPackage
    {
        if ($preloaded !== null && array_key_exists('activePackage', $preloaded)) {
            return $preloaded['activePackage'] instanceof TermCalendarPackage
                ? $preloaded['activePackage']
                : null;
        }

        return TermCalendarPackage::query()
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->first();
    }

    /**
     * @param  array<string, mixed>|null  $preloaded
     */
    private static function resolveHasDraftCalendar(Term $term, ?TermCalendarPackage $activePackage, ?array $preloaded): bool
    {
        if ($preloaded !== null && array_key_exists('draftPackages', $preloaded)) {
            $draftPackages = $preloaded['draftPackages'];
            if ($draftPackages instanceof Collection) {
                return $draftPackages->isNotEmpty();
            }

            return is_array($draftPackages) && $draftPackages !== [];
        }

        return TermCalendarPackage::query()
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateDraft)
            ->when(
                $activePackage instanceof TermCalendarPackage,
                fn ($query) => $query->where('version', '>', $activePackage->version),
            )
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $preloaded
     */
    private static function resolveCohortCount(Term $term, ?array $preloaded): int
    {
        if ($preloaded !== null && array_key_exists('cohorts', $preloaded)) {
            $cohorts = $preloaded['cohorts'];

            return $cohorts instanceof Collection ? $cohorts->count() : (is_array($cohorts) ? count($cohorts) : (int) $cohorts);
        }

        return TermCohort::query()->where('term_id', $term->id)->count();
    }

    /**
     * @param  array<string, mixed>|null  $preloaded
     * @return array{0: int, 1: int, 2: int} [recordedClassesCount, confirmedClassesCount, contextConfirmedCount]
     */
    private static function resolveClassCounts(Term $term, ?TermCalendarPackage $activePackage, ?array $preloaded): array
    {
        if ($preloaded !== null && array_key_exists('sourceClasses', $preloaded) && $preloaded['sourceClasses'] instanceof Collection) {
            /** @var Collection<int, Section> $sourceClasses */
            $sourceClasses = $preloaded['sourceClasses'];
            $recordedCount = $sourceClasses->count();
            $confirmedCount = $sourceClasses
                ->filter(fn (Section $section): bool => $section->confirmed_at !== null
                    && $section->termOffering?->state !== TermOffering::StateCancelled)
                ->count();

            $contextConfirmedCount = array_key_exists('contextClasses', $preloaded) && $preloaded['contextClasses'] instanceof Collection
                ? $preloaded['contextClasses']->whereNotNull('confirmed_at')->count()
                : $confirmedCount;

            return [$recordedCount, $confirmedCount, $contextConfirmedCount];
        }

        $baseQuery = Section::query()
            ->whereHas('termOffering', fn ($query) => $query
                ->where('term_id', $term->id)
                ->where('state', '!=', TermOffering::StateCancelled))
            ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed]);

        $recordedCount = (clone $baseQuery)->count();
        if ($recordedCount === 0) {
            return [0, 0, 0];
        }

        $confirmedCount = (clone $baseQuery)->whereNotNull('confirmed_at')->count();
        if ($confirmedCount === 0 || ! $activePackage instanceof TermCalendarPackage) {
            return [$recordedCount, $confirmedCount, 0];
        }

        $compatiblePackageIds = $activePackage->compatibleCoreSourceIds();
        $contextConfirmedCount = $compatiblePackageIds === []
            ? 0
            : (clone $baseQuery)
                ->whereNotNull('confirmed_at')
                ->whereIn('term_calendar_package_id', $compatiblePackageIds)
                ->count();

        return [$recordedCount, $confirmedCount, $contextConfirmedCount];
    }

    /**
     * @param  array<string, mixed>|null  $preloaded
     */
    private static function resolveDemandsCount(Term $term, ?array $preloaded): int
    {
        if ($preloaded !== null && array_key_exists('demands', $preloaded)) {
            $demands = $preloaded['demands'];

            return $demands instanceof Collection ? $demands->count() : (is_array($demands) ? count($demands) : (int) $demands);
        }

        return SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query
                ->where('term_id', $term->id)
                ->where('state', '!=', TermOffering::StateCancelled))
            ->count();
    }

    /**
     * @param  array<string, mixed>|null  $preloaded
     */
    private static function resolveHasRooms(?array $preloaded): bool
    {
        if ($preloaded !== null && array_key_exists('rooms', $preloaded)) {
            $rooms = $preloaded['rooms'];
            if ($rooms instanceof Collection) {
                return $rooms->isNotEmpty();
            }

            return is_array($rooms) ? $rooms !== [] : (bool) $rooms;
        }

        return Room::query()->where('is_active', true)->exists();
    }

    /**
     * @param  array<string, mixed>|null  $preloaded
     */
    private static function resolveHasOfferings(Term $term, ?array $preloaded): bool
    {
        if ($preloaded !== null && array_key_exists('offerings', $preloaded)) {
            $offerings = $preloaded['offerings'];
            if ($offerings instanceof Collection) {
                return $offerings->isNotEmpty();
            }

            return is_array($offerings) ? $offerings !== [] : (bool) $offerings;
        }

        return TermOffering::query()
            ->where('term_id', $term->id)
            ->where('state', '!=', TermOffering::StateCancelled)
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $preloaded
     */
    private static function resolveMissingQualificationsCount(Term $term, bool $isLiveMode, ?array $preloaded): int
    {
        if ($preloaded !== null && array_key_exists('missingQualifications', $preloaded)) {
            return max(0, (int) $preloaded['missingQualifications']);
        }

        $offerings = TermOffering::query()
            ->where('term_id', $term->id)
            ->where('state', '!=', TermOffering::StateCancelled)
            ->with([
                'curriculumEntry.courseSpecification:id,course_id,scheduling_treatment',
                'sections:id,term_offering_id,state,confirmed_at',
            ])
            ->get();

        if ($offerings->isEmpty()) {
            return 0;
        }

        if ($isLiveMode) {
            $confirmedOfferings = $offerings->filter(fn (TermOffering $offering): bool => $offering->sections
                ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])
                ->whereNotNull('confirmed_at')
                ->isNotEmpty());
            if ($confirmedOfferings->isNotEmpty()) {
                $offerings = $confirmedOfferings;
            }
        }

        $courseIds = $offerings
            ->filter(function (TermOffering $offering): bool {
                $specification = $offering->curriculumEntry?->courseSpecification;

                return $specification === null
                    || $specification->scheduling_treatment !== CourseSpecification::SchedulingExternallyArranged;
            })
            ->map(fn (TermOffering $offering): ?int => $offering->curriculumEntry?->courseSpecification?->course_id)
            ->filter()
            ->unique()
            ->values();

        if ($courseIds->isEmpty()) {
            return 0;
        }

        $qualifiedCourseIds = FacultyQualification::query()
            ->active()
            ->whereIn('course_id', $courseIds)
            ->whereHas('faculty', fn ($query) => $query->where('status', User::StatusActive))
            ->pluck('course_id')
            ->unique();

        return $courseIds->diff($qualifiedCourseIds)->count();
    }

    /**
     * @param  array<string, mixed>|null  $preloaded
     */
    private static function resolveHasRuns(Term $term, ?array $preloaded): bool
    {
        if ($preloaded !== null && array_key_exists('allRuns', $preloaded)) {
            $allRuns = $preloaded['allRuns'];
            if ($allRuns instanceof Collection) {
                return $allRuns->isNotEmpty();
            }

            return is_array($allRuns) ? $allRuns !== [] : (bool) $allRuns;
        }

        return ScheduleGenerationRun::query()
            ->where('term_id', $term->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $readiness
     * @return list<string>
     */
    private static function extractResourceBlockerCodes(?array $readiness): array
    {
        if ($readiness === null || empty($readiness['blockers'])) {
            return [];
        }

        return collect($readiness['blockers'])
            ->pluck('code')
            ->filter(fn (mixed $code): bool => is_string($code) && in_array($code, self::RESOURCE_BLOCKER_CODES, true))
            ->values()
            ->all();
    }
}
