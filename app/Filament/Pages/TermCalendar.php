<?php

namespace App\Filament\Pages;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use App\Support\TeachingWeekSummary;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class TermCalendar extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable;

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?string $title = 'Calendar';

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

    public function recordCalendarPackageAction(): Action
    {
        return parent::recordCalendarPackageAction()
            ->fillForm(function (): array {
                $active = $this->activeCalendarPackage();
                if ($active instanceof TermCalendarPackage) {
                    return $this->extractPackageFormData($active) + [
                        'source_package_id' => $active->id,
                        'source_token' => $active->concurrencyToken(),
                    ];
                }

                return $this->defaultCalendarFormData($this->selectedTerm()) + [
                    'source_package_id' => null,
                    'source_token' => null,
                ];
            });
    }

    public function saveAndActivateCalendarAction(): Action
    {
        return Action::make('saveAndActivateCalendar')
            ->label(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage()
                ? 'Update & activate calendar'
                : 'Save & activate calendar')
            ->icon('heroicon-o-check-circle')
            ->color(fn (): string => $this->activeCalendarPackage() && ! $this->latestDraftCalendarPackage() ? 'gray' : 'primary')
            ->modalHeading('Save & activate teaching calendar')
            ->modalDescription('Review the pre-filled Term dates and weekly teaching hours, adjust if needed, and activate immediately.')
            ->modalSubmitActionLabel('Save & activate calendar')
            ->modalWidth('5xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->termId !== null)
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
                SchemaSection::make('Teaching dates')
                    ->description('Pre-filled from Term dates. Adjust if class dates differ from the administrative Term window.')
                    ->schema([
                        DatePicker::make('administrative_starts_on')->label('Term start')->required(),
                        DatePicker::make('administrative_ends_on')->label('Term end')->required()->afterOrEqual('administrative_starts_on'),
                        DatePicker::make('classes_start_on')->label('Classes start')->required(),
                        DatePicker::make('classes_end_on')->label('Classes end')->required()->afterOrEqual('classes_start_on'),
                        TextInput::make('authority_reference')->label('Approval note or memo (optional)')->maxLength(255),
                        DatePicker::make('authority_date')->label('Approval date (optional)'),
                        TextInput::make('special_term_schedule_basis')
                            ->label('Special Term class-day and class-hour basis')
                            ->maxLength(255)
                            ->visible(fn (): bool => $this->selectedTerm()->type === Term::TypeSpecialTerm)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                SchemaSection::make('Teaching hours')
                    ->description('Standard Mon–Sat 7:00 AM–7:00 PM schedule is pre-filled. Scheduling uses 30-minute slots in Asia/Manila.')
                    ->schema([
                        Repeater::make('teaching_grid_rows')
                            ->label('Approved teaching days')
                            ->schema([
                                Select::make('day_of_week')->options(SectionMeeting::dayOptions())->required()->distinct(),
                                TimePicker::make('starts_at')->timezone('UTC')->seconds(false)->required(),
                                TimePicker::make('ends_at')->timezone('UTC')->seconds(false)->required()->after('starts_at'),
                                Repeater::make('breaks')
                                    ->label('Recurring institutional breaks')
                                    ->defaultItems(0)
                                    ->schema([
                                        TimePicker::make('starts_at')->timezone('UTC')->seconds(false)->required(),
                                        TimePicker::make('ends_at')->timezone('UTC')->seconds(false)->required()->after('starts_at'),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),
                            ])
                            ->minItems(1)
                            ->required()
                            ->reorderable(false)
                            ->columns(3),
                    ]),
                SchemaSection::make('Activity dates (optional)')
                    ->description('Enrollment, grading, and exam dates. Optional—does not block class planning.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Repeater::make('windows')
                            ->label('Approved activity dates')
                            ->defaultItems(0)
                            ->schema([
                                Select::make('window_type')->options(TermCalendarWindow::typeOptions())->required()->distinct(),
                                DatePicker::make('opens_on')->required(),
                                DatePicker::make('closes_on')->required()->afterOrEqual('opens_on'),
                                TimePicker::make('cutoff_at')->label('Close time (Asia/Manila)')->timezone('UTC')->seconds(false),
                            ])
                            ->columns(2),
                    ]),
                SchemaSection::make('Calendar exceptions (optional)')
                    ->description('Holidays, no-class days, or make-up dates.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Repeater::make('dated_exceptions')
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->schema([
                                DatePicker::make('starts_on')->required(),
                                DatePicker::make('ends_on')->required()->afterOrEqual('starts_on'),
                                TextInput::make('exception_type')->required()->maxLength(64),
                                TextInput::make('label')->required()->maxLength(255),
                                TextInput::make('authority_reference')->label('Approval memo or reference')->required()->maxLength(255),
                                Toggle::make('blocks_teaching')->label('No classes')->default(true),
                            ])
                            ->columns(2),
                    ]),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $term = $this->selectedTerm();
                Gate::forUser($actor)->authorize('update', $term);

                DB::transaction(function () use ($data, $actor, $term): void {
                    $lockedTerm = Term::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();

                    $package = null;
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
                            $package->windows()->delete();
                            $package->teachingGridRows()->delete();
                            $package->datedExceptions()->delete();
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

                    foreach ($data['windows'] ?? [] as $window) {
                        $package->windows()->create($window);
                    }

                    foreach ($data['teaching_grid_rows'] ?? [] as $row) {
                        $package->teachingGridRows()->create($row);
                    }

                    foreach ($data['dated_exceptions'] ?? [] as $exception) {
                        $package->datedExceptions()->create([
                            ...$exception,
                            'authority_reference' => trim((string) ($exception['authority_reference'] ?? '')),
                            'blocks_teaching' => (bool) ($exception['blocks_teaching'] ?? true),
                        ]);
                    }

                    $freshPackage = $package->fresh(['windows', 'teachingGridRows', 'datedExceptions']);
                    app(ActivateTermCalendarPackage::class)->execute($freshPackage, $actor, $freshPackage->concurrencyToken());
                }, attempts: 3);

                Notification::make()
                    ->title('Teaching calendar saved and activated')
                    ->body("The teaching calendar is now active for {$term->label}.")
                    ->success()
                    ->send();
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
            ->emptyStateDescription(fn (): string => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'The Registrar records the teaching calendar before classes can be planned.' : 'Record the teaching calendar before planning classes.');
    }
}
