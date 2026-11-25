<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\FacultyAvailabilityStatusProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Models\CalendarEvent;
use App\Models\FacultyAvailabilityDeadline;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyTermLoadOverride;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use App\Support\HasSearchableTermSelection;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

class MyAvailability extends Page
{
    use HasSearchableTermSelection;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'My availability';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'My availability';

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected string $view = 'filament.pages.my-availability';

    /** @var array<string, string> */
    protected array $extraBodyAttributes = ['class' => 'tala-faculty-availability-page'];

    #[Url]
    public ?int $termId = null;

    public ?int $editorTermId = null;

    public ?string $availabilityMode = null;

    /** @var array<int, bool> */
    public array $enabledDays = [];

    /** @var array<int, list<array{start_time:string,end_time:string}>> */
    public array $dayWindows = [];

    public string $correctionReason = '';

    private const ShortDayNames = [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];

    public function mount(): void
    {
        if ($this->termId !== null) {
            abort_unless(Term::query()->whereKey($this->termId)->exists(), 404);
        }

        if ($this->termId === null) {
            $activeTermIds = TermCalendarPackage::query()
                ->where('state', TermCalendarPackage::StateActive)
                ->whereHas('term', fn ($query) => $query->where('state', '!=', Term::StateClosed))
                ->distinct()
                ->pluck('term_id');

            if ($activeTermIds->count() === 1) {
                $this->termId = (int) $activeTermIds->sole();
            }
        }

        $this->syncEditorState();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleFaculty) ?? false;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function selectTerm(int $termId): void
    {
        abort_unless(Term::query()->whereKey($termId)->exists(), 404);
        $this->termId = $termId;
        $this->syncEditorState();
    }

    public function cancelEdits(): void
    {
        $this->syncEditorState();
    }

    public function toggleDay(int $day): void
    {
        if ($this->isSelectedTermClosed() || $this->isRegistrarMarkedNotTeaching()) {
            return;
        }

        if (! empty($this->enabledDays[$day])) {
            unset($this->enabledDays[$day], $this->dayWindows[$day]);
            $this->resetErrorBag('teachingWindows');

            return;
        }

        $row = $this->gridRowForDay($day);
        if (! $row instanceof TermTeachingGridRow) {
            return;
        }

        $this->enabledDays[$day] = true;
        $this->dayWindows[$day] = [[
            'start_time' => substr((string) $row->starts_at, 0, 5),
            'end_time' => substr((string) $row->ends_at, 0, 5),
        ]];
        ksort($this->enabledDays);
        ksort($this->dayWindows);
        $this->resetErrorBag('teachingWindows');
    }

    public function addDayRange(int $day): void
    {
        if ($this->isSelectedTermClosed() || $this->isRegistrarMarkedNotTeaching()) {
            return;
        }

        $row = $this->gridRowForDay($day);
        if (! $row instanceof TermTeachingGridRow) {
            return;
        }

        $gridStart = FacultyAvailabilityDeclaration::clockToMinutes((string) $row->starts_at);
        $gridEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) $row->ends_at);
        if ($gridStart === null || $gridEnd === null || $gridStart >= $gridEnd) {
            return;
        }

        $existing = $this->dayWindows[$day] ?? [];
        $defaultStart = $gridStart;
        $defaultEnd = $gridEnd;

        if ($existing !== []) {
            $last = end($existing);
            $lastEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) ($last['end_time'] ?? ''));

            if ($lastEnd !== null && $lastEnd < $gridEnd) {
                $candidateStart = $lastEnd;
                foreach (is_array($row->breaks) ? $row->breaks : [] as $break) {
                    if (! is_array($break)) {
                        continue;
                    }
                    $bStart = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['starts_at'] ?? ''));
                    $bEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['ends_at'] ?? ''));
                    if ($bStart !== null && $bEnd !== null && $candidateStart >= $bStart && $candidateStart < $bEnd) {
                        $candidateStart = $bEnd;
                    }
                }

                if ($candidateStart < $gridEnd) {
                    $defaultStart = $candidateStart;
                    $defaultEnd = $gridEnd;
                }
            }
        }

        $this->enabledDays[$day] = true;
        $this->dayWindows[$day][] = [
            'start_time' => FacultyAvailabilityDeclaration::minutesToHourMinute($defaultStart),
            'end_time' => FacultyAvailabilityDeclaration::minutesToHourMinute($defaultEnd),
        ];
        ksort($this->enabledDays);
        ksort($this->dayWindows);
        $this->resetErrorBag('teachingWindows');
    }

    public function removeDayRange(int $day, int $index): void
    {
        if ($this->isSelectedTermClosed() || $this->isRegistrarMarkedNotTeaching()) {
            return;
        }

        if (! isset($this->dayWindows[$day][$index])) {
            return;
        }

        unset($this->dayWindows[$day][$index]);
        $this->dayWindows[$day] = array_values($this->dayWindows[$day]);

        if ($this->dayWindows[$day] === []) {
            unset($this->enabledDays[$day], $this->dayWindows[$day]);
        }

        $this->resetErrorBag('teachingWindows');
    }

    public function updatedDayWindows(mixed $value, string $path): void
    {
        $parts = explode('.', $path);
        if (count($parts) === 3 && $parts[2] === 'start_time') {
            $this->normalizeRangeEnd((int) $parts[0], (int) $parts[1]);
        }
    }

    public function copyDayToWeekdays(int $sourceDay): void
    {
        if ($this->isSelectedTermClosed() || $this->isRegistrarMarkedNotTeaching()) {
            return;
        }

        $sourceRanges = $this->dayWindows[$sourceDay] ?? [];
        if ($sourceRanges === []) {
            return;
        }

        $package = $this->activePackage();
        if (! $package instanceof TermCalendarPackage) {
            return;
        }

        foreach ($package->teachingGridRows->sortBy('day_of_week') as $row) {
            $day = (int) $row->day_of_week;
            if ($day < 1 || $day > 5 || $day === $sourceDay) {
                continue;
            }

            $gridStart = FacultyAvailabilityDeclaration::clockToMinutes((string) $row->starts_at);
            $gridEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) $row->ends_at);
            if ($gridStart === null || $gridEnd === null || $gridStart >= $gridEnd) {
                continue;
            }

            $copied = [];
            foreach ($sourceRanges as $range) {
                $rStart = FacultyAvailabilityDeclaration::clockToMinutes((string) ($range['start_time'] ?? ''));
                $rEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) ($range['end_time'] ?? ''));
                if ($rStart === null || $rEnd === null) {
                    continue;
                }

                $cStart = max($gridStart, $rStart);
                $cEnd = min($gridEnd, $rEnd);
                if ($cStart < $cEnd) {
                    $copied[] = [
                        'start_time' => FacultyAvailabilityDeclaration::minutesToHourMinute($cStart),
                        'end_time' => FacultyAvailabilityDeclaration::minutesToHourMinute($cEnd),
                    ];
                }
            }

            if ($copied !== []) {
                $this->enabledDays[$day] = true;
                $this->dayWindows[$day] = $copied;
            }
        }

        ksort($this->enabledDays);
        ksort($this->dayWindows);
        $this->resetErrorBag('teachingWindows');
    }

    public function saveAvailability(): void
    {
        $faculty = auth()->user();
        abort_unless($faculty instanceof User, 403);

        if ($this->termId === null || $this->editorTermId !== $this->termId) {
            Notification::make()
                ->title('Term selection changed')
                ->body('Review availability for the selected Term before saving.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $selectedTerm = $this->selectedTerm();
        abort_unless($selectedTerm->state !== Term::StateClosed, 409);
        abort_unless(! $this->isRegistrarMarkedNotTeaching(), 409);
        abort_unless($this->hasActiveCoreCalendar(), 409);
        $this->resetValidation();

        if (! in_array($this->availabilityMode, [
            FacultyAvailabilityDeclaration::ModeWindows,
            FacultyAvailabilityDeclaration::ModeAnyApproved,
            FacultyAvailabilityDeclaration::ModeCannotTeach,
        ], true)) {
            $this->addError('availabilityMode', 'Choose how you can teach this Term.');

            return;
        }

        $windows = $this->availabilityMode === FacultyAvailabilityDeclaration::ModeWindows
            ? $this->compiledDraftWindows()
            : [];

        try {
            app(RecordFacultyAvailabilityDeclaration::class)->execute(
                term: $this->selectedTerm(),
                faculty: $faculty,
                actor: $faculty,
                correctionReason: filled(trim($this->correctionReason)) ? trim($this->correctionReason) : null,
                availabilityMode: $this->availabilityMode,
                teachingWindows: $windows,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $mappedKey = match ((string) $key) {
                    'availability_mode' => 'availabilityMode',
                    'correction_reason' => 'correctionReason',
                    'teaching_windows' => 'teachingWindows',
                    default => (string) $key,
                };
                foreach ($messages as $message) {
                    $this->addError($mappedKey, $message);
                }
            }

            return;
        }

        $this->syncEditorState();

        $postSaveStatus = app(FacultyAvailabilityStatusProjection::class)->forFaculty($selectedTerm, $faculty);
        $lateImpactMeetings = $postSaveStatus['late_impact_meetings'] ?? [];

        if ($lateImpactMeetings !== []) {
            $conflictCount = collect($lateImpactMeetings)
                ->map(fn (array $meeting): string => $meeting['section_id'] !== null
                    ? 's:'.$meeting['section_id']
                    : 'm:'.($meeting['section_meeting_id'] ?? ''))
                ->unique()
                ->count();

            Notification::make()
                ->title(sprintf(
                    'Availability saved: conflicts with %d published %s',
                    $conflictCount,
                    Str::plural('class', $conflictCount),
                ))
                ->body('Your current classes stay as scheduled until the Registrar publishes an updated timetable.')
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title('Availability response saved')
            ->body('The Registrar can use your response to plan teaching assignments for this term.')
            ->success()
            ->send();
    }

    public function availabilitySummary(FacultyAvailabilityDeclaration $declaration): string
    {
        return $this->declarationSummary($declaration);
    }

    public function stateHeading(?string $effectiveMode): string
    {
        return match ($effectiveMode) {
            FacultyAvailabilityDeclaration::ModeWindows => 'Available on selected times',
            FacultyAvailabilityDeclaration::ModeAnyApproved => 'Available during all teaching hours',
            FacultyAvailabilityDeclaration::ModeCannotTeach => 'Unavailable this term',
            FacultyAvailabilityDeclaration::ModeLegacyUnavailability => 'Available except recorded unavailable times',
            default => 'Not submitted yet',
        };
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $terms = Term::query()->with('academicYear')->latest('starts_on')->get();
        $term = $terms->firstWhere('id', $this->termId);
        $faculty = auth()->user();

        $status = ($term instanceof Term && $faculty instanceof User)
            ? app(FacultyAvailabilityStatusProjection::class)->forFaculty($term, $faculty)
            : null;

        /** @var TermCalendarPackage|null $package */
        $package = $status['active_calendar_package'] ?? null;
        /** @var FacultyAvailabilityDeclaration|null $currentDeclaration */
        $currentDeclaration = $status['latest_declaration'] ?? null;
        $deadlineSource = $term instanceof Term
            ? FacultyAvailabilityDeadline::query()->where('term_id', $term->id)->latest('id')->first()
            : null;

        $outsideGridKeys = collect($status['outside_grid_windows'] ?? [])
            ->mapWithKeys(fn (array $window): array => [
                sprintf('%d|%s|%s', $window['day_of_week'], $window['start_time'], $window['end_time']) => (string) $window['reason'],
            ]);

        $teachingWindows = collect($status['teaching_windows'] ?? [])
            ->sortBy([['day_of_week', 'asc'], ['start_time', 'asc']])
            ->map(function (array $window) use ($outsideGridKeys): array {
                $key = sprintf('%d|%s|%s', $window['day_of_week'], $window['start_time'], $window['end_time']);

                return [
                    'day' => SectionMeeting::dayOptions()[(int) $window['day_of_week']] ?? 'Unknown day',
                    'time' => $this->displayIntervalTime((string) $window['start_time']).'–'.$this->displayIntervalTime((string) $window['end_time']),
                    'outside_reason' => $outsideGridKeys->get($key),
                ];
            })
            ->values();

        $legacyUnavailableTimes = collect($status['hard_unavailability'] ?? [])
            ->sortBy([['day_of_week', 'asc'], ['starts_at', 'asc']])
            ->map(fn (array $interval): array => [
                'day' => SectionMeeting::dayOptions()[(int) $interval['day_of_week']] ?? 'Unknown day',
                'time' => $this->displayIntervalTime((string) $interval['starts_at']).'–'.$this->displayIntervalTime((string) $interval['ends_at']),
            ])
            ->values();

        $hasActiveCoreCalendar = (bool) ($status['has_active_core_calendar'] ?? false);
        $isTermClosed = $term instanceof Term && $term->state === Term::StateClosed;
        $registrarNotTeaching = (bool) ($status['registrar_not_teaching'] ?? false);
        $registrarNotTeachingReason = $status['registrar_not_teaching_reason'] ?? null;
        $canEditAvailability = $hasActiveCoreCalendar && ! $isTermClosed && ! $registrarNotTeaching;
        $declarations = $term instanceof Term
            ? $this->declarationsQuery()->orderByDesc('version')->get()
            : collect();

        $liveHoursSummary = $canEditAvailability && $this->availabilityMode === FacultyAvailabilityDeclaration::ModeWindows
            ? $this->weeklyHoursSummaryFromWindows($this->compiledDraftWindows(), $package)
            : ($currentDeclaration instanceof FacultyAvailabilityDeclaration && $currentDeclaration->effectiveMode() === FacultyAvailabilityDeclaration::ModeWindows
                ? $this->weeklyHoursSummaryFromWindows($currentDeclaration->normalizedTeachingWindows(), $package)
                : null);

        $approvedWeekdayCount = $package instanceof TermCalendarPackage
            ? $package->teachingGridRows->filter(fn (TermTeachingGridRow $row): bool => $row->day_of_week >= 1 && $row->day_of_week <= 5)->count()
            : 0;

        $scopePill = $deadlineSource?->due_at !== null && ! $isTermClosed && ! $registrarNotTeaching
            ? 'Reply by '.$deadlineSource->due_at->timezone((string) config('app.display_timezone', 'Asia/Manila'))->format('M j, Y · g:i A')
            : null;

        $scopeMeta = $term instanceof Term
            ? implode(' · ', array_filter([
                $term->starts_on->format('M j').' – '.$term->ends_on->format('M j, Y'),
                $hasActiveCoreCalendar ? 'Term calendar active' : 'Term calendar not active',
                $scopePill,
            ]))
            : null;

        $historicalBlocks = ($term instanceof Term && $faculty instanceof User)
            ? CalendarEvent::query()
                ->recurringSchedulingBlocks()
                ->where('scope_type', CalendarEvent::ScopeFaculty)
                ->where('faculty_user_id', $faculty->id)
                ->where('term_id', $term->id)
                ->orderBy('day_of_week')
                ->orderBy('starts_at')
                ->get()
                ->map(fn (CalendarEvent $event): array => [
                    'id' => (int) $event->id,
                    'day' => SectionMeeting::dayOptions()[(int) $event->day_of_week] ?? 'Unknown day',
                    'time' => ($event->starts_at && $event->ends_at)
                        ? $this->displayIntervalTime($event->starts_at instanceof \DateTimeInterface ? $event->starts_at->format('H:i') : (string) $event->starts_at)
                            .'–'
                            .$this->displayIntervalTime($event->ends_at instanceof \DateTimeInterface ? $event->ends_at->format('H:i') : (string) $event->ends_at)
                        : 'Time unavailable',
                    'authority' => $event->authority,
                    'recorded_at' => $event->created_at,
                ])
                ->values()
                ->all()
            : [];

        return [
            'terms' => $terms,
            'term' => $term,
            'scopeMeta' => $scopeMeta,
            'status' => $status,
            'hasActiveCoreCalendar' => $hasActiveCoreCalendar,
            'isTermClosed' => $isTermClosed,
            'canEditAvailability' => $canEditAvailability,
            'registrarNotTeaching' => $registrarNotTeaching,
            'registrarNotTeachingReason' => $registrarNotTeachingReason,
            'hasDeclaration' => (bool) ($status['has_declaration'] ?? false),
            'currentDeclaration' => $currentDeclaration,
            'effectiveMode' => $status['effective_mode'] ?? null,
            'statusHeading' => $registrarNotTeaching
                ? 'Not teaching (Registrar)'
                : $this->stateHeading($status['effective_mode'] ?? null),
            'teachingWindows' => $teachingWindows,
            'weeklyHoursSummary' => $liveHoursSummary,
            'legacyUnavailableTimes' => $legacyUnavailableTimes,
            'needsGridReview' => (bool) ($status['needs_grid_review'] ?? false),
            'hasPublishedImpact' => (bool) ($status['has_official_impact'] ?? false),
            'lateImpactMeetings' => $status['late_impact_meetings'] ?? [],
            'groupedApprovedHours' => $this->groupedApprovedHours($package),
            'editorDayRows' => $this->editorDayRows($package, $outsideGridKeys),
            'canCopyToWeekdays' => $approvedWeekdayCount > 1,
            'declarations' => $declarations,
            'scopePill' => $scopePill,
            'historicalBlocks' => $historicalBlocks,
        ];
    }

    private function syncEditorState(): void
    {
        $this->editorTermId = $this->termId;
        $this->correctionReason = '';
        $this->resetValidation();
        $this->enabledDays = [];
        $this->dayWindows = [];

        if ($this->termId === null) {
            $this->availabilityMode = null;

            return;
        }

        $latest = $this->declarationsQuery()->latest('version')->first();
        $effectiveMode = $latest?->effectiveMode();

        $this->availabilityMode = in_array($effectiveMode, [
            FacultyAvailabilityDeclaration::ModeWindows,
            FacultyAvailabilityDeclaration::ModeAnyApproved,
            FacultyAvailabilityDeclaration::ModeCannotTeach,
        ], true) ? $effectiveMode : null;

        if ($latest instanceof FacultyAvailabilityDeclaration && $effectiveMode === FacultyAvailabilityDeclaration::ModeWindows) {
            foreach ($latest->normalizedTeachingWindows() as $window) {
                $day = (int) $window['day_of_week'];
                $this->enabledDays[$day] = true;
                $this->dayWindows[$day][] = [
                    'start_time' => $window['start_time'],
                    'end_time' => $window['end_time'],
                ];
            }
            ksort($this->enabledDays);
            ksort($this->dayWindows);
        }
    }

    /** @return list<array{day_of_week:int,start_time:string,end_time:string}> */
    private function compiledDraftWindows(): array
    {
        $windows = [];
        foreach ($this->enabledDays as $day => $enabled) {
            if (! $enabled) {
                continue;
            }
            foreach ($this->dayWindows[(int) $day] ?? [] as $range) {
                $windows[] = [
                    'day_of_week' => (int) $day,
                    'start_time' => (string) ($range['start_time'] ?? ''),
                    'end_time' => (string) ($range['end_time'] ?? ''),
                ];
            }
        }

        return $windows;
    }

    private function normalizeRangeEnd(int $day, int $index): void
    {
        if (! isset($this->dayWindows[$day][$index])) {
            return;
        }

        $startMinutes = FacultyAvailabilityDeclaration::clockToMinutes((string) ($this->dayWindows[$day][$index]['start_time'] ?? ''));
        $endMinutes = FacultyAvailabilityDeclaration::clockToMinutes((string) ($this->dayWindows[$day][$index]['end_time'] ?? ''));
        $row = $this->gridRowForDay($day);
        $gridEnd = $row instanceof TermTeachingGridRow
            ? FacultyAvailabilityDeclaration::clockToMinutes((string) $row->ends_at)
            : null;

        if ($startMinutes !== null && ($endMinutes === null || $endMinutes <= $startMinutes)) {
            $nextEnd = $gridEnd !== null ? min($gridEnd, $startMinutes + 60) : ($startMinutes + 60);
            if ($gridEnd !== null && $nextEnd <= $startMinutes) {
                $nextEnd = $gridEnd;
            }
            $this->dayWindows[$day][$index]['end_time'] = FacultyAvailabilityDeclaration::minutesToHourMinute($nextEnd);
        }
    }

    /** @return Builder<FacultyAvailabilityDeclaration> */
    private function declarationsQuery(): Builder
    {
        $query = FacultyAvailabilityDeclaration::query()
            ->where('faculty_user_id', auth()->id());

        return $this->termId === null
            ? $query->whereRaw('1 = 0')
            : $query->where('term_id', $this->termId);
    }

    private function selectedTerm(): Term
    {
        abort_if($this->termId === null, 404);

        return Term::query()->findOrFail($this->termId);
    }

    private function hasActiveCoreCalendar(): bool
    {
        return $this->activePackage() instanceof TermCalendarPackage;
    }

    private function activePackage(): ?TermCalendarPackage
    {
        if ($this->termId === null) {
            return null;
        }

        return TermCalendarPackage::query()
            ->where('term_id', $this->termId)
            ->where('state', TermCalendarPackage::StateActive)
            ->with('teachingGridRows')
            ->first();
    }

    private function gridRowForDay(int $day): ?TermTeachingGridRow
    {
        $package = $this->activePackage();
        if (! $package instanceof TermCalendarPackage) {
            return null;
        }

        $row = $package->teachingGridRows->firstWhere('day_of_week', $day);

        return $row instanceof TermTeachingGridRow ? $row : null;
    }

    /** @return list<string> */
    private function groupedApprovedHours(?TermCalendarPackage $package): array
    {
        if (! $package instanceof TermCalendarPackage) {
            return [];
        }

        $package->loadMissing('teachingGridRows');
        $rows = $package->teachingGridRows->sortBy('day_of_week')->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $groups = [];
        foreach ($rows as $row) {
            $hoursLabel = $this->displayIntervalTime((string) $row->starts_at).'–'.$this->displayIntervalTime((string) $row->ends_at);
            $breaks = collect(is_array($row->breaks) ? $row->breaks : [])
                ->filter(fn (mixed $break): bool => is_array($break) && filled($break['starts_at'] ?? null) && filled($break['ends_at'] ?? null))
                ->map(fn (array $break): string => $this->displayIntervalTime((string) $break['starts_at']).'–'.$this->displayIntervalTime((string) $break['ends_at']))
                ->values()
                ->all();
            $breaksLabel = $breaks !== [] ? 'Break '.implode(', ', $breaks) : null;
            $signature = $hoursLabel.'|'.($breaksLabel ?? '');

            if (! isset($groups[$signature])) {
                $groups[$signature] = [
                    'days' => [],
                    'hours' => $hoursLabel,
                    'breaks' => $breaksLabel,
                ];
            }

            $groups[$signature]['days'][] = (int) $row->day_of_week;
        }

        return collect($groups)
            ->map(function (array $group): string {
                $dayRange = $this->formatDayRun($group['days']);
                $suffix = $group['breaks'] !== null ? ' ('.$group['breaks'].')' : '';

                return $dayRange.' '.$group['hours'].$suffix;
            })
            ->values()
            ->all();
    }

    /** @param list<int> $days */
    private function formatDayRun(array $days): string
    {
        sort($days);
        $runs = [];
        $start = $days[0];
        $prev = $days[0];

        for ($i = 1, $count = count($days); $i < $count; $i++) {
            if ($days[$i] === $prev + 1) {
                $prev = $days[$i];

                continue;
            }
            $runs[] = [$start, $prev];
            $start = $days[$i];
            $prev = $days[$i];
        }
        $runs[] = [$start, $prev];

        return collect($runs)
            ->map(function (array $run): string {
                [$from, $to] = $run;
                $fromLabel = self::ShortDayNames[$from] ?? (string) $from;
                $toLabel = self::ShortDayNames[$to] ?? (string) $to;

                if ($from === $to) {
                    return $fromLabel;
                }

                if ($to === $from + 1) {
                    return $fromLabel.', '.$toLabel;
                }

                return $fromLabel.'–'.$toLabel;
            })
            ->implode(', ');
    }

    /**
     * @param  Collection<string, string>  $outsideGridKeys
     * @return list<array<string, mixed>>
     */
    private function editorDayRows(?TermCalendarPackage $package, Collection $outsideGridKeys): array
    {
        $daysMap = SectionMeeting::dayOptions();
        $gridByDay = $package instanceof TermCalendarPackage
            ? $package->teachingGridRows->sortBy('day_of_week')->keyBy('day_of_week')
            : collect();

        $allDays = $gridByDay->keys()
            ->merge(array_keys($this->dayWindows))
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => isset($daysMap[$day]))
            ->unique()
            ->sort()
            ->values();

        return $allDays->map(function (int $day) use ($daysMap, $gridByDay, $outsideGridKeys): array {
            /** @var TermTeachingGridRow|null $row */
            $row = $gridByDay->get($day);
            $isApprovedDay = $row instanceof TermTeachingGridRow;
            $isEnabled = ! empty($this->enabledDays[$day]);
            $ranges = $isEnabled ? ($this->dayWindows[$day] ?? []) : [];

            $breaks = $isApprovedDay
                ? collect(is_array($row->breaks) ? $row->breaks : [])
                    ->filter(fn (mixed $break): bool => is_array($break) && filled($break['starts_at'] ?? null) && filled($break['ends_at'] ?? null))
                    ->map(fn (array $break): string => $this->displayIntervalTime((string) $break['starts_at']).'–'.$this->displayIntervalTime((string) $break['ends_at']))
                    ->values()
                    ->all()
                : [];

            $gridStart = $isApprovedDay ? FacultyAvailabilityDeclaration::clockToMinutes((string) $row->starts_at) : null;
            $gridEnd = $isApprovedDay ? FacultyAvailabilityDeclaration::clockToMinutes((string) $row->ends_at) : null;

            $warningReasons = [];
            $preparedRanges = [];

            foreach ($ranges as $index => $range) {
                $startTime = substr((string) ($range['start_time'] ?? ''), 0, 5);
                $endTime = substr((string) ($range['end_time'] ?? ''), 0, 5);
                $key = sprintf('%d|%s|%s', $day, $startTime, $endTime);
                $outsideReason = $outsideGridKeys->get($key);

                if ($outsideReason === null) {
                    $wStart = FacultyAvailabilityDeclaration::clockToMinutes($startTime);
                    $wEnd = FacultyAvailabilityDeclaration::clockToMinutes($endTime);
                    if (! $isApprovedDay) {
                        $outsideReason = 'Day is not part of the approved teaching grid.';
                    } elseif ($gridStart !== null && $gridEnd !== null && ($wStart === null || $wEnd === null || $wStart < $gridStart || $wEnd > $gridEnd)) {
                        $outsideReason = 'Time range falls outside approved daily teaching hours.';
                    }
                }

                if ($outsideReason !== null) {
                    $warningReasons[] = $outsideReason;
                }

                $preparedRanges[] = [
                    'index' => $index,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'start_options' => $this->startSlotOptions($row, $startTime),
                    'end_options' => $this->endSlotOptions($row, $startTime, $endTime),
                    'outside_reason' => $outsideReason,
                ];
            }

            return [
                'day_of_week' => $day,
                'day_label' => $daysMap[$day],
                'is_approved_day' => $isApprovedDay,
                'is_weekday' => $day >= 1 && $day <= 5,
                'hours_label' => $isApprovedDay
                    ? $this->displayIntervalTime((string) $row->starts_at).'–'.$this->displayIntervalTime((string) $row->ends_at)
                    : 'Not in approved schedule',
                'breaks_label' => $breaks !== [] ? 'Break '.implode(', ', $breaks) : null,
                'is_enabled' => $isEnabled,
                'ranges' => $preparedRanges,
                'has_warning' => $warningReasons !== [],
                'warning_reason' => $warningReasons[0] ?? null,
            ];
        })->all();
    }

    /** @return array<string, string> */
    private function startSlotOptions(?TermTeachingGridRow $row, ?string $currentValue = null): array
    {
        $options = [];
        $gridStart = $row instanceof TermTeachingGridRow
            ? FacultyAvailabilityDeclaration::clockToMinutes((string) $row->starts_at)
            : null;
        $gridEnd = $row instanceof TermTeachingGridRow
            ? FacultyAvailabilityDeclaration::clockToMinutes((string) $row->ends_at)
            : null;

        if ($gridStart !== null && $gridEnd !== null && $gridStart < $gridEnd) {
            for ($minutes = $gridStart; $minutes <= $gridEnd - 30; $minutes += 30) {
                $key = FacultyAvailabilityDeclaration::minutesToHourMinute($minutes);
                $options[$key] = $this->displayIntervalTime($key);
            }
        }

        $normalized = is_string($currentValue) && trim($currentValue) !== '' ? substr(trim($currentValue), 0, 5) : null;
        if ($normalized !== null
            && FacultyAvailabilityDeclaration::clockToMinutes($normalized) !== null
            && ! array_key_exists($normalized, $options)) {
            $options[$normalized] = $this->displayIntervalTime($normalized).' (outside hours)';
            ksort($options);
        }

        return $options;
    }

    /** @return array<string, string> */
    private function endSlotOptions(?TermTeachingGridRow $row, ?string $startTime, ?string $currentValue = null): array
    {
        $options = [];
        $gridStart = $row instanceof TermTeachingGridRow
            ? FacultyAvailabilityDeclaration::clockToMinutes((string) $row->starts_at)
            : null;
        $gridEnd = $row instanceof TermTeachingGridRow
            ? FacultyAvailabilityDeclaration::clockToMinutes((string) $row->ends_at)
            : null;
        $startMinutes = FacultyAvailabilityDeclaration::clockToMinutes($startTime);

        if ($gridStart !== null && $gridEnd !== null && $gridStart < $gridEnd) {
            $minEnd = max($gridStart + 30, ($startMinutes ?? $gridStart) + 30);
            for ($minutes = $minEnd; $minutes <= $gridEnd; $minutes += 30) {
                $key = FacultyAvailabilityDeclaration::minutesToHourMinute($minutes);
                $options[$key] = $this->displayIntervalTime($key);
            }
        }

        $normalized = is_string($currentValue) && trim($currentValue) !== '' ? substr(trim($currentValue), 0, 5) : null;
        if ($normalized !== null
            && FacultyAvailabilityDeclaration::clockToMinutes($normalized) !== null
            && ! array_key_exists($normalized, $options)) {
            $options[$normalized] = $this->displayIntervalTime($normalized).' (outside hours)';
            ksort($options);
        }

        return $options;
    }

    /** @param list<array{day_of_week:int,start_time:string,end_time:string}> $windows */
    private function weeklyHoursSummaryFromWindows(array $windows, ?TermCalendarPackage $package): ?string
    {
        if ($windows === [] || ! $package instanceof TermCalendarPackage) {
            return null;
        }

        $package->loadMissing('teachingGridRows');
        $gridByDay = $package->teachingGridRows->keyBy('day_of_week');
        $windowsByDay = collect($windows)->groupBy('day_of_week');
        $totalMinutes = 0;
        $daysWithHours = 0;

        foreach ($windowsByDay as $day => $dayWindows) {
            $gridRow = $gridByDay->get((int) $day);
            if (! $gridRow instanceof TermTeachingGridRow) {
                continue;
            }

            $gridStart = FacultyAvailabilityDeclaration::clockToMinutes((string) $gridRow->starts_at);
            $gridEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) $gridRow->ends_at);
            if ($gridStart === null || $gridEnd === null || $gridStart >= $gridEnd) {
                continue;
            }

            $clipped = [];
            foreach ($dayWindows as $window) {
                $wStart = FacultyAvailabilityDeclaration::clockToMinutes($window['start_time']);
                $wEnd = FacultyAvailabilityDeclaration::clockToMinutes($window['end_time']);
                if ($wStart === null || $wEnd === null) {
                    continue;
                }
                $cStart = max($gridStart, $wStart);
                $cEnd = min($gridEnd, $wEnd);
                if ($cStart < $cEnd) {
                    $clipped[] = ['start' => $cStart, 'end' => $cEnd];
                }
            }

            $breaks = [];
            foreach (is_array($gridRow->breaks) ? $gridRow->breaks : [] as $break) {
                if (! is_array($break)) {
                    continue;
                }
                $bStart = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['starts_at'] ?? ''));
                $bEnd = FacultyAvailabilityDeclaration::clockToMinutes((string) ($break['ends_at'] ?? ''));
                if ($bStart !== null && $bEnd !== null && $bStart < $bEnd) {
                    $breaks[] = ['start' => $bStart, 'end' => $bEnd];
                }
            }

            $dayMinutes = 0;
            foreach (FacultyAvailabilityDeclaration::subtractIntervals($clipped, $breaks) as $segment) {
                $dayMinutes += ($segment['end'] - $segment['start']);
            }

            if ($dayMinutes > 0) {
                $totalMinutes += $dayMinutes;
                $daysWithHours++;
            }
        }

        if ($totalMinutes <= 0) {
            return null;
        }

        $hours = $totalMinutes / 60;
        $formattedHours = fmod($hours, 1.0) === 0.0
            ? (string) (int) $hours
            : rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
        $hourUnit = $formattedHours === '1' ? 'hour' : 'hours';
        $dayUnit = $daysWithHours === 1 ? 'day' : 'days';

        return sprintf('%s %s/week across %d %s (excluding breaks)', $formattedHours, $hourUnit, $daysWithHours, $dayUnit);
    }

    private function declarationSummary(FacultyAvailabilityDeclaration $record): string
    {
        return match ($record->effectiveMode()) {
            FacultyAvailabilityDeclaration::ModeCannotTeach => 'Unavailable for the whole Term',
            FacultyAvailabilityDeclaration::ModeAnyApproved => 'All approved school teaching hours',
            FacultyAvailabilityDeclaration::ModeWindows => collect($record->normalizedTeachingWindows())
                ->map(fn (array $window): string => sprintf(
                    '%s, %s–%s',
                    SectionMeeting::dayOptions()[(int) $window['day_of_week']] ?? 'Unknown day',
                    $this->displayIntervalTime($window['start_time']),
                    $this->displayIntervalTime($window['end_time']),
                ))
                ->implode('; ') ?: 'No teaching windows recorded',
            FacultyAvailabilityDeclaration::ModeLegacyUnavailability => 'Unavailable: '.collect($record->hard_unavailability)
                ->map(fn (array $interval): string => sprintf(
                    '%s, %s–%s',
                    SectionMeeting::dayOptions()[(int) $interval['day_of_week']] ?? 'Unknown day',
                    $this->displayIntervalTime((string) $interval['starts_at']),
                    $this->displayIntervalTime((string) $interval['ends_at']),
                ))
                ->implode('; '),
            default => 'All approved school teaching hours',
        };
    }

    private function displayIntervalTime(string $time): string
    {
        return CarbonImmutable::createFromFormat('!H:i', substr($time, 0, 5), (string) config('app.display_timezone', 'Asia/Manila'))->format('g:i A');
    }

    private function isRegistrarMarkedNotTeaching(): bool
    {
        $faculty = auth()->user();

        if ($this->termId === null || ! $faculty instanceof User) {
            return false;
        }

        return FacultyTermLoadOverride::query()
            ->where('term_id', $this->termId)
            ->where('faculty_user_id', $faculty->id)
            ->where('is_active', true)
            ->get()
            ->contains(fn (FacultyTermLoadOverride $override): bool => $override->allowedLoadUnits() <= 0.0);
    }

    private function isSelectedTermClosed(): bool
    {
        if ($this->termId === null) {
            return false;
        }

        return Term::query()
            ->whereKey($this->termId)
            ->where('state', Term::StateClosed)
            ->exists();
    }
}
