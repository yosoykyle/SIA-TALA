<?php

namespace App\Filament\Pages;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Calendar\CalendarPhaseGateService;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermDatedException;
use App\Models\User;
use App\Support\FacultyActiveTermResolver;
use App\Support\TeachingWeekSummary;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

class FacultyAcademicCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'Academic calendar';

    protected static ?string $title = 'Academic calendar';

    protected static ?string $slug = 'faculty-academic-calendar';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.faculty-academic-calendar';

    #[Url]
    public ?int $termId = null;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasRole(User::StaffRoleFaculty)) {
            return false;
        }

        $contexts = app(WorkspaceContextResolver::class);
        $selected = $contexts->selected($user);

        if ($selected === null) {
            $available = $contexts->availableContexts($user);
            $selected = count($available) === 1 ? array_key_first($available) : null;
        }

        return $selected === User::StaffRoleFaculty;
    }

    public function mount(): void
    {
        if ($this->termId !== null) {
            abort_unless(Term::query()->whereKey($this->termId)->exists(), 404);

            return;
        }

        $user = auth()->user();
        $this->termId = app(FacultyActiveTermResolver::class)->resolveId($user instanceof User ? $user : null);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $resolver = app(FacultyActiveTermResolver::class);
        $term = $this->termId !== null
            ? Term::query()->with('academicYear')->find($this->termId)
            : null;

        if (! $term instanceof Term) {
            return [
                'term' => null,
            ];
        }

        $package = TermCalendarPackage::query()
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->with(['windows', 'teachingGridRows', 'datedExceptions'])
            ->latest('version')
            ->first();

        if (! $package instanceof TermCalendarPackage && $term->state === Term::StateClosed) {
            $package = TermCalendarPackage::query()
                ->where('term_id', $term->id)
                ->where('state', TermCalendarPackage::StateClosed)
                ->where(function ($query): void {
                    $query->whereNotNull('activated_at')->orWhereNotNull('closed_at');
                })
                ->with(['windows', 'teachingGridRows', 'datedExceptions'])
                ->latest('version')
                ->first();
        }

        $hasActiveCoreCalendar = $package instanceof TermCalendarPackage
            && $package->state === TermCalendarPackage::StateActive;

        $extraScope = $term->state === Term::StateClosed ? 'Closed term (read-only)' : null;

        return [
            'term' => $term,
            'package' => $package,
            'hasActiveCoreCalendar' => $hasActiveCoreCalendar,
            'scopeTitle' => $resolver->scopeTitle($term),
            'scopeMeta' => $resolver->scopeMeta($term, $hasActiveCoreCalendar, $extraScope),
            'teachingPeriod' => $this->teachingPeriodSummary($term, $package),
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
        $now = CarbonImmutable::now('Asia/Manila');

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
                    'Upcoming' => 'info',
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
                    ? $cutoffVerb.' '.$bounds['closes_at']->format('g:i A').' (Asia/Manila)'
                    : null;

                $note = match ($type) {
                    TermCalendarWindow::TypeExaminationPeriod => 'Calendar notice · Does not pause classes or lock grades.',
                    TermCalendarWindow::TypeGradeEntry => 'Official grades are released by the Registrar after review.',
                    default => null,
                };

                return [
                    'type' => $type,
                    'label' => $label,
                    'icon' => $icon,
                    'status' => $status,
                    'status_color' => $statusColor,
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

        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
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
            $endDay = $end !== null ? CarbonImmutable::parse($end->toDateString(), 'Asia/Manila')->startOfDay() : null;
            $startDay = $start !== null ? CarbonImmutable::parse($start->toDateString(), 'Asia/Manila')->startOfDay() : null;

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
                'timing_color' => $isOngoing ? 'success' : 'info',
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
}
