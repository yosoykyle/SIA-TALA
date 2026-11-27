<?php

namespace App\Filament\Student\Pages;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\StudentHub\RecordStudentScheduleAccess;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\Enrollment;
use App\Models\ScheduleRevisionEvent;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermDatedException;
use App\Models\User;
use App\Support\RegistrationSchedulePresentation;
use App\Support\TeachingWeekSummary;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class ScheduleView extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Class schedule';

    protected static ?string $title = 'Class schedule';

    protected ?string $subheading = 'Your registered classes, meeting times, and term calendar.';

    protected string $view = 'filament.student.pages.schedule-view';

    #[Url]
    public ?int $termId = null;

    #[Url]
    public string $activeTab = 'schedule';

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(panel: 'student') => 'Overview', $this->getTitle()];
    }

    public function mount(): void
    {
        if (! in_array($this->activeTab, ['schedule', 'calendar'], true)) {
            $this->activeTab = 'schedule';
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $selected = $this->resolveSelectedEnrollment($user);
        $this->termId = $selected instanceof Enrollment ? (int) $selected->term_id : null;

        app(RecordStudentScheduleAccess::class)->execute($user, request(), RecordStudentScheduleAccess::ActionView, $selected);
    }

    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['schedule', 'calendar'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function selectTerm(int $termId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $match = app(CurrentOfficialEnrollmentResolver::class)->forStudentInTerm($user, $termId);

        if (! $match instanceof Enrollment) {
            return;
        }

        $this->termId = (int) $match->term_id;

        app(RecordStudentScheduleAccess::class)->execute($user, request(), RecordStudentScheduleAccess::ActionView, $match);
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('printSchedule')
                ->label('Print schedule')
                ->icon('heroicon-o-printer')
                ->url(function (): string {
                    $user = auth()->user();
                    $selected = $user instanceof User ? $this->resolveSelectedEnrollment($user) : null;

                    return route(
                        'student.schedule.print',
                        $selected instanceof Enrollment ? ['term_id' => $selected->term_id] : [],
                    );
                })
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->hasPrintableSchedule()),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $actor = auth()->user();

        if ($actor instanceof User) {
            $actor->loadMissing('studentProfile.program');
        }

        $activeEnrollments = $actor instanceof User
            ? app(CurrentOfficialEnrollmentResolver::class)->allActiveForStudent($actor)
            : new EloquentCollection;
        $selectedEnrollment = $actor instanceof User ? $this->resolveSelectedEnrollment($actor, $activeEnrollments) : null;
        $selectedEnrollment?->loadMissing(['studentProfile.program', 'term.academicYear']);
        $studentProfile = $selectedEnrollment?->studentProfile ?? ($actor instanceof User ? $actor->studentProfile : null);
        $schedulePresentation = $selectedEnrollment instanceof Enrollment
            ? app(RegistrationSchedulePresentation::class)->currentForEnrollment($selectedEnrollment)
            : null;
        $externallyArrangedClasses = $selectedEnrollment instanceof Enrollment
            ? $this->externallyArrangedClasses($selectedEnrollment)
            : [];
        $recentScheduleRevisions = self::recentScheduleRevisionsForEnrollment($selectedEnrollment);

        $calendarTerm = $selectedEnrollment?->term
            ?? Term::query()->with('academicYear')->where('state', Term::StateActive)->latest('starts_on')->first();

        $package = $calendarTerm instanceof Term
            ? TermCalendarPackage::query()
                ->where('term_id', $calendarTerm->id)
                ->where('state', TermCalendarPackage::StateActive)
                ->with(['windows', 'teachingGridRows', 'datedExceptions'])
                ->latest('version')
                ->first()
            : null;

        $examinationPeriod = $package?->windows->first(
            fn (TermCalendarWindow $window): bool => $window->window_type === TermCalendarWindow::TypeExaminationPeriod,
        );
        $termTeachingRange = match (true) {
            $package?->classes_start_on !== null && $package?->classes_end_on !== null => $package->classes_start_on->format('M j').'–'.$package->classes_end_on->format('M j, Y'),
            $calendarTerm?->starts_on !== null && $calendarTerm?->ends_on !== null => $calendarTerm->starts_on->format('M j').'–'.$calendarTerm->ends_on->format('M j, Y'),
            default => null,
        };
        $displayTz = (string) config('app.display_timezone', 'Asia/Manila');
        $today = CarbonImmutable::now($displayTz)->startOfDay();
        $upcomingHolidays = $package instanceof TermCalendarPackage
            ? $package->datedExceptions
                ->filter(fn (TermDatedException $exception): bool => $exception->blocks_teaching
                    && $exception->ends_on !== null
                    && CarbonImmutable::parse($exception->ends_on->toDateString(), $displayTz)->gte($today))
                ->sortBy('starts_on')
                ->take(5)
                ->map(fn (TermDatedException $exception): array => [
                    'label' => $exception->label ?: 'No-class day',
                    'date_label' => $exception->starts_on !== null && $exception->ends_on !== null && ! $exception->starts_on->isSameDay($exception->ends_on)
                        ? $exception->starts_on->format('M j').'–'.$exception->ends_on->format('M j')
                        : ($exception->starts_on?->format('M j') ?? ''),
                ])
                ->values()
                ->all()
            : [];

        $termSwitcherOptions = $activeEnrollments
            ->map(fn (Enrollment $enrollment): array => [
                'term_id' => (int) $enrollment->term_id,
                'label' => collect([
                    $enrollment->term?->label,
                    $this->formatAcademicYearLabel($enrollment->term?->academicYear?->label),
                ])->filter()->implode(' · ') ?: 'Term #'.$enrollment->term_id,
                'is_selected' => $selectedEnrollment instanceof Enrollment && (int) $selectedEnrollment->term_id === (int) $enrollment->term_id,
            ])
            ->values()
            ->all();

        $selectedTermFullLabel = collect([
            $calendarTerm?->label,
            $this->formatAcademicYearLabel($calendarTerm?->academicYear?->label),
        ])->filter()->implode(' · ') ?: null;

        return [
            'activeTab' => in_array($this->activeTab, ['schedule', 'calendar'], true) ? $this->activeTab : 'schedule',
            'activeTermOptions' => $termSwitcherOptions,
            'studentName' => $actor instanceof User ? (string) $actor->name : null,
            'studentNumber' => $studentProfile?->student_number,
            'programName' => $studentProfile?->program?->name ?? $studentProfile?->program?->code,
            'selectedTermLabel' => $calendarTerm?->label,
            'selectedTermFullLabel' => $selectedTermFullLabel,
            'termTeachingRange' => $termTeachingRange,
            'examinationPeriod' => $examinationPeriod,
            'upcomingHolidays' => $upcomingHolidays,
            'recentScheduleRevisions' => $recentScheduleRevisions,
            'schedulePresentation' => $schedulePresentation,
            'externallyArrangedClasses' => $externallyArrangedClasses,
            'hasOfficialEnrollment' => $selectedEnrollment instanceof Enrollment,
            'calendarPackage' => $package,
            'teachingPeriod' => $calendarTerm instanceof Term ? $this->teachingPeriodSummary($calendarTerm, $package) : null,
            'teachingWeekRows' => $package instanceof TermCalendarPackage
                ? app(TeachingWeekSummary::class)->rows($package)
                : [],
            'academicWindows' => $this->academicWindows($package),
            'datedExceptions' => $this->datedExceptionsSummary($package),
        ];
    }

    /**
     * @return array{
     *     classes_start_iso: ?string,
     *     classes_start_label: ?string,
     *     classes_end_iso: ?string,
     *     classes_end_label: ?string,
     *     admin_start_iso: ?string,
     *     admin_start_label: ?string,
     *     admin_end_iso: ?string,
     *     admin_end_label: ?string,
     *     teaching_hours: string,
     *     teaching_hours_note: string,
     *     special_term_basis: ?string,
     *     updated_at_iso: ?string,
     *     updated_at_label: ?string
     * }|null
     */
    private function teachingPeriodSummary(Term $term, ?TermCalendarPackage $package): ?array
    {
        if (! $package instanceof TermCalendarPackage) {
            return null;
        }

        $start = $package->classes_start_on;
        $end = $package->classes_end_on;
        $adminStart = $package->administrative_starts_on;
        $adminEnd = $package->administrative_ends_on;

        $adminDiffers = $adminStart !== null
            && $adminEnd !== null
            && ($adminStart->toDateString() !== $start?->toDateString()
                || $adminEnd->toDateString() !== $end?->toDateString());

        $rows = $package->teachingGridRows->sortBy('day_of_week')->values();
        $dayCount = $rows->count();
        $dayOptions = SectionMeeting::dayOptions();

        if ($dayCount === 0) {
            $teachingHours = 'Not configured';
            $teachingHoursNote = 'No approved teaching days recorded.';
        } else {
            $shortDays = $rows->map(fn ($row): string => substr($dayOptions[(int) $row->day_of_week] ?? 'Day', 0, 3))->values()->all();
            $dayNumbers = $rows->pluck('day_of_week')->map(fn (mixed $day): int => (int) $day)->values()->all();
            $daySpan = $dayCount > 2 && $dayNumbers === range((int) $rows->first()->day_of_week, (int) $rows->last()->day_of_week)
                ? $shortDays[0].'–'.$shortDays[$dayCount - 1]
                : implode(', ', $shortDays);

            $uniqueRanges = $rows->map(
                fn ($row): string => CarbonImmutable::parse($row->starts_at)->format('g:i A').'–'.CarbonImmutable::parse($row->ends_at)->format('g:i A'),
            )->unique()->values();

            $teachingHours = $uniqueRanges->count() === 1
                ? $daySpan.' · '.$uniqueRanges->first()
                : $daySpan.' (varies by day)';

            $breakCount = $rows->sum(fn ($row): int => count($row->breaks ?? []));
            $teachingHoursNote = $dayCount.' '.str('teaching day')->plural($dayCount).'/week'
                .($breakCount > 0 ? ' · '.$breakCount.' '.str('break')->plural($breakCount) : '');
        }

        $displayTz = (string) config('app.display_timezone', 'Asia/Manila');

        return [
            'classes_start_iso' => $start?->toDateString(),
            'classes_start_label' => $start ? ($start->year === $end?->year ? $start->format('M j') : $start->format('M j, Y')) : null,
            'classes_end_iso' => $end?->toDateString(),
            'classes_end_label' => $end?->format('M j, Y'),
            'admin_start_iso' => $adminDiffers ? $adminStart->toDateString() : null,
            'admin_start_label' => $adminDiffers ? ($adminStart->year === $adminEnd->year ? $adminStart->format('M j') : $adminStart->format('M j, Y')) : null,
            'admin_end_iso' => $adminDiffers ? $adminEnd->toDateString() : null,
            'admin_end_label' => $adminDiffers ? $adminEnd->format('M j, Y') : null,
            'teaching_hours' => $teachingHours,
            'teaching_hours_note' => $teachingHoursNote,
            'special_term_basis' => $term->type === Term::TypeSpecialTerm && filled($package->special_term_schedule_basis)
                ? (string) $package->special_term_schedule_basis
                : null,
            'updated_at_iso' => $package->updated_at?->toIso8601String(),
            'updated_at_label' => $package->updated_at?->copy()->timezone($displayTz)->format('M j, Y'),
        ];
    }

    /**
     * @return list<array{
     *     type: string,
     *     label: string,
     *     icon: string,
     *     status: string,
     *     status_color: string,
     *     is_highlighted: bool,
     *     opens_on_iso: ?string,
     *     opens_on_label: ?string,
     *     closes_on_iso: ?string,
     *     closes_on_label: ?string,
     *     closes_at_iso: ?string,
     *     cutoff_label: ?string,
     *     note: ?string
     * }>
     */
    private function academicWindows(?TermCalendarPackage $package): array
    {
        $gateService = app(CalendarPhaseGateService::class);
        $displayTz = (string) config('app.display_timezone', 'Asia/Manila');
        $now = CarbonImmutable::now($displayTz);

        return collect(TermCalendarWindow::typeOptions())
            ->map(function (string $label, string $type) use ($package, $gateService, $now): array {
                $window = $package?->windows->firstWhere('window_type', $type);
                $bounds = $window instanceof TermCalendarWindow ? $gateService->windowBounds($window) : null;

                $status = match (true) {
                    $bounds === null => 'Not set',
                    $now->lt($bounds['opens_at']) => 'Upcoming',
                    $now->gt($bounds['closes_at']) => 'Closed',
                    default => 'Open',
                };

                $statusColor = match ($status) {
                    'Open' => 'success',
                    'Upcoming' => 'warning',
                    default => 'gray',
                };

                $icon = match ($type) {
                    TermCalendarWindow::TypeEnrollment => 'heroicon-o-clipboard-document-check',
                    TermCalendarWindow::TypeLateEnrollment => 'heroicon-o-clock',
                    TermCalendarWindow::TypeEnrollmentAdjustment => 'heroicon-o-arrows-right-left',
                    TermCalendarWindow::TypeCourseDrop => 'heroicon-o-minus-circle',
                    TermCalendarWindow::TypeExaminationPeriod => 'heroicon-o-academic-cap',
                    default => 'heroicon-o-pencil-square',
                };

                $cutoffVerb = $type === TermCalendarWindow::TypeExaminationPeriod ? 'Ends' : 'Closes';
                $cutoffLabel = $bounds !== null
                    ? $cutoffVerb.' '.$bounds['closes_at']->format('g:i A')
                    : null;

                $note = match ($type) {
                    TermCalendarWindow::TypeExaminationPeriod => 'Scheduled examination week for the term.',
                    TermCalendarWindow::TypeGradeEntry => 'Official grades are released by the Registrar after review.',
                    default => null,
                };

                return [
                    'type' => $type,
                    'label' => $label,
                    'icon' => $icon,
                    'status' => $status,
                    'status_color' => $statusColor,
                    'is_highlighted' => $type === TermCalendarWindow::TypeExaminationPeriod,
                    'opens_on_iso' => $window?->opens_on?->toDateString(),
                    'opens_on_label' => ($window?->opens_on && $window?->closes_on)
                        ? ($window->opens_on->year === $window->closes_on->year
                            ? $window->opens_on->format('M j')
                            : $window->opens_on->format('M j, Y'))
                        : null,
                    'closes_on_iso' => $window?->closes_on?->toDateString(),
                    'closes_on_label' => $window?->closes_on?->format('M j, Y'),
                    'closes_at_iso' => $bounds !== null ? $bounds['closes_at']->toIso8601String() : null,
                    'cutoff_label' => $cutoffLabel,
                    'note' => $note,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     upcoming: list<array{label: string, type_label: ?string, starts_on_iso: ?string, starts_on_label: ?string, ends_on_iso: ?string, ends_on_label: ?string, is_same_day: bool, blocks_teaching: bool, timing_label: ?string, timing_color: string}>,
     *     past: list<array{label: string, type_label: ?string, starts_on_iso: ?string, starts_on_label: ?string, ends_on_iso: ?string, ends_on_label: ?string, is_same_day: bool, blocks_teaching: bool, timing_label: ?string, timing_color: string}>
     * }
     */
    private function datedExceptionsSummary(?TermCalendarPackage $package): array
    {
        if (! $package instanceof TermCalendarPackage) {
            return ['upcoming' => [], 'past' => []];
        }

        $displayTz = (string) config('app.display_timezone', 'Asia/Manila');
        $today = CarbonImmutable::now($displayTz)->startOfDay();
        $upcoming = [];
        $past = [];

        /** @var Collection<int, TermDatedException> $sorted */
        $sorted = $package->datedExceptions
            ->sortBy([
                ['starts_on', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        foreach ($sorted as $exception) {
            $start = $exception->starts_on;
            $end = $exception->ends_on ?? $start;
            $isSameDay = $start !== null && $end !== null && $start->isSameDay($end);
            $endDay = $end !== null ? CarbonImmutable::parse($end->toDateString(), $displayTz)->startOfDay() : null;
            $startDay = $start !== null ? CarbonImmutable::parse($start->toDateString(), $displayTz)->startOfDay() : null;

            $isUpcomingOrCurrent = $endDay !== null && $endDay->gte($today);
            $isOngoing = $isUpcomingOrCurrent && $startDay !== null && $startDay->lte($today);

            $entry = [
                'label' => $exception->label ?: 'Calendar exception',
                'type_label' => filled($exception->exception_type) ? (string) $exception->exception_type : null,
                'starts_on_iso' => $start?->toDateString(),
                'starts_on_label' => $start
                    ? ($isSameDay || $start->year !== $end?->year ? $start->format('M j, Y') : $start->format('M j'))
                    : null,
                'ends_on_iso' => $end?->toDateString(),
                'ends_on_label' => $end?->format('M j, Y'),
                'is_same_day' => $isSameDay,
                'blocks_teaching' => (bool) $exception->blocks_teaching,
                'timing_label' => $isOngoing ? 'In progress' : ($isUpcomingOrCurrent ? 'Upcoming' : null),
                'timing_color' => $isOngoing ? 'success' : 'gray',
            ];

            if ($isUpcomingOrCurrent) {
                $upcoming[] = $entry;
            } else {
                $past[] = $entry;
            }
        }

        return [
            'upcoming' => $upcoming,
            'past' => $past,
        ];
    }

    /**
     * Recent published schedule revisions / cancellations affecting the student's officially enrolled sections.
     *
     * @return list<array{is_cancelled:bool,badge_label:string,badge_color:string,course_section:string,schedule_line:string,detail_line:string}>
     */
    public static function recentScheduleRevisionsForEnrollment(?Enrollment $enrollment): array
    {
        if (! $enrollment instanceof Enrollment || $enrollment->canonical_outcome !== Enrollment::OutcomeOfficiallyEnrolled) {
            return [];
        }

        $sectionIds = CourseEnrollment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->whereNotNull('section_id')
            ->pluck('section_id')
            ->unique()
            ->values()
            ->all();

        if ($sectionIds === []) {
            return [];
        }

        $events = ScheduleRevisionEvent::query()
            ->where('term_id', $enrollment->term_id)
            ->whereHas('sectionMeeting.schedulingDemand.sectionDeliveryGroup', fn ($query) => $query->whereIn('section_id', $sectionIds))
            ->with([
                'sectionMeeting.schedulingDemand.courseComponent.courseSpecification.course',
                'sectionMeeting.schedulingDemand.sectionDeliveryGroup.section',
                'sectionMeeting.room',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return $events
            ->map(function (ScheduleRevisionEvent $event): array {
                $meeting = $event->sectionMeeting;
                $specification = $meeting?->schedulingDemand?->courseComponent?->courseSpecification;
                $course = $specification?->course;
                $section = $meeting?->schedulingDemand?->sectionDeliveryGroup?->section;

                $courseSection = collect([
                    $course?->code,
                    $specification?->title,
                    $section?->code,
                ])->filter()->implode(' · ') ?: 'Enrolled class';

                $oldSnapshot = is_array($event->old_snapshot_json) ? $event->old_snapshot_json : [];
                $newSnapshot = is_array($event->new_snapshot_json) ? $event->new_snapshot_json : [];
                $activeSnapshot = $newSnapshot !== [] ? $newSnapshot : $oldSnapshot;

                $day = (int) ($meeting?->day_of_week ?? $activeSnapshot['day_of_week'] ?? 0);
                $startsAt = (string) ($meeting?->starts_at ?? $activeSnapshot['starts_at'] ?? '');
                $endsAt = (string) ($meeting?->ends_at ?? $activeSnapshot['ends_at'] ?? '');
                $dayLabel = SectionMeeting::dayOptions()[$day] ?? 'Day unavailable';
                $timeLabel = ($startsAt !== '' && $endsAt !== '')
                    ? self::formatClock($startsAt).'–'.self::formatClock($endsAt)
                    : 'Time unavailable';

                $isCancelled = $event->change_type === ScheduleRevisionEvent::ChangeSectionCancellation;
                $scheduleLine = $isCancelled
                    ? 'Cancelled · Was '.$dayLabel.' · '.$timeLabel
                    : $dayLabel.' · '.$timeLabel;

                if (! $isCancelled) {
                    $oldDay = isset($oldSnapshot['day_of_week']) ? (int) $oldSnapshot['day_of_week'] : null;
                    $oldStart = is_string($oldSnapshot['starts_at'] ?? null) ? $oldSnapshot['starts_at'] : null;
                    $oldEnd = is_string($oldSnapshot['ends_at'] ?? null) ? $oldSnapshot['ends_at'] : null;

                    if ($oldDay !== null && $oldStart !== null && $oldEnd !== null
                        && ($oldDay !== $day
                            || substr($oldStart, 0, 5) !== substr($startsAt, 0, 5)
                            || substr($oldEnd, 0, 5) !== substr($endsAt, 0, 5))) {
                        $oldDayLabel = SectionMeeting::dayOptions()[$oldDay] ?? 'Day unavailable';
                        $oldTimeLabel = self::formatClock($oldStart).'–'.self::formatClock($oldEnd);
                        $scheduleLine = 'Was '.$oldDayLabel.' · '.$oldTimeLabel.' → Now '.$dayLabel.' · '.$timeLabel;
                    } elseif ($event->change_type === ScheduleRevisionEvent::ChangeRoom && filled($meeting?->room?->code)) {
                        $scheduleLine .= ' · Room '.$meeting->room->code;
                    }
                }

                $badgeLabel = match ($event->change_type) {
                    ScheduleRevisionEvent::ChangeSectionCancellation => 'Class cancelled',
                    ScheduleRevisionEvent::ChangeTime => 'Time changed',
                    ScheduleRevisionEvent::ChangeRoom => 'Room changed',
                    ScheduleRevisionEvent::ChangeFacultyReassignment => 'Instructor changed',
                    ScheduleRevisionEvent::ChangeDeliveryModality => 'Delivery changed',
                    default => 'Schedule updated',
                };

                $detailLine = collect([
                    $event->effective_date ? 'Effective '.$event->effective_date->format('M j, Y') : null,
                    filled($event->reason) ? (string) $event->reason : null,
                ])->filter()->implode(' · ');

                return [
                    'is_cancelled' => $isCancelled,
                    'badge_label' => $badgeLabel,
                    'badge_color' => $isCancelled ? 'danger' : 'info',
                    'course_section' => $courseSection,
                    'schedule_line' => $scheduleLine,
                    'detail_line' => $detailLine !== '' ? $detailLine : 'Updated class schedule.',
                ];
            })
            ->values()
            ->all();
    }

    private static function formatClock(string $time): string
    {
        return CarbonImmutable::createFromFormat(
            '!H:i',
            substr($time, 0, 5),
            (string) config('app.display_timezone', 'Asia/Manila'),
        )->format('g:i A');
    }

    /**
     * @param  EloquentCollection<int, Enrollment>|null  $activeEnrollments
     */
    private function resolveSelectedEnrollment(User $user, ?EloquentCollection $activeEnrollments = null): ?Enrollment
    {
        $activeEnrollments ??= app(CurrentOfficialEnrollmentResolver::class)->allActiveForStudent($user);

        if ($activeEnrollments->isEmpty()) {
            return null;
        }

        if ($this->termId !== null) {
            $matched = $activeEnrollments->first(fn (Enrollment $enrollment): bool => (int) $enrollment->term_id === $this->termId);

            if ($matched instanceof Enrollment) {
                return $matched;
            }
        }

        return $activeEnrollments->first();
    }

    private function hasPrintableSchedule(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $selected = $this->resolveSelectedEnrollment($user);

        if (! $selected instanceof Enrollment) {
            return false;
        }

        $presentation = app(RegistrationSchedulePresentation::class)->currentForEnrollment($selected);

        if ($presentation !== null && ($presentation['meetings'] ?? []) !== []) {
            return true;
        }

        return $this->externallyArrangedClasses($selected) !== [];
    }

    /**
     * @return list<array{course_code:string,course_title:string,section_code:string,units:string,faculty:string,note:string}>
     */
    private function externallyArrangedClasses(Enrollment $enrollment): array
    {
        $externalRegistrations = CourseEnrollment::query()
            ->with([
                'termOffering.curriculumEntry.courseSpecification.course',
                'section',
            ])
            ->where('enrollment_id', $enrollment->id)
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->whereHas('termOffering.curriculumEntry.courseSpecification', fn ($query) => $query
                ->where('scheduling_treatment', CourseSpecification::SchedulingExternallyArranged))
            ->get();

        if ($externalRegistrations->isEmpty()) {
            return [];
        }

        $offeringIds = $externalRegistrations->pluck('term_offering_id')->filter()->unique()->values()->all();
        $assignments = $offeringIds === []
            ? collect()
            : ClassOfferingTeachingAssignment::query()
                ->with('faculty')
                ->whereIn('term_offering_id', $offeringIds)
                ->where('state', ClassOfferingTeachingAssignment::StateActive)
                ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [ClassOfferingTeachingAssignment::RoleDesignated])
                ->orderBy('id')
                ->get();

        return $externalRegistrations
            ->map(function (CourseEnrollment $registration) use ($assignments): array {
                $specification = $registration->termOffering?->curriculumEntry?->courseSpecification;
                $course = $specification?->course;
                $assignment = $assignments->first(function (ClassOfferingTeachingAssignment $candidate) use ($registration): bool {
                    if ((int) $candidate->term_offering_id !== (int) $registration->term_offering_id) {
                        return false;
                    }

                    return $registration->section_id === null
                        || $candidate->section_id === null
                        || (int) $candidate->section_id === (int) $registration->section_id;
                });

                return [
                    'course_code' => (string) ($course?->code ?? 'Subject'),
                    'course_title' => (string) ($specification?->title ?? $course?->code ?? 'Externally arranged subject'),
                    'section_code' => (string) ($registration->section?->code ?: 'Not sectioned'),
                    'units' => $specification?->credit_units !== null
                        ? rtrim(rtrim(number_format((float) $specification->credit_units, 2, '.', ''), '0'), '.').' units'
                        : '—',
                    'faculty' => (string) ($assignment?->faculty?->name ?: 'Adviser to be confirmed'),
                    'note' => 'Externally arranged · No fixed weekly room or time slot',
                ];
            })
            ->sortBy('course_code')
            ->values()
            ->all();
    }

    private function formatAcademicYearLabel(?string $label): ?string
    {
        $trimmed = trim((string) $label);
        if ($trimmed === '') {
            return null;
        }

        return str_starts_with(strtoupper($trimmed), 'AY ')
            ? $trimmed
            : 'AY '.$trimmed;
    }
}
