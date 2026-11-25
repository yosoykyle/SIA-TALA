<?php

namespace App\Filament\Student\Pages;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\StudentHub\RecordStudentScheduleAccess;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\Enrollment;
use App\Models\ScheduleRevisionEvent;
use App\Models\SectionMeeting;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermDatedException;
use App\Models\User;
use App\Support\RegistrationSchedulePresentation;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Url;

class ScheduleView extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Class schedule';

    protected static ?string $title = 'Class schedule';

    protected ?string $subheading = 'Your registered classes and published meeting times.';

    protected string $view = 'filament.student.pages.schedule-view';

    #[Url]
    public ?int $termId = null;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(panel: 'student') => 'Overview', $this->getTitle()];
    }

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $selected = $this->resolveSelectedEnrollment($user);
        $this->termId = $selected instanceof Enrollment ? (int) $selected->term_id : null;

        app(RecordStudentScheduleAccess::class)->execute($user, request(), RecordStudentScheduleAccess::ActionView, $selected);
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
        $activeEnrollments = $actor instanceof User
            ? app(CurrentOfficialEnrollmentResolver::class)->allActiveForStudent($actor)
            : new EloquentCollection;
        $selectedEnrollment = $actor instanceof User ? $this->resolveSelectedEnrollment($actor, $activeEnrollments) : null;
        $schedulePresentation = $selectedEnrollment instanceof Enrollment
            ? app(RegistrationSchedulePresentation::class)->currentForEnrollment($selectedEnrollment)
            : null;
        $externallyArrangedClasses = $selectedEnrollment instanceof Enrollment
            ? $this->externallyArrangedClasses($selectedEnrollment)
            : [];
        $recentScheduleRevisions = self::recentScheduleRevisionsForEnrollment($selectedEnrollment);

        $package = $selectedEnrollment instanceof Enrollment
            ? TermCalendarPackage::query()
                ->where('term_id', $selectedEnrollment->term_id)
                ->where('state', TermCalendarPackage::StateActive)
                ->with(['windows', 'datedExceptions'])
                ->first()
            : null;
        $examinationPeriod = $package?->windows->first(
            fn (TermCalendarWindow $window): bool => $window->window_type === TermCalendarWindow::TypeExaminationPeriod,
        );
        $termTeachingRange = match (true) {
            $package?->classes_start_on !== null && $package?->classes_end_on !== null => $package->classes_start_on->format('M j').'–'.$package->classes_end_on->format('M j, Y'),
            $selectedEnrollment?->term?->starts_on !== null && $selectedEnrollment?->term?->ends_on !== null => $selectedEnrollment->term->starts_on->format('M j').'–'.$selectedEnrollment->term->ends_on->format('M j, Y'),
            default => null,
        };
        $today = CarbonImmutable::now((string) config('app.display_timezone', 'Asia/Manila'))->startOfDay();
        $upcomingHolidays = $package instanceof TermCalendarPackage
            ? $package->datedExceptions
                ->filter(fn (TermDatedException $exception): bool => $exception->blocks_teaching
                    && $exception->ends_on !== null
                    && CarbonImmutable::parse($exception->ends_on->toDateString(), (string) config('app.display_timezone', 'Asia/Manila'))->gte($today))
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
                    $enrollment->term?->academicYear?->label ? 'AY '.$enrollment->term->academicYear->label : null,
                ])->filter()->implode(' · ') ?: 'Term #'.$enrollment->term_id,
                'is_selected' => $selectedEnrollment instanceof Enrollment && (int) $selectedEnrollment->term_id === (int) $enrollment->term_id,
            ])
            ->values()
            ->all();

        return [
            'activeTermOptions' => $termSwitcherOptions,
            'selectedTermLabel' => $selectedEnrollment?->term?->label,
            'termTeachingRange' => $termTeachingRange,
            'examinationPeriod' => $examinationPeriod,
            'upcomingHolidays' => $upcomingHolidays,
            'recentScheduleRevisions' => $recentScheduleRevisions,
            'schedulePresentation' => $schedulePresentation,
            'externallyArrangedClasses' => $externallyArrangedClasses,
            'hasOfficialEnrollment' => $selectedEnrollment instanceof Enrollment,
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
}
