<?php

namespace App\Filament\Pages;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\AcademicYear;
use App\Models\AdmissionCycle;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use App\Support\TeachingWeekSummary;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class TermCalendar extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable;

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?string $title = 'Term calendar';

    protected static ?string $slug = 'calendar';

    protected static ?int $navigationSort = 1;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = true;

    protected static string $planningTab = 'overview';

    protected string $view = 'filament.pages.term-calendar';

    public function teachingWeekRows(TermCalendarPackage $package): array
    {
        return app(TeachingWeekSummary::class)->rows($package);
    }

    public function datedExceptionTypeLabel(?string $type): ?string
    {
        if (! filled($type)) {
            return null;
        }

        return $this->datedExceptionTypeOptions($type)[$type] ?? $type;
    }

    /**
     * @return list<array{short_day: string, day: string, hours: string, breaks: ?string}>
     */
    public function teachingDayChips(TermCalendarPackage $package): array
    {
        $package->loadMissing('teachingGridRows');

        return $package->teachingGridRows
            ->sortBy('day_of_week')
            ->map(function ($row): array {
                $day = SectionMeeting::dayOptions()[$row->day_of_week] ?? 'Unknown day';
                $hours = CarbonImmutable::parse($row->starts_at)->format('g:i A').'–'.CarbonImmutable::parse($row->ends_at)->format('g:i A');
                $breaks = collect($row->breaks ?? [])
                    ->map(fn (array $break): string => CarbonImmutable::parse($break['starts_at'])->format('g:i A').'–'.CarbonImmutable::parse($break['ends_at'])->format('g:i A'))
                    ->implode(', ');

                return [
                    'short_day' => substr($day, 0, 3),
                    'day' => $day,
                    'hours' => $hours,
                    'breaks' => $breaks !== '' ? $breaks : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, code: string, date_range: string, time_note: ?string, status: string, status_color: string, url: string}>
     */
    public function linkedAdmissionCycles(Term $term): array
    {
        $stateOrder = [
            AdmissionCycle::StatePublished => 0,
            AdmissionCycle::StateDraft => 1,
            AdmissionCycle::StateCancelled => 2,
        ];

        return AdmissionCycle::query()
            ->where('term_id', $term->id)
            ->orderByDesc('opens_at')
            ->orderByDesc('id')
            ->get()
            ->sortBy(fn (AdmissionCycle $cycle): int => $stateOrder[$cycle->state] ?? 9)
            ->map(function (AdmissionCycle $cycle): array {
                $status = match (true) {
                    $cycle->state === AdmissionCycle::StateDraft => 'Draft',
                    $cycle->state === AdmissionCycle::StateCancelled => 'Cancelled',
                    $cycle->state !== AdmissionCycle::StatePublished || ! $cycle->opens_at || ! $cycle->closes_at => 'Window unavailable',
                    $cycle->opens_at->isFuture() => 'Scheduled',
                    $cycle->closes_at <= now() => 'Closed',
                    default => 'Open',
                };

                $statusColor = match ($status) {
                    'Open' => 'success',
                    'Scheduled' => 'info',
                    'Cancelled' => 'danger',
                    'Window unavailable' => 'warning',
                    default => 'gray',
                };

                $dateRange = 'Dates not set';
                $timeNote = null;

                if ($cycle->opens_at && $cycle->closes_at) {
                    $opens = $cycle->opens_at->timezone('Asia/Manila');
                    $closes = $cycle->closes_at->timezone('Asia/Manila');
                    $dateRange = $opens->year === $closes->year
                        ? $opens->format('M j').' – '.$closes->format('M j, Y')
                        : $opens->format('M j, Y').' – '.$closes->format('M j, Y');
                    $timeParts = [
                        'Opens '.$opens->format('g:i A'),
                        'Closes '.$closes->format('g:i A'),
                    ];
                    if ($cycle->correction_closes_at) {
                        $correctionCloses = $cycle->correction_closes_at->timezone('Asia/Manila');
                        $timeParts[] = 'Corrections until '.$correctionCloses->format('M j, Y g:i A');
                    }
                    $timeParts[] = 'Asia/Manila';
                    $timeNote = implode(' · ', $timeParts);
                }

                return [
                    'id' => (int) $cycle->id,
                    'label' => filled($cycle->label) ? (string) $cycle->label : (string) $cycle->code,
                    'code' => (string) $cycle->code,
                    'date_range' => $dateRange,
                    'time_note' => $timeNote,
                    'status' => $status,
                    'status_color' => $statusColor,
                    'url' => AdmissionCycleResource::getUrl('view', ['record' => $cycle]),
                ];
            })
            ->values()
            ->all();
    }

    public function admissionsIndexUrl(): string
    {
        return AdmissionCycleResource::getUrl('index');
    }

    /**
     * @return array{
     *     classes_run: string,
     *     classes_run_note: ?string,
     *     teaching_hours: string,
     *     teaching_hours_note: string,
     *     grid_slots: string,
     *     grid_slots_note: string
     * }
     */
    public function teachingScheduleSummary(?TermCalendarPackage $package, ?Term $term = null): array
    {
        if (! $package instanceof TermCalendarPackage) {
            $start = $term?->starts_on;
            $end = $term?->ends_on;
            $classesRun = ($start && $end)
                ? ($start->year === $end->year
                    ? $start->format('M j').' – '.$end->format('M j, Y')
                    : $start->format('M j, Y').' – '.$end->format('M j, Y'))
                : 'Not set';

            return [
                'classes_run' => $classesRun,
                'classes_run_note' => 'Pre-filled from term dates (not yet activated)',
                'teaching_hours' => 'Mon–Sat · 7:00 AM–7:00 PM',
                'teaching_hours_note' => 'Default 6-day teaching schedule',
                'grid_slots' => '30-minute intervals',
                'grid_slots_note' => 'Asia/Manila',
            ];
        }

        $package->loadMissing('teachingGridRows');
        $start = $package->classes_start_on;
        $end = $package->classes_end_on;
        $classesRun = ($start && $end)
            ? ($start->year === $end->year
                ? $start->format('M j').' – '.$end->format('M j, Y')
                : $start->format('M j, Y').' – '.$end->format('M j, Y'))
            : 'Dates missing';

        $classesRunNote = null;
        if ($package->administrative_starts_on && $package->administrative_ends_on
            && ($package->administrative_starts_on->toDateString() !== $start?->toDateString()
                || $package->administrative_ends_on->toDateString() !== $end?->toDateString())) {
            $adminStart = $package->administrative_starts_on;
            $adminEnd = $package->administrative_ends_on;
            $adminRange = $adminStart->year === $adminEnd->year
                ? $adminStart->format('M j').' – '.$adminEnd->format('M j, Y')
                : $adminStart->format('M j, Y').' – '.$adminEnd->format('M j, Y');
            $classesRunNote = 'Term dates: '.$adminRange;
        }

        $rows = $package->teachingGridRows->sortBy('day_of_week')->values();
        $dayCount = $rows->count();
        $dayOptions = SectionMeeting::dayOptions();

        if ($dayCount === 0) {
            $teachingHours = 'Not configured';
            $teachingHoursNote = 'No approved teaching days';
        } else {
            $shortDays = $rows->map(fn ($row): string => substr($dayOptions[(int) $row->day_of_week] ?? 'Day', 0, 3))->values()->all();
            $daySpan = $rows->pluck('day_of_week')->map(fn (mixed $d): int => (int) $d)->values()->all() === range((int) $rows->first()->day_of_week, (int) $rows->last()->day_of_week) && $dayCount > 2
                ? $shortDays[0].'–'.$shortDays[$dayCount - 1]
                : implode(', ', $shortDays);

            $uniqueRanges = $rows->map(
                fn ($row): string => CarbonImmutable::parse($row->starts_at)->format('g:i A').'–'.CarbonImmutable::parse($row->ends_at)->format('g:i A')
            )->unique()->values();

            $teachingHours = $uniqueRanges->count() === 1
                ? $daySpan.' · '.$uniqueRanges->first()
                : $daySpan.' (varies by day)';

            $breakCount = $rows->sum(fn ($row): int => count($row->breaks ?? []));
            $teachingHoursNote = $dayCount.' '.str('teaching day')->plural($dayCount).'/week'
                .($breakCount > 0 ? ' · '.$breakCount.' '.str('break')->plural($breakCount) : '');
        }

        return [
            'classes_run' => $classesRun,
            'classes_run_note' => $classesRunNote,
            'teaching_hours' => $teachingHours,
            'teaching_hours_note' => $teachingHoursNote,
            'grid_slots' => '30-minute intervals',
            'grid_slots_note' => 'Asia/Manila',
        ];
    }

    /**
     * @return array{confirmed_classes: int, draft_runs: int}|null
     */
    private function calendarChangeImpactSummary(): ?array
    {
        if ($this->termId === null || ! $this->activeCalendarPackage() instanceof TermCalendarPackage) {
            return null;
        }

        $confirmedClasses = Section::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $this->termId))
            ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])
            ->whereNotNull('confirmed_at')
            ->count();

        $draftRuns = ScheduleGenerationRun::query()
            ->where('term_id', $this->termId)
            ->whereIn('status', [
                ScheduleGenerationRun::StatusQueued,
                ScheduleGenerationRun::StatusDispatching,
                ScheduleGenerationRun::StatusUnderReview,
            ])
            ->where(function ($query): void {
                $query->whereNull('candidate_state')
                    ->orWhereNotIn('candidate_state', ['Stale', 'Superseded', 'Rejected']);
            })
            ->count();

        if ($confirmedClasses === 0 && $draftRuns === 0) {
            return null;
        }

        return [
            'confirmed_classes' => $confirmedClasses,
            'draft_runs' => $draftRuns,
        ];
    }

    public function saveAndActivateCalendarAction(): Action
    {
        return Action::make('saveAndActivateCalendar')
            ->label(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage()
                ? 'Edit calendar'
                : 'Activate calendar')
            ->icon(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage()
                ? 'heroicon-o-pencil-square'
                : 'heroicon-o-check-circle')
            ->color(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage() ? 'gray' : 'primary')
            ->size('sm')
            ->modalHeading(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage()
                ? 'Edit term calendar'
                : 'Set up term calendar')
            ->modalDescription(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage()
                ? 'Update teaching dates, weekly teaching hours, or school events. Saving updates the active term calendar immediately.'
                : 'Pre-filled with term dates and default Mon–Sat hours. Review and activate to start planning.')
            ->modalSubmitActionLabel(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage()
                ? 'Save changes'
                : 'Activate calendar')
            ->modalWidth('5xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->termId !== null
                && ! $this->hasPublishedTimetable()
                && ! $this->isTermClosed())
            ->fillForm(function (): array {
                $draft = $this->latestDraftCalendarPackage();
                if ($draft instanceof TermCalendarPackage) {
                    return $this->extractPackageFormData($draft) + [
                        'draft_package_id' => $draft->id,
                        'draft_token' => $draft->concurrencyToken(),
                    ];
                }

                $active = $this->activeCalendarPackage();
                if ($active instanceof TermCalendarPackage) {
                    return $this->extractPackageFormData($active) + [
                        'draft_package_id' => null,
                        'draft_token' => null,
                    ];
                }

                return $this->defaultCalendarFormData($this->selectedTerm()) + [
                    'draft_package_id' => null,
                    'draft_token' => null,
                ];
            })
            ->schema([
                Hidden::make('draft_package_id'),
                Hidden::make('draft_token'),
                Placeholder::make('calendar_change_impact')
                    ->hiddenLabel()
                    ->visible(fn (): bool => $this->calendarChangeImpactSummary() !== null)
                    ->content(function (): HtmlString {
                        $impact = $this->calendarChangeImpactSummary();
                        if ($impact === null) {
                            return new HtmlString('');
                        }

                        $parts = [];
                        if ($impact['confirmed_classes'] > 0) {
                            $parts[] = $impact['confirmed_classes'].' confirmed '.str('class')->plural($impact['confirmed_classes']);
                        }
                        if ($impact['draft_runs'] > 0) {
                            $parts[] = $impact['draft_runs'].' draft timetable '.str('attempt')->plural($impact['draft_runs']);
                        }
                        $scopeText = implode(' and ', $parts);

                        return new HtmlString(
                            '<div class="tala-warning-banner" role="status">'
                            .'<p><strong>Impact warning:</strong> Changing dates or teaching hours will mark existing draft timetable attempt(s) stale and require re-confirming affected classes. '
                            .'('.e($scopeText).' currently recorded for this term.)</p>'
                            .'</div>'
                        );
                    })
                    ->columnSpanFull(),
                SchemaSection::make('Teaching dates')
                    ->description('Pre-filled from term dates. Adjust if class dates differ from the administrative term window.')
                    ->schema([
                        DatePicker::make('administrative_starts_on')->label('Term start')->required(),
                        DatePicker::make('administrative_ends_on')->label('Term end')->required()->afterOrEqual('administrative_starts_on'),
                        DatePicker::make('classes_start_on')
                            ->label('Classes start')
                            ->minDate(fn (): ?string => $this->boundedAcademicYearForSelectedTerm()?->starts_on?->toDateString())
                            ->maxDate(fn (): ?string => $this->boundedAcademicYearForSelectedTerm()?->ends_on?->toDateString())
                            ->rules([$this->calendarAcademicYearBoundRule('start')])
                            ->required(),
                        DatePicker::make('classes_end_on')
                            ->label('Classes end')
                            ->minDate(fn (): ?string => $this->boundedAcademicYearForSelectedTerm()?->starts_on?->toDateString())
                            ->maxDate(fn (): ?string => $this->boundedAcademicYearForSelectedTerm()?->ends_on?->toDateString())
                            ->rules([$this->calendarAcademicYearBoundRule('end')])
                            ->required()
                            ->afterOrEqual('classes_start_on'),
                        TextInput::make('special_term_schedule_basis')
                            ->label('Special Term class-day and class-hour basis')
                            ->maxLength(255)
                            ->visible(fn (): bool => $this->selectedTerm()?->type === Term::TypeSpecialTerm)
                            ->required(fn (): bool => $this->selectedTerm()?->type === Term::TypeSpecialTerm)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                SchemaSection::make('Teaching hours')
                    ->description('Standard Mon–Sat 7:00 AM–7:00 PM schedule is pre-filled. Scheduling uses 30-minute intervals in Asia/Manila.')
                    ->schema([
                        Repeater::make('teaching_grid_rows')
                            ->label('Teaching days & hours')
                            ->schema([
                                Select::make('day_of_week')->label('Day')->options(SectionMeeting::dayOptions())->required()->distinct(),
                                TimePicker::make('starts_at')->label('Start time')->timezone('UTC')->seconds(false)->required(),
                                TimePicker::make('ends_at')->label('End time')->timezone('UTC')->seconds(false)->required()->after('starts_at'),
                                Repeater::make('breaks')
                                    ->label('Daily breaks (optional)')
                                    ->defaultItems(0)
                                    ->addActionLabel('Add break')
                                    ->schema([
                                        TimePicker::make('starts_at')->label('Start time')->timezone('UTC')->seconds(false)->required(),
                                        TimePicker::make('ends_at')->label('End time')->timezone('UTC')->seconds(false)->required()->after('starts_at'),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),
                            ])
                            ->minItems(1)
                            ->required()
                            ->reorderable(false)
                            ->columns(3),
                    ]),
                SchemaSection::make('School events & no-class days (optional)')
                    ->description('Holidays, school events, class suspensions, or make-up class days.')
                    ->collapsible()
                    ->collapsed(fn (Get $get): bool => blank($get('dated_exceptions')))
                    ->schema([
                        Repeater::make('dated_exceptions')
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->addActionLabel('Add school event or no-class day')
                            ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null)
                                ? (string) $state['label']
                                : (filled($state['exception_type'] ?? null) ? ($this->datedExceptionTypeOptions((string) $state['exception_type'])[(string) $state['exception_type']] ?? (string) $state['exception_type']) : null))
                            ->schema([
                                DatePicker::make('starts_on')
                                    ->native(false)
                                    ->displayFormat('M j, Y')
                                    ->suffixIcon(Heroicon::OutlinedCalendar)
                                    ->required(),
                                DatePicker::make('ends_on')
                                    ->native(false)
                                    ->displayFormat('M j, Y')
                                    ->suffixIcon(Heroicon::OutlinedCalendar)
                                    ->required()
                                    ->afterOrEqual('starts_on'),
                                Select::make('exception_type')
                                    ->label('Event type')
                                    ->options(fn (?string $state): array => $this->datedExceptionTypeOptions($state))
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Set $set): void {
                                        $blocksTeaching = $this->defaultBlocksTeachingForExceptionType($state);

                                        if ($blocksTeaching !== null) {
                                            $set('blocks_teaching', $blocksTeaching);
                                        }
                                    }),
                                TextInput::make('label')->label('Event name')->required()->maxLength(255),
                                Toggle::make('blocks_teaching')->label('Suspend classes on these dates')->default(true),
                            ])
                            ->columns(2),
                    ]),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $term = $this->selectedTerm();
                Gate::forUser($actor)->authorize('update', $term);

                if ($this->isTermClosed($term) || $this->hasPublishedTimetable($term)) {
                    throw ValidationException::withMessages([
                        'classes_start_on' => $this->isTermClosed($term)
                            ? 'This term is closed and read-only.'
                            : 'Official timetable is published. Make schedule changes in Timetable via Revise schedule.',
                    ]);
                }

                $boundedAcademicYear = $this->boundedAcademicYearForTerm($term);
                if ($boundedAcademicYear instanceof AcademicYear) {
                    $rangeLabel = $boundedAcademicYear->starts_on->format('M j, Y').' – '.$boundedAcademicYear->ends_on->format('M j, Y');
                    $boundErrors = [];

                    if (filled($data['classes_start_on'] ?? null)) {
                        $classesStart = CarbonImmutable::parse((string) $data['classes_start_on'])->startOfDay();
                        if (! $classesStart->betweenIncluded($boundedAcademicYear->starts_on->startOfDay(), $boundedAcademicYear->ends_on->startOfDay())) {
                            $boundErrors['classes_start_on'] = "Classes start date must fall within {$boundedAcademicYear->displayLabel()} ({$rangeLabel}).";
                        }
                    }

                    if (filled($data['classes_end_on'] ?? null)) {
                        $classesEnd = CarbonImmutable::parse((string) $data['classes_end_on'])->startOfDay();
                        if (! $classesEnd->betweenIncluded($boundedAcademicYear->starts_on->startOfDay(), $boundedAcademicYear->ends_on->startOfDay())) {
                            $boundErrors['classes_end_on'] = "Classes end date must fall within {$boundedAcademicYear->displayLabel()} ({$rangeLabel}).";
                        }
                    }

                    if ($boundErrors !== []) {
                        throw ValidationException::withMessages($boundErrors);
                    }
                }

                DB::transaction(function () use ($data, $actor, $term): void {
                    $lockedTerm = Term::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();

                    $activeSourcePackage = TermCalendarPackage::query()
                        ->where('term_id', $lockedTerm->id)
                        ->where('state', TermCalendarPackage::StateActive)
                        ->with('windows')
                        ->latest('version')
                        ->first();

                    $package = null;
                    $reusedDraft = false;
                    $draftId = filter_var($data['draft_package_id'] ?? null, FILTER_VALIDATE_INT);

                    if ($draftId !== false && $draftId > 0) {
                        $package = TermCalendarPackage::query()
                            ->where('term_id', $lockedTerm->id)
                            ->where('state', TermCalendarPackage::StateDraft)
                            ->whereKey($draftId)
                            ->lockForUpdate()
                            ->first();

                        if ($package instanceof TermCalendarPackage) {
                            $submittedToken = (string) ($data['draft_token'] ?? '');
                            if (blank($submittedToken) || ! hash_equals($package->concurrencyToken(), $submittedToken)) {
                                throw ValidationException::withMessages([
                                    'classes_start_on' => 'This calendar draft was updated by another user. Reopen and try again.',
                                ]);
                            }

                            $package->update([
                                ...collect($data)->except(['draft_package_id', 'draft_token', 'windows', 'teaching_grid_rows', 'dated_exceptions'])->all(),
                                'recorded_by' => $actor->id,
                            ]);
                            // Key term dates are edited exclusively via Card 2 (Set dates); preserve any saved windows.
                            $package->teachingGridRows()->delete();
                            $package->datedExceptions()->delete();
                            $reusedDraft = true;
                        }
                    }

                    if (! $package instanceof TermCalendarPackage) {
                        $version = ((int) TermCalendarPackage::query()->where('term_id', $lockedTerm->id)->max('version')) + 1;
                        $package = TermCalendarPackage::query()->create([
                            ...collect($data)->except(['draft_package_id', 'draft_token', 'windows', 'teaching_grid_rows', 'dated_exceptions'])->all(),
                            'term_id' => $lockedTerm->id,
                            'version' => $version,
                            'state' => TermCalendarPackage::StateDraft,
                            'recorded_by' => $actor->id,
                        ]);
                    }

                    if (! $reusedDraft && $activeSourcePackage instanceof TermCalendarPackage) {
                        foreach ($activeSourcePackage->windows as $window) {
                            $package->windows()->create([
                                'window_type' => $window->window_type,
                                'opens_on' => $window->opens_on?->toDateString(),
                                'closes_on' => $window->closes_on?->toDateString(),
                                'cutoff_at' => $window->cutoff_at,
                            ]);
                        }
                    }

                    foreach ($data['teaching_grid_rows'] ?? [] as $row) {
                        $package->teachingGridRows()->create($row);
                    }

                    foreach ($data['dated_exceptions'] ?? [] as $exception) {
                        $package->datedExceptions()->create([
                            ...$exception,
                            'authority_reference' => filled($exception['authority_reference'] ?? null)
                                ? trim((string) $exception['authority_reference'])
                                : null,
                            'blocks_teaching' => (bool) ($exception['blocks_teaching'] ?? true),
                        ]);
                    }

                    $freshPackage = $package->fresh(['windows', 'teachingGridRows', 'datedExceptions']);
                    app(ActivateTermCalendarPackage::class)->execute($freshPackage, $actor, $freshPackage->concurrencyToken());
                }, attempts: 3);

                Notification::make()
                    ->title('Term calendar saved and activated')
                    ->body("The term calendar is now active for {$term->label}.")
                    ->success()
                    ->send();
            });
    }

    public function discardDraftCalendarAction(): Action
    {
        return Action::make('discardDraftCalendar')
            ->label('Discard draft')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Discard draft calendar?')
            ->modalDescription('Removes this unactivated draft calendar so the active calendar remains the only current teaching schedule. This cannot be undone.')
            ->modalSubmitActionLabel('Discard draft')
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->termId !== null
                && ! $this->hasPublishedTimetable()
                && ! $this->isTermClosed()
                && $this->latestDraftCalendarPackage() instanceof TermCalendarPackage)
            ->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

                $term = $this->selectedTerm();
                Gate::forUser($actor)->authorize('update', $term);

                if ($this->isTermClosed($term) || $this->hasPublishedTimetable($term)) {
                    throw ValidationException::withMessages([
                        'draft' => 'Draft calendars cannot be discarded once the official timetable is published or the term is closed.',
                    ]);
                }

                $removed = 0;

                DB::transaction(function () use ($term, &$removed): void {
                    $drafts = TermCalendarPackage::query()
                        ->where('term_id', $term->id)
                        ->where('state', TermCalendarPackage::StateDraft)
                        ->lockForUpdate()
                        ->get();

                    foreach ($drafts as $draft) {
                        $hasSections = Section::query()
                            ->where('term_calendar_package_id', $draft->id)
                            ->exists();

                        if ($hasSections) {
                            $draft->update([
                                'state' => TermCalendarPackage::StateClosed,
                                'closed_at' => now(),
                            ]);
                        } else {
                            $draft->windows()->delete();
                            $draft->teachingGridRows()->delete();
                            $draft->datedExceptions()->delete();
                            $draft->delete();
                        }

                        $removed++;
                    }
                }, attempts: 3);

                if ($removed > 0) {
                    Notification::make()
                        ->title('Draft calendar discarded')
                        ->body('The unactivated draft calendar was removed.')
                        ->success()
                        ->send();
                }
            });
    }

    /** @return array<string, mixed> */
    public function defaultCalendarFormData(Term $term): array
    {
        return [
            'authority_reference' => null,
            'authority_date' => null,
            'administrative_starts_on' => $term->starts_on?->toDateString(),
            'administrative_ends_on' => $term->ends_on?->toDateString(),
            'classes_start_on' => $term->starts_on?->toDateString(),
            'classes_end_on' => $term->ends_on?->toDateString(),
            'special_term_schedule_basis' => null,
            'windows' => [],
            'teaching_grid_rows' => collect([1, 2, 3, 4, 5, 6])->map(fn (int $day): array => [
                'day_of_week' => $day,
                'starts_at' => '07:00',
                'ends_at' => '19:00',
                'breaks' => [],
            ])->all(),
            'dated_exceptions' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function extractPackageFormData(TermCalendarPackage $package): array
    {
        $package->loadMissing(['windows', 'teachingGridRows', 'datedExceptions']);

        return [
            ...$package->only(['authority_reference', 'special_term_schedule_basis']),
            'administrative_starts_on' => $package->administrative_starts_on?->toDateString(),
            'administrative_ends_on' => $package->administrative_ends_on?->toDateString(),
            'classes_start_on' => $package->classes_start_on?->toDateString(),
            'classes_end_on' => $package->classes_end_on?->toDateString(),
            'authority_date' => $package->authority_date?->toDateString(),
            'windows' => $package->windows->map(fn (TermCalendarWindow $window): array => [
                'window_type' => $window->window_type,
                'opens_on' => $window->opens_on?->toDateString(),
                'closes_on' => $window->closes_on?->toDateString(),
                'cutoff_at' => $window->cutoff_at,
            ])->all(),
            'teaching_grid_rows' => $package->teachingGridRows->sortBy('day_of_week')->map(fn ($row): array => [
                'day_of_week' => $row->day_of_week,
                'starts_at' => substr((string) $row->starts_at, 0, 5),
                'ends_at' => substr((string) $row->ends_at, 0, 5),
                'breaks' => collect($row->breaks ?? [])->map(fn ($break): array => [
                    'starts_at' => isset($break['starts_at']) ? substr((string) $break['starts_at'], 0, 5) : null,
                    'ends_at' => isset($break['ends_at']) ? substr((string) $break['ends_at'], 0, 5) : null,
                ])->all(),
            ])->values()->all(),
            'dated_exceptions' => $package->datedExceptions->map(fn ($exception): array => [
                ...$exception->only(['exception_type', 'label', 'authority_reference', 'blocks_teaching']),
                'starts_on' => $exception->starts_on?->toDateString(),
                'ends_on' => $exception->ends_on?->toDateString(),
            ])->all(),
        ];
    }

    private function latestDraftCalendarPackage(): ?TermCalendarPackage
    {
        if ($this->termId === null) {
            return null;
        }

        return TermCalendarPackage::query()
            ->where('term_id', $this->termId)
            ->where('state', TermCalendarPackage::StateDraft)
            ->latest('version')
            ->first();
    }

    private function boundedAcademicYearForSelectedTerm(): ?AcademicYear
    {
        if ($this->termId === null) {
            return null;
        }

        return $this->boundedAcademicYearForTerm($this->selectedTerm());
    }

    private function boundedAcademicYearForTerm(Term $term): ?AcademicYear
    {
        $term->loadMissing('academicYear');
        $academicYear = $term->academicYear;

        if (! $academicYear instanceof AcademicYear
            || $academicYear->starts_on === null
            || $academicYear->ends_on === null
            || $term->starts_on === null
            || $term->ends_on === null) {
            return null;
        }

        if (! $term->starts_on->betweenIncluded($academicYear->starts_on, $academicYear->ends_on)
            || ! $term->ends_on->betweenIncluded($academicYear->starts_on, $academicYear->ends_on)) {
            return null;
        }

        return $academicYear;
    }

    private function calendarAcademicYearBoundRule(string $boundary): Closure
    {
        return function () use ($boundary): Closure {
            return function (string $attribute, mixed $value, Closure $fail) use ($boundary): void {
                if (blank($value)) {
                    return;
                }

                $academicYear = $this->boundedAcademicYearForSelectedTerm();

                if (! $academicYear instanceof AcademicYear) {
                    return;
                }

                $date = CarbonImmutable::parse((string) $value)->startOfDay();
                $startsOn = CarbonImmutable::parse($academicYear->starts_on)->startOfDay();
                $endsOn = CarbonImmutable::parse($academicYear->ends_on)->startOfDay();

                if ($date->betweenIncluded($startsOn, $endsOn)) {
                    return;
                }

                $label = $boundary === 'start' ? 'start' : 'end';
                $rangeLabel = $academicYear->starts_on->format('M j, Y').' – '.$academicYear->ends_on->format('M j, Y');
                $fail("Classes {$label} date must fall within {$academicYear->displayLabel()} ({$rangeLabel}).");
            };
        };
    }

    public function table(Table $table): Table
    {
        return $table->records(function (): array {
            $package = TermCalendarPackage::query()
                ->where('term_id', $this->termId)
                ->whereIn('state', [TermCalendarPackage::StateDraft, TermCalendarPackage::StateActive])
                ->latest('version')
                ->with('teachingGridRows')
                ->first();

            return $package?->teachingGridRows->sortBy('day_of_week')->mapWithKeys(fn ($row): array => [$row->id => [
                'day' => SectionMeeting::dayOptions()[$row->day_of_week] ?? 'Unknown day',
                'hours' => CarbonImmutable::parse($row->starts_at)->format('g:i A').'–'.CarbonImmutable::parse($row->ends_at)->format('g:i A'),
                'breaks' => collect($row->breaks ?? [])->map(fn (array $break): string => CarbonImmutable::parse($break['starts_at'])->format('g:i A').'–'.CarbonImmutable::parse($break['ends_at'])->format('g:i A'))->implode(', ') ?: 'None recorded',
            ]])->all() ?? [];
        })->columns([TextColumn::make('day'), TextColumn::make('hours')->label('Teaching hours'), TextColumn::make('breaks')->wrap()])
            ->paginated(false)
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading('No approved teaching days recorded')
            ->emptyStateDescription(fn (): string => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'The Registrar sets the term calendar before classes can be planned.' : 'Set the term calendar before adding classes.');
    }
}
