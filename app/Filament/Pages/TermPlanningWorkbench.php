<?php

namespace App\Filament\Pages;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Calendar\TermCalendarPackageReadinessService;
use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\FacultyAvailabilityRequestService;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\ReviewTimetableCandidate;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\SchedulePublicationImpactService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleSolverRetryService;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\Terms\TermResource;
use App\Models\CandidateScheduleRow;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermCohort;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

final class TermPlanningWorkbench extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Academic Planning';

    protected static ?string $navigationLabel = 'Term Planning';

    protected static ?string $title = 'Selected-Term Planning';

    protected string $view = 'filament.pages.term-planning-workbench';

    /** @var array<string, string> */
    protected array $extraBodyAttributes = ['class' => 'tala-term-planning-page'];

    #[Url]
    public ?int $termId = null;

    #[Url]
    public string $viewTab = 'overview';

    #[Url]
    public ?int $selectedRunId = null;

    #[Url]
    public string $candidateViewMode = 'matrix';

    #[Url]
    public ?string $candidateFilterFaculty = null;

    #[Url]
    public ?string $candidateFilterRoom = null;

    #[Url]
    public ?string $candidateFilterSection = null;

    #[Url]
    public ?string $candidateFilterModality = null;

    #[Url]
    public ?int $selectedVersionId = null;

    #[Url]
    public bool $isControlDeckCollapsed = false;

    public function toggleControlDeck(): void
    {
        $this->isControlDeckCollapsed = ! $this->isControlDeckCollapsed;
    }

    /** @var list<string> */
    private const Tabs = ['overview', 'classes', 'resources', 'generate', 'published'];

    public function mount(): void
    {
        if ($this->termId !== null && ! Term::query()->whereKey($this->termId)->exists()) {
            $this->termId = null;
        }

        if ($this->termId === null) {
            $activeTermIds = TermCalendarPackage::query()
                ->where('state', TermCalendarPackage::StateActive)
                ->distinct()
                ->pluck('term_id');

            if ($activeTermIds->count() === 1) {
                $this->termId = (int) $activeTermIds->sole();
            }
        }

        if ($this->selectedVersionId !== null) {
            $versionBelongsToTerm = PublishedTimetableVersion::query()
                ->whereKey($this->selectedVersionId)
                ->when($this->termId !== null, fn ($query) => $query->where('term_id', $this->termId))
                ->exists();

            if (! $versionBelongsToTerm) {
                $this->selectedVersionId = null;
            }
        }

        $this->viewTab = in_array($this->viewTab, self::Tabs, true) ? $this->viewTab : 'overview';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]);
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead)) {
            return [];
        }

        if ($this->termId === null) {
            return [];
        }

        return [
            $this->recordCalendarPackageAction(),
            $this->correctDraftCalendarPackageAction(),
            $this->activateCalendarPackageAction(),
            $this->requestFacultyAvailabilityAction(),
            $this->confirmClassAction(),
            $this->manageTeachingAssignmentAction(),
            $this->generateTimetableAction(),
            $this->acceptCandidateAction(),
            $this->rejectCandidateAction(),
            $this->retrySolverRunAction(),
            $this->publishOfficialTimetableAction(),
        ];
    }

    public function recordCalendarPackageAction(): Action
    {
        return Action::make('recordCalendarPackage')
            ->label('Record Calendar Package')
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->termId !== null)
            ->schema([
                DatePicker::make('administrative_starts_on')->required(),
                DatePicker::make('administrative_ends_on')->required()->after('administrative_starts_on'),
                DatePicker::make('classes_start_on')->required(),
                DatePicker::make('classes_end_on')->required()->after('classes_start_on'),
                DateTimePicker::make('faculty_availability_due_at')
                    ->label('Faculty availability deadline')
                    ->timezone((string) config('app.display_timezone'))
                    ->seconds(false)
                    ->required()
                    ->beforeOrEqual('classes_start_on'),
                TextInput::make('authority_reference')->required()->maxLength(255),
                DatePicker::make('authority_date')->required(),
                TextInput::make('special_term_schedule_basis')->maxLength(255),
                Repeater::make('windows')->schema([
                    Select::make('window_type')->options(TermCalendarWindow::typeOptions())->required(),
                    DatePicker::make('opens_on')->required(),
                    DatePicker::make('closes_on')->required()->afterOrEqual('opens_on'),
                    TimePicker::make('cutoff_at')->timezone((string) config('app.timezone'))->seconds(false),
                ])->minItems(3)->required()->columns(4),
                Repeater::make('teaching_grid_rows')->schema([
                    Select::make('day_of_week')->options([
                        1 => 'Monday',
                        2 => 'Tuesday',
                        3 => 'Wednesday',
                        4 => 'Thursday',
                        5 => 'Friday',
                        6 => 'Saturday',
                        7 => 'Sunday',
                    ])->required(),
                    TimePicker::make('starts_at')->timezone((string) config('app.timezone'))->seconds(false)->required(),
                    TimePicker::make('ends_at')->timezone((string) config('app.timezone'))->seconds(false)->required()->after('starts_at'),
                    Repeater::make('breaks')->schema([
                        TimePicker::make('starts_at')->timezone((string) config('app.timezone'))->seconds(false)->required(),
                        TimePicker::make('ends_at')->timezone((string) config('app.timezone'))->seconds(false)->required()->after('starts_at'),
                    ])->columns(2),
                ])->minItems(1)->required()->columns(3),
                Repeater::make('dated_exceptions')->schema([
                    DatePicker::make('starts_on')->required(),
                    DatePicker::make('ends_on')->required()->afterOrEqual('starts_on'),
                    TextInput::make('exception_type')->required()->maxLength(64),
                    TextInput::make('label')->required()->maxLength(255),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    Toggle::make('blocks_teaching')->label('Blocks teaching')->default(true),
                ])->columns(3),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                DB::transaction(function () use ($data, $actor): void {
                    $term = Term::query()->whereKey($this->selectedTerm()->id)->lockForUpdate()->firstOrFail();
                    $version = ((int) TermCalendarPackage::query()->where('term_id', $term->id)->max('version')) + 1;
                    $package = TermCalendarPackage::query()->create([
                        ...collect($data)->except(['windows', 'teaching_grid_rows', 'dated_exceptions'])->all(),
                        'term_id' => $term->id,
                        'version' => $version,
                        'state' => TermCalendarPackage::StateDraft,
                        'recorded_by' => $actor->id,
                    ]);
                    foreach ($data['windows'] as $window) {
                        $package->windows()->create($window);
                    }
                    foreach ($data['teaching_grid_rows'] as $row) {
                        $package->teachingGridRows()->create($row);
                    }
                    foreach ($data['dated_exceptions'] ?? [] as $exception) {
                        $package->datedExceptions()->create([
                            ...$exception,
                            'blocks_teaching' => (bool) ($exception['blocks_teaching'] ?? true),
                        ]);
                    }
                }, 3);
                Notification::make()->title('Draft Calendar Package recorded')->body('Review draft readiness and activate it when all checks pass.')->success()->send();
            });
    }

    public function correctDraftCalendarPackageAction(): Action
    {
        return Action::make('correctDraftCalendarPackage')
            ->label('Correct Draft Package')
            ->color('warning')
            ->visible(function (): bool {
                if (! (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) || $this->termId === null) {
                    return false;
                }

                return TermCalendarPackage::query()
                    ->where('term_id', $this->termId)
                    ->where('state', TermCalendarPackage::StateDraft)
                    ->exists();
            })
            ->fillForm(function (): array {
                if ($this->termId === null) {
                    return [];
                }

                $draft = TermCalendarPackage::query()
                    ->where('term_id', $this->termId)
                    ->where('state', TermCalendarPackage::StateDraft)
                    ->latest('version')
                    ->with(['windows', 'teachingGridRows', 'datedExceptions'])
                    ->first();

                if (! $draft) {
                    return [];
                }

                return [
                    'package_id' => $draft->id,
                    'concurrency_token' => $draft->concurrencyToken(),
                    'original_updated_at' => $draft->concurrencyToken(),
                    'administrative_starts_on' => $draft->administrative_starts_on?->toDateString(),
                    'administrative_ends_on' => $draft->administrative_ends_on?->toDateString(),
                    'classes_start_on' => $draft->classes_start_on?->toDateString(),
                    'classes_end_on' => $draft->classes_end_on?->toDateString(),
                    'faculty_availability_due_at' => $draft->faculty_availability_due_at?->timezone((string) config('app.display_timezone'))->format('Y-m-d H:i'),
                    'authority_reference' => $draft->authority_reference,
                    'authority_date' => $draft->authority_date?->toDateString(),
                    'special_term_schedule_basis' => $draft->special_term_schedule_basis,
                    'windows' => $draft->windows->map(fn (TermCalendarWindow $w): array => [
                        'window_type' => $w->window_type,
                        'opens_on' => $w->opens_on?->toDateString(),
                        'closes_on' => $w->closes_on?->toDateString(),
                        'cutoff_at' => $w->cutoff_at ? substr((string) $w->cutoff_at, 0, 5) : null,
                    ])->all(),
                    'teaching_grid_rows' => $draft->teachingGridRows->map(fn ($r): array => [
                        'day_of_week' => $r->day_of_week,
                        'starts_at' => substr((string) $r->starts_at, 0, 5),
                        'ends_at' => substr((string) $r->ends_at, 0, 5),
                        'breaks' => collect($r->breaks ?? [])->map(fn ($b): array => [
                            'starts_at' => isset($b['starts_at']) ? substr((string) $b['starts_at'], 0, 5) : null,
                            'ends_at' => isset($b['ends_at']) ? substr((string) $b['ends_at'], 0, 5) : null,
                        ])->all(),
                    ])->all(),
                    'dated_exceptions' => $draft->datedExceptions->map(fn ($e): array => [
                        'starts_on' => $e->starts_on?->toDateString(),
                        'ends_on' => $e->ends_on?->toDateString(),
                        'exception_type' => $e->exception_type,
                        'label' => $e->label,
                        'authority_reference' => $e->authority_reference,
                        'blocks_teaching' => (bool) $e->blocks_teaching,
                    ])->all(),
                ];
            })
            ->schema([
                Select::make('package_id')
                    ->label('Draft package')
                    ->options(fn (): array => TermCalendarPackage::query()
                        ->where('term_id', $this->selectedTerm()->id)
                        ->where('state', TermCalendarPackage::StateDraft)
                        ->get()
                        ->mapWithKeys(
                            fn (TermCalendarPackage $package): array => [$package->id => 'v'.$package->version.' · '.$package->authority_reference],
                        )->all())
                    ->required()
                    ->rules([
                        Rule::exists('term_calendar_packages', 'id')
                            ->where('term_id', $this->termId)
                            ->where('state', TermCalendarPackage::StateDraft),
                    ])
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if (! $state) {
                            return;
                        }

                        $package = TermCalendarPackage::query()
                            ->with(['windows', 'teachingGridRows', 'datedExceptions'])
                            ->find((int) $state);

                        if (! $package || $package->term_id !== $this->selectedTerm()->id || $package->state !== TermCalendarPackage::StateDraft) {
                            return;
                        }

                        $token = $package->concurrencyToken();
                        $set('concurrency_token', $token);
                        $set('original_updated_at', $token);
                        $set('administrative_starts_on', $package->administrative_starts_on?->toDateString());
                        $set('administrative_ends_on', $package->administrative_ends_on?->toDateString());
                        $set('classes_start_on', $package->classes_start_on?->toDateString());
                        $set('classes_end_on', $package->classes_end_on?->toDateString());
                        $set('faculty_availability_due_at', $package->faculty_availability_due_at?->timezone((string) config('app.display_timezone'))->format('Y-m-d H:i'));
                        $set('authority_reference', $package->authority_reference);
                        $set('authority_date', $package->authority_date?->toDateString());
                        $set('special_term_schedule_basis', $package->special_term_schedule_basis);
                        $set('windows', $package->windows->map(fn (TermCalendarWindow $w): array => [
                            'window_type' => $w->window_type,
                            'opens_on' => $w->opens_on?->toDateString(),
                            'closes_on' => $w->closes_on?->toDateString(),
                            'cutoff_at' => $w->cutoff_at ? substr((string) $w->cutoff_at, 0, 5) : null,
                        ])->all());
                        $set('teaching_grid_rows', $package->teachingGridRows->map(fn ($r): array => [
                            'day_of_week' => $r->day_of_week,
                            'starts_at' => substr((string) $r->starts_at, 0, 5),
                            'ends_at' => substr((string) $r->ends_at, 0, 5),
                            'breaks' => collect($r->breaks ?? [])->map(fn ($b): array => [
                                'starts_at' => isset($b['starts_at']) ? substr((string) $b['starts_at'], 0, 5) : null,
                                'ends_at' => isset($b['ends_at']) ? substr((string) $b['ends_at'], 0, 5) : null,
                            ])->all(),
                        ])->all());
                        $set('dated_exceptions', $package->datedExceptions->map(fn ($e): array => [
                            'starts_on' => $e->starts_on?->toDateString(),
                            'ends_on' => $e->ends_on?->toDateString(),
                            'exception_type' => $e->exception_type,
                            'label' => $e->label,
                            'authority_reference' => $e->authority_reference,
                            'blocks_teaching' => (bool) $e->blocks_teaching,
                        ])->all());
                    }),
                Hidden::make('concurrency_token'),
                Hidden::make('original_updated_at'),
                DatePicker::make('administrative_starts_on')->required(),
                DatePicker::make('administrative_ends_on')->required()->after('administrative_starts_on'),
                DatePicker::make('classes_start_on')->required(),
                DatePicker::make('classes_end_on')->required()->after('classes_start_on'),
                DateTimePicker::make('faculty_availability_due_at')
                    ->label('Faculty availability deadline')
                    ->timezone((string) config('app.display_timezone'))
                    ->seconds(false)
                    ->required()
                    ->beforeOrEqual('classes_start_on'),
                TextInput::make('authority_reference')->required()->maxLength(255),
                DatePicker::make('authority_date')->required(),
                TextInput::make('special_term_schedule_basis')->maxLength(255),
                Repeater::make('windows')->schema([
                    Select::make('window_type')->options(TermCalendarWindow::typeOptions())->required(),
                    DatePicker::make('opens_on')->required(),
                    DatePicker::make('closes_on')->required()->afterOrEqual('opens_on'),
                    TimePicker::make('cutoff_at')->timezone((string) config('app.timezone'))->seconds(false),
                ])->minItems(3)->required()->columns(4),
                Repeater::make('teaching_grid_rows')->schema([
                    Select::make('day_of_week')->options([
                        1 => 'Monday',
                        2 => 'Tuesday',
                        3 => 'Wednesday',
                        4 => 'Thursday',
                        5 => 'Friday',
                        6 => 'Saturday',
                        7 => 'Sunday',
                    ])->required(),
                    TimePicker::make('starts_at')->timezone((string) config('app.timezone'))->seconds(false)->required(),
                    TimePicker::make('ends_at')->timezone((string) config('app.timezone'))->seconds(false)->required()->after('starts_at'),
                    Repeater::make('breaks')->schema([
                        TimePicker::make('starts_at')->timezone((string) config('app.timezone'))->seconds(false)->required(),
                        TimePicker::make('ends_at')->timezone((string) config('app.timezone'))->seconds(false)->required()->after('starts_at'),
                    ])->columns(2),
                ])->minItems(1)->required()->columns(3),
                Repeater::make('dated_exceptions')->schema([
                    DatePicker::make('starts_on')->required(),
                    DatePicker::make('ends_on')->required()->afterOrEqual('starts_on'),
                    TextInput::make('exception_type')->required()->maxLength(64),
                    TextInput::make('label')->required()->maxLength(255),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    Toggle::make('blocks_teaching')->label('Blocks teaching')->default(true),
                ])->columns(3),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $term = $this->selectedTerm();
                Gate::forUser($actor)->authorize('update', $term);

                DB::transaction(function () use ($data, $term, $actor): void {
                    $package = TermCalendarPackage::query()
                        ->where('term_id', $term->id)
                        ->whereKey((int) $data['package_id'])
                        ->with(['windows', 'teachingGridRows', 'datedExceptions'])
                        ->lockForUpdate()
                        ->first();

                    if (! $package || $package->state !== TermCalendarPackage::StateDraft) {
                        throw ValidationException::withMessages([
                            'package_id' => 'Only a Draft Term Calendar Package can be corrected for this Term.',
                        ]);
                    }

                    $submittedToken = (string) ($data['concurrency_token'] ?? $data['original_updated_at'] ?? '');
                    if (blank($submittedToken) || ! hash_equals($package->concurrencyToken(), $submittedToken)) {
                        Notification::make()
                            ->title('Cannot correct Draft Calendar Package')
                            ->body('This Draft Calendar Package was modified by another Registrar while your form was open. Reopen the correction form to review the latest changes before saving.')
                            ->danger()
                            ->persistent()
                            ->send();

                        throw ValidationException::withMessages([
                            'package_id' => 'This Draft Calendar Package was modified by another Registrar while your form was open. Reopen the correction form to review the latest changes before saving.',
                        ]);
                    }

                    $package->update([
                        ...collect($data)->except(['package_id', 'concurrency_token', 'original_updated_at', 'windows', 'teaching_grid_rows', 'dated_exceptions'])->all(),
                        'recorded_by' => $actor->id,
                    ]);

                    $package->windows()->delete();
                    foreach ($data['windows'] as $window) {
                        $package->windows()->create($window);
                    }

                    $package->teachingGridRows()->delete();
                    foreach ($data['teaching_grid_rows'] as $row) {
                        $package->teachingGridRows()->create($row);
                    }

                    $package->datedExceptions()->delete();
                    foreach ($data['dated_exceptions'] ?? [] as $exception) {
                        $package->datedExceptions()->create([
                            ...$exception,
                            'blocks_teaching' => (bool) ($exception['blocks_teaching'] ?? true),
                        ]);
                    }
                }, attempts: 3);

                Notification::make()->title('Draft Calendar Package corrected')->body('Review draft readiness and activate it when all checks pass.')->success()->send();
            });
    }

    public function activateCalendarPackageAction(): Action
    {
        return Action::make('activateCalendarPackage')
            ->label('Activate Calendar Package')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(function (): bool {
                if (! (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) || $this->termId === null) {
                    return false;
                }

                return TermCalendarPackage::query()
                    ->where('term_id', $this->termId)
                    ->where('state', TermCalendarPackage::StateDraft)
                    ->exists();
            })
            ->modalHeading('Activate Calendar Package')
            ->modalDescription('Review the selected exact Term, package authority, readiness, and downstream operational windows before activating this package.')
            ->modalSubmitActionLabel('Activate Calendar Package')
            ->modalWidth('2xl')
            ->fillForm(function (): array {
                if ($this->termId === null) {
                    return [];
                }

                $drafts = TermCalendarPackage::query()
                    ->where('term_id', $this->termId)
                    ->where('state', TermCalendarPackage::StateDraft)
                    ->get();

                if ($drafts->count() === 1) {
                    return [
                        'package_id' => $drafts->first()->id,
                    ];
                }

                return [
                    'package_id' => null,
                ];
            })
            ->schema([
                Select::make('package_id')
                    ->label('Draft calendar package')
                    ->placeholder('Select a draft package...')
                    ->options(function (): array {
                        if ($this->termId === null) {
                            return [];
                        }

                        return TermCalendarPackage::query()
                            ->where('term_id', $this->termId)
                            ->where('state', TermCalendarPackage::StateDraft)
                            ->orderByDesc('version')
                            ->get()
                            ->mapWithKeys(function (TermCalendarPackage $package): array {
                                $details = collect([
                                    'v'.$package->version,
                                    $package->authority_reference ? 'Auth: '.$package->authority_reference : null,
                                    $package->authority_date ? 'Approved: '.$package->authority_date->toDateString() : null,
                                    ($package->classes_start_on && $package->classes_end_on)
                                        ? 'Classes: '.$package->classes_start_on->toDateString().' to '.$package->classes_end_on->toDateString()
                                        : null,
                                ])->filter()->implode(' · ');

                                return [$package->id => $details];
                            })
                            ->all();
                    })
                    ->default(function (): ?int {
                        if ($this->termId === null) {
                            return null;
                        }

                        $drafts = TermCalendarPackage::query()
                            ->where('term_id', $this->termId)
                            ->where('state', TermCalendarPackage::StateDraft)
                            ->get();

                        return $drafts->count() === 1 ? $drafts->first()->id : null;
                    })
                    ->required()
                    ->rules([
                        Rule::exists('term_calendar_packages', 'id')
                            ->where('term_id', $this->termId)
                            ->where('state', TermCalendarPackage::StateDraft),
                    ])
                    ->live()
                    ->afterStateUpdated(function (Page $livewire): void {
                        $livewire->dispatch('close-notification', id: 'calendar_package_activation_feedback');
                    })
                    ->afterStateUpdatedJs(<<<'JS'
                        window.dispatchEvent(new CustomEvent('close-notification', { detail: { id: 'calendar_package_activation_feedback' } }));
                    JS),
                Placeholder::make('activation_summary')
                    ->hiddenLabel()
                    ->content(function (Get $get): Htmlable {
                        if ($this->termId === null) {
                            return new HtmlString('');
                        }

                        $term = $this->selectedTerm();
                        $packageId = $get('package_id');
                        $package = null;
                        $readiness = null;

                        if ($packageId) {
                            $package = TermCalendarPackage::query()
                                ->where('term_id', $term->id)
                                ->where('state', TermCalendarPackage::StateDraft)
                                ->with(['windows', 'teachingGridRows', 'datedExceptions'])
                                ->find((int) $packageId);

                            if ($package) {
                                $readiness = app(TermCalendarPackageReadinessService::class)->for($package);
                            }
                        }

                        return new HtmlString(view('filament.pages.partials.activate-calendar-package-summary', [
                            'term' => $term,
                            'package' => $package,
                            'readiness' => $readiness,
                        ])->render());
                    })
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, Action $action): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                $term = $this->selectedTerm();
                Gate::forUser($actor)->authorize('update', $term);

                $packageId = (int) ($data['package_id'] ?? 0);
                $package = TermCalendarPackage::query()
                    ->where('term_id', $term->id)
                    ->where('state', TermCalendarPackage::StateDraft)
                    ->find($packageId);

                if (! $package) {
                    throw ValidationException::withMessages([
                        'package_id' => 'The selected calendar package is not an activatable draft for this Term.',
                    ]);
                }

                try {
                    app(ActivateTermCalendarPackage::class)->execute($package, $actor);
                    Notification::make('calendar_package_activation_feedback')
                        ->title('Calendar Package activated')
                        ->body("Calendar Package v{$package->version} is now Active for {$term->label}.")
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    $errors = collect($exception->errors())->flatten()->unique()->values()->all();
                    Notification::make('calendar_package_activation_feedback')
                        ->title('Cannot activate Calendar Package')
                        ->body(implode(' ', $errors))
                        ->danger()
                        ->duration(6000)
                        ->send();

                    throw $exception;
                } catch (Throwable $e) {
                    report($e);
                    Notification::make('calendar_package_activation_feedback')
                        ->title('Activation failed unexpectedly')
                        ->body('An unexpected error occurred while activating the Calendar Package. Please review the term state and try again.')
                        ->danger()
                        ->duration(8000)
                        ->send();

                    $action->halt();
                }
            });
    }

    public function requestFacultyAvailabilityAction(): Action
    {
        return Action::make('requestFacultyAvailability')
            ->label('Request Faculty Availability')
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalHeading('Request exact-Term availability declarations')
            ->modalDescription('Choose only affected Faculty who have not declared for this Term. TALA sends one attributable action-required email for this Calendar Package generation; routine saves send no email.')
            ->schema([
                Select::make('faculty_user_ids')
                    ->label('Affected Faculty')
                    ->options(function (): array {
                        $term = $this->selectedTerm();
                        $declaredIds = FacultyAvailabilityDeclaration::query()
                            ->where('term_id', $term->id)
                            ->pluck('faculty_user_id');

                        return User::query()
                            ->where('status', User::StatusActive)
                            ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleFaculty))
                            ->whereNotIn('id', $declaredIds)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->multiple()
                    ->searchable()
                    ->required(),
            ])
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->activeCalendarPackage() instanceof TermCalendarPackage)
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $package = $this->activeCalendarPackage();
                abort_unless($package instanceof TermCalendarPackage, 409);

                $events = app(FacultyAvailabilityRequestService::class)->request(
                    $package,
                    $data['faculty_user_ids'] ?? [],
                    $actor,
                );

                Notification::make()
                    ->title('Faculty availability requests recorded')
                    ->body($events->count().' attributable request record(s) are available for delivery tracking and authorized resend.')
                    ->success()
                    ->send();
            });
    }

    public function confirmClassAction(): Action
    {
        return Action::make('confirmClassOffering')
            ->label('Confirm Class Offering')
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->termId !== null)
            ->schema([
                Select::make('section_id')->label('Class Offering')->options(Section::query()
                    ->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $this->selectedTerm()->id))
                    ->whereNull('confirmed_at')
                    ->orderBy('code')
                    ->pluck('code', 'id'))->required()->searchable(),
                Repeater::make('cohorts')->schema([
                    Select::make('term_cohort_id')->label('Cohort')->options(TermCohort::query()
                        ->where('term_id', $this->selectedTerm()->id)
                        ->orderBy('reference')
                        ->pluck('reference', 'id'))->required()->searchable(),
                    TextInput::make('expected_count')->numeric()->integer()->minValue(1)->required(),
                ])->minItems(1)->required()->columns(2),
                TextInput::make('additional_authority_reference')->maxLength(255),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $counts = collect($data['cohorts'])->mapWithKeys(
                    fn (array $row): array => [(int) $row['term_cohort_id'] => (int) $row['expected_count']],
                )->all();
                app(ConfirmClassOffering::class)->execute(
                    Section::query()->findOrFail((int) $data['section_id']),
                    $actor,
                    $counts,
                    $data['additional_authority_reference'] ?? null,
                );
                Notification::make()->title('Class Offering confirmed')->success()->send();
            });
    }

    public function manageTeachingAssignmentAction(): Action
    {
        return Action::make('manageTeachingAssignment')
            ->label('Assign Teaching Faculty')
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->termId !== null)
            ->schema([
                Select::make('section_id')
                    ->label('Official Class Offering')
                    ->options(Section::query()
                        ->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $this->selectedTerm()->id))
                        ->whereNotNull('confirmed_at')
                        ->orderBy('code')
                        ->pluck('code', 'id'))
                    ->required()->searchable(),
                Select::make('faculty_user_id')
                    ->label('Faculty')
                    ->options(User::query()->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleFaculty))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->required()->searchable(),
                Select::make('role')
                    ->options([
                        ClassOfferingTeachingAssignment::RoleDesignated => 'Designated submitter',
                        ClassOfferingTeachingAssignment::RoleCoFaculty => 'View-only co-Faculty',
                    ])->required(),
                TextInput::make('authority_reference')
                    ->label('Assignment authority')
                    ->helperText('Record the memo, load, or Registrar authority used for this attributable assignment.')
                    ->required()->maxLength(255),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $section = Section::query()->findOrFail((int) $data['section_id']);
                $faculty = User::query()->findOrFail((int) $data['faculty_user_id']);
                $assignments = app(ManageTeachingAssignment::class);

                if ($data['role'] === ClassOfferingTeachingAssignment::RoleDesignated) {
                    $assignments->designate($section, $faculty, $actor, (string) $data['authority_reference']);
                    app(SynchronizeOfficialGradeRoster::class)->execute($section, $actor);
                } else {
                    $assignments->addCoFaculty($section, $faculty, $actor, (string) $data['authority_reference']);
                }

                Notification::make()->title('Teaching assignment recorded')->success()->send();
            });
    }

    public function generateTimetableAction(): Action
    {
        return Action::make('generateTimetable')
            ->label('Generate Timetable')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Generate Timetable')
            ->modalDescription(function (): string {
                $term = $this->termId ? Term::query()->find($this->termId) : null;
                $label = $term ? "{$term->label}" : 'the selected term';

                return "Captures the current ready requirements for {$label} as one protected request, then sends it to the configured timetable generator. Nothing becomes official until Registrar review and publication.";
            })
            ->modalSubmitActionLabel('Generate Timetable')
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->termId !== null)
            ->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                try {
                    $term = Term::query()->findOrFail($this->termId);
                    $run = app(ScheduleGenerationService::class)->generate($term, $actor);
                    $this->selectedRunId = $run->id;
                    $this->viewTab = 'generate';

                    Notification::make()
                        ->title('Timetable generation requested')
                        ->body("Request #{$run->id} captured the current ready requirements. Its status refreshes automatically.")
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first();

                    Notification::make()
                        ->title('Timetable generation blocked')
                        ->body(is_string($message) ? $message : 'Review the Schedule Requirement findings and try again.')
                        ->danger()
                        ->persistent()
                        ->send();
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title('Timetable generation failed')
                        ->body('TALA could not queue the timetable request. Try again or review the application log.')
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    public function acceptCandidateAction(): Action
    {
        return Action::make('acceptCandidate')
            ->label('Accept Candidate')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Accept Candidate Timetable')
            ->modalDescription('This attributable review records that the candidate meets academic requirements. It remains non-official until separately published.')
            ->schema([
                Textarea::make('candidate_review_reason')
                    ->label('Review reason')
                    ->required()
                    ->maxLength(2000),
            ])
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->canReviewActiveRun())
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $run = $this->activeScheduleRun();
                abort_unless($run instanceof ScheduleGenerationRun, 404);

                app(ReviewTimetableCandidate::class)->accept($run, $actor, (string) $data['candidate_review_reason']);

                Notification::make()
                    ->title('Candidate Accepted')
                    ->body('The candidate has been marked Accepted. It remains non-official until separately published.')
                    ->success()
                    ->send();
            });
    }

    public function rejectCandidateAction(): Action
    {
        return Action::make('rejectCandidate')
            ->label('Reject Candidate')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Reject Candidate Timetable')
            ->modalDescription('Record why this candidate schedule is rejected. A rejected candidate cannot be published.')
            ->schema([
                Textarea::make('candidate_review_reason')
                    ->label('Rejection reason')
                    ->required()
                    ->maxLength(2000),
            ])
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->canReviewActiveRun())
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $run = $this->activeScheduleRun();
                abort_unless($run instanceof ScheduleGenerationRun, 404);

                app(ReviewTimetableCandidate::class)->reject($run, $actor, (string) $data['candidate_review_reason']);

                Notification::make()
                    ->title('Candidate Rejected')
                    ->body('The candidate has been marked Rejected and cannot be published.')
                    ->danger()
                    ->send();
            });
    }

    public function retrySolverRunAction(): Action
    {
        return Action::make('retrySolverRun')
            ->label('Retry Generation')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Retry Timetable Generation')
            ->modalDescription('Retry this same protected generation request. Previous solver diagnostics remain in the operational log.')
            ->modalSubmitActionLabel('Retry Solver')
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->canRetryActiveRun())
            ->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $run = $this->activeScheduleRun();
                abort_unless($run instanceof ScheduleGenerationRun, 404);

                try {
                    $retried = app(ScheduleSolverRetryService::class)->retry($run, $actor);
                    $this->selectedRunId = $retried->id;

                    Notification::make()
                        ->title('Solver run requeued')
                        ->body('Generation has been re-dispatched to the solver.')
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Solver retry blocked')
                        ->body(is_string($message) ? $message : 'The solver run cannot be retried.')
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    public function publishOfficialTimetableAction(): Action
    {
        return Action::make('publishOfficialTimetable')
            ->label('Publish Official Timetable')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Publish Official Timetable')
            ->modalDescription(fn (): string => $this->publicationModalDescription())
            ->modalSubmitActionLabel('Publish Official Timetable')
            ->schema([
                TextInput::make('authority_reference')
                    ->label('External timetable sign-off reference')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Record the official board, council, or Registrar sign-off authority reference.'),
                Textarea::make('publication_note')
                    ->label('Publication reason / note')
                    ->maxLength(2000)
                    ->required(fn (): bool => $this->publicationReasonRequirement() !== null)
                    ->helperText(fn (): string => $this->publicationReasonRequirement()
                        ?? 'Optional for optimal candidate without quality warnings.'),
            ])
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->canPublishActiveRun())
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $run = $this->activeScheduleRun();
                abort_unless($run instanceof ScheduleGenerationRun, 404);

                try {
                    app(SchedulePublishService::class)->publish(
                        $run,
                        $actor,
                        $data['publication_note'] ?? null,
                        authorityReference: $data['authority_reference'] ?? null,
                    );

                    $this->viewTab = 'published';
                    $this->selectedVersionId = null;

                    Notification::make()
                        ->title('Official Timetable Published')
                        ->body('The timetable is now authoritative. Switched to Published Timetable view.')
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Schedule publication blocked')
                        ->body(is_string($message) ? $message : 'The schedule failed validation and cannot be published.')
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    public function activeScheduleRun(): ?ScheduleGenerationRun
    {
        if ($this->termId === null) {
            return null;
        }

        if ($this->selectedRunId !== null) {
            $run = ScheduleGenerationRun::query()
                ->where('term_id', $this->termId)
                ->whereKey($this->selectedRunId)
                ->first();

            if ($run instanceof ScheduleGenerationRun) {
                return $run;
            }
        }

        return ScheduleGenerationRun::query()
            ->where('term_id', $this->termId)
            ->latest('id')
            ->first();
    }

    public function canReviewActiveRun(): bool
    {
        $actor = auth()->user();
        $run = $this->activeScheduleRun();

        return $actor instanceof User
            && $run instanceof ScheduleGenerationRun
            && $run->status === ScheduleGenerationRun::StatusUnderReview
            && ! in_array($run->candidate_state, ['Accepted', 'Rejected', 'Stale', 'Superseded'], true)
            && Gate::forUser($actor)->allows('reviewCandidates', $run);
    }

    public function canRetryActiveRun(): bool
    {
        $actor = auth()->user();
        $run = $this->activeScheduleRun();

        return $actor instanceof User
            && $run instanceof ScheduleGenerationRun
            && Gate::forUser($actor)->allows('retry', $run)
            && $run->canRetrySolver();
    }

    public function canPublishActiveRun(): bool
    {
        $actor = auth()->user();
        $run = $this->activeScheduleRun();

        return $actor instanceof User
            && $run instanceof ScheduleGenerationRun
            && Gate::forUser($actor)->allows('publish', $run)
            && $run->canBePublished();
    }

    private function publicationModalDescription(): string
    {
        $run = $this->activeScheduleRun();
        if (! $run instanceof ScheduleGenerationRun) {
            return '';
        }

        $summary = $run->publicationSummary();
        $impact = app(SchedulePublicationImpactService::class)->preview($run);

        $description = sprintf(
            '%d candidate assignments, %d warnings, and %d conflicts. Impact: %d new, %d changed, %d removed, and %d unchanged; %d affected faculty. Publication makes these assignments official and supersedes any prior published version.',
            $summary['assignments'],
            $summary['warnings'],
            $summary['conflicts'],
            $impact->newAssignments(),
            $impact->changedAssignments(),
            $impact->removedAssignments(),
            $impact->unchangedAssignments(),
            $impact->affectedFaculty(),
        );

        if ($impact->blocksFullReplacement()) {
            return $description.' Warning: Full replacement is blocked because active official student registrations exist. Controlled revision must be used instead.';
        }

        return $description;
    }

    private function publicationReasonRequirement(): ?string
    {
        $run = $this->activeScheduleRun();
        if (! $run instanceof ScheduleGenerationRun) {
            return null;
        }

        return app(SchedulePublishService::class)->publicationReasonRequirement($run);
    }

    public function selectRun(int $runId): void
    {
        $this->selectedRunId = $runId;
    }

    public function setCandidateViewMode(string $mode): void
    {
        $this->candidateViewMode = in_array($mode, ['matrix', 'table'], true) ? $mode : 'matrix';
    }

    public function resetCandidateFilters(): void
    {
        $this->candidateFilterFaculty = null;
        $this->candidateFilterRoom = null;
        $this->candidateFilterSection = null;
        $this->candidateFilterModality = null;
    }

    public function selectPublishedVersion(int $versionId): void
    {
        $version = PublishedTimetableVersion::query()
            ->whereKey($versionId)
            ->when($this->termId !== null, fn ($query) => $query->where('term_id', $this->termId))
            ->first();

        $this->selectedVersionId = $version?->id;
    }

    public function selectTerm(int $termId): void
    {
        abort_unless(Term::query()->whereKey($termId)->exists(), 404);
        $this->termId = $termId;
        $this->viewTab = 'overview';
        $this->selectedRunId = null;
        $this->selectedVersionId = null;
    }

    public function showTab(string $tab): void
    {
        abort_unless(in_array($tab, self::Tabs, true), 404);
        $this->viewTab = $tab;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $terms = Term::query()->with(['academicYear', 'calendarPackages'])->latest('starts_on')->get();
        $term = $terms->firstWhere('id', $this->termId);
        $activePackage = $term instanceof Term
            ? TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)->with('teachingGridRows')->first()
            : null;
        $currentVersion = $term instanceof Term
            ? PublishedTimetableVersion::query()->where('term_id', $term->id)->where('state', PublishedTimetableVersion::StatePublished)->withCount('meetings')->first()
            : null;
        $versions = $term instanceof Term
            ? PublishedTimetableVersion::query()->where('term_id', $term->id)->withCount('meetings')->latest('version')->get()
            : collect();
        $readinessService = app(TermCalendarPackageReadinessService::class);
        $draftPackages = $term instanceof Term
            ? TermCalendarPackage::query()
                ->where('term_id', $term->id)
                ->where('state', TermCalendarPackage::StateDraft)
                ->with(['windows', 'teachingGridRows', 'datedExceptions'])
                ->orderBy('version')
                ->get()
                ->map(fn (TermCalendarPackage $p): array => [
                    'package' => $p,
                    'readiness' => $readinessService->for($p),
                ])
            : collect();

        $activeRun = $term instanceof Term ? $this->activeScheduleRun() : null;
        $allRuns = $term instanceof Term
            ? ScheduleGenerationRun::query()->where('term_id', $term->id)->latest('id')->get()
            : collect();

        $allCandidateRows = collect();
        $filteredCandidateRows = collect();
        $candidateFacultyOptions = collect();
        $candidateRoomOptions = collect();
        $candidateSectionOptions = collect();
        $candidateModalityOptions = collect();
        $solverOutcome = [];
        $solverDispatch = [];
        $solverFailure = [];
        $qualityMeasures = [];
        $publicationSummary = ['assignments' => 0, 'warnings' => 0, 'conflicts' => 0];

        if ($activeRun instanceof ScheduleGenerationRun) {
            $diagnostics = is_array($activeRun->diagnostics) ? $activeRun->diagnostics : [];
            $solverOutcome = (array) ($diagnostics['solver_outcome'] ?? []);
            $solverDispatch = (array) ($diagnostics['solver_dispatch'] ?? []);
            $solverFailure = $activeRun->finalSolverFailure();
            $qualityMeasures = is_array($activeRun->quality_measures) ? $activeRun->quality_measures : [];
            $publicationSummary = $activeRun->publicationSummary();

            $allCandidateRows = $activeRun->candidateRows()
                ->with([
                    'faculty',
                    'room',
                    'schedulingDemand.courseComponent.courseSpecification.course',
                    'schedulingDemand.sectionDeliveryGroup.section',
                    'schedulingDemand.termOffering',
                ])
                ->orderBy('day_of_week')
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get();

            $candidateFacultyOptions = $allCandidateRows
                ->filter(fn (CandidateScheduleRow $r): bool => $r->faculty !== null)
                ->mapWithKeys(fn (CandidateScheduleRow $r): array => [(int) $r->faculty_user_id => (string) $r->faculty?->name])
                ->unique()
                ->sort();

            $candidateRoomOptions = $allCandidateRows
                ->filter(fn (CandidateScheduleRow $r): bool => $r->room !== null)
                ->mapWithKeys(fn (CandidateScheduleRow $r): array => [(int) $r->room_id => (string) ($r->room?->code ?? $r->room?->name)])
                ->unique()
                ->sort();

            $candidateSectionOptions = $allCandidateRows
                ->mapWithKeys(function (CandidateScheduleRow $r): array {
                    $section = data_get($r, 'schedulingDemand.sectionDeliveryGroup.section');

                    return $section ? [(int) $section->id => (string) $section->code] : [];
                })
                ->unique()
                ->sort();

            $candidateModalityOptions = $allCandidateRows
                ->mapWithKeys(function (CandidateScheduleRow $r): array {
                    $modality = data_get($r, 'schedulingDemand.modality');

                    return $modality ? [(string) $modality => (string) str($modality)->headline()] : [];
                })
                ->filter()
                ->unique()
                ->sort();

            $filteredCandidateRows = $allCandidateRows;

            if ($this->candidateFilterFaculty) {
                $filteredCandidateRows = $filteredCandidateRows->where('faculty_user_id', (int) $this->candidateFilterFaculty);
            }
            if ($this->candidateFilterRoom) {
                $filteredCandidateRows = $filteredCandidateRows->where('room_id', (int) $this->candidateFilterRoom);
            }
            if ($this->candidateFilterSection) {
                $filteredCandidateRows = $filteredCandidateRows->filter(
                    fn (CandidateScheduleRow $r): bool => (int) data_get($r, 'schedulingDemand.sectionDeliveryGroup.section.id') === (int) $this->candidateFilterSection,
                );
            }
            if ($this->candidateFilterModality) {
                $filteredCandidateRows = $filteredCandidateRows->filter(
                    fn (CandidateScheduleRow $r): bool => (string) data_get($r, 'schedulingDemand.modality') === (string) $this->candidateFilterModality,
                );
            }
        }

        $matrixDays = [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];
        if (
            $allCandidateRows->contains('day_of_week', 7)
            || ($activePackage?->teachingGridRows && $activePackage->teachingGridRows->contains('day_of_week', 7))
            || (is_array($term?->scheduling_days) && in_array(7, $term->scheduling_days, true))
        ) {
            $matrixDays[7] = 'Sunday';
        }

        $startHour = null;
        $endHour = null;

        if ($activePackage instanceof TermCalendarPackage && $activePackage->teachingGridRows->isNotEmpty()) {
            $startHour = (int) $activePackage->teachingGridRows->map(fn ($r): int => (int) substr((string) $r->starts_at, 0, 2))->min();
            $endHour = (int) $activePackage->teachingGridRows->map(function ($r): int {
                $h = (int) substr((string) $r->ends_at, 0, 2);
                $m = (int) substr((string) $r->ends_at, 3, 2);

                return $m > 0 ? $h : max(0, $h - 1);
            })->max();
        }

        if ($startHour === null && $term instanceof Term && $term->scheduling_day_starts_at !== null) {
            $startHour = (int) substr((string) $term->scheduling_day_starts_at, 0, 2);
        }

        if ($endHour === null && $term instanceof Term && $term->scheduling_day_ends_at !== null) {
            $h = (int) substr((string) $term->scheduling_day_ends_at, 0, 2);
            $m = (int) substr((string) $term->scheduling_day_ends_at, 3, 2);
            $endHour = $m > 0 ? $h : max(0, $h - 1);
        }

        $startHour ??= 7;
        $endHour ??= 20;

        if ($allCandidateRows->isNotEmpty()) {
            $candidateStart = $allCandidateRows->map(fn ($r): int => (int) substr((string) $r->starts_at, 0, 2))->min();
            if ($candidateStart !== null && $candidateStart < $startHour) {
                $startHour = (int) $candidateStart;
            }

            $candidateEnd = $allCandidateRows->map(function ($r): int {
                $h = (int) substr((string) $r->ends_at, 0, 2);
                $m = (int) substr((string) $r->ends_at, 3, 2);

                return $m > 0 ? $h : max(0, $h - 1);
            })->max();

            if ($candidateEnd !== null && $candidateEnd > $endHour) {
                $endHour = (int) $candidateEnd;
            }
        }

        $matrixHours = range($startHour, max($startHour, $endHour));

        // Published timetable view data
        $selectedVersion = null;
        if ($this->selectedVersionId !== null) {
            $selectedVersion = $versions->firstWhere('id', $this->selectedVersionId);
            if (! $selectedVersion instanceof PublishedTimetableVersion) {
                $this->selectedVersionId = null;
            }
        }

        if (! $selectedVersion instanceof PublishedTimetableVersion) {
            $selectedVersion = $currentVersion ?? $versions->first();
        }

        $publishedMeetings = $selectedVersion instanceof PublishedTimetableVersion
            ? $selectedVersion->meetings()
                ->with([
                    'classOffering',
                    'faculty',
                    'room',
                    'schedulingDemand.courseComponent.courseSpecification.course',
                ])
                ->orderBy('day_of_week')
                ->orderBy('starts_at')
                ->get()
            : collect();

        return [
            'terms' => $terms,
            'term' => $term,
            'activePackage' => $activePackage,
            'draftPackages' => $draftPackages,
            'currentVersion' => $currentVersion,
            'versions' => $versions,
            'selectedVersion' => $selectedVersion,
            'publishedMeetings' => $publishedMeetings,
            'readiness' => $term instanceof Term ? app(ReadyTermPlanningProjection::class)->forTerm($term) : null,
            'counts' => $term instanceof Term ? [
                'classes' => Section::query()->where('term_calendar_package_id', $activePackage?->id)->count(),
                'confirmed' => Section::query()->where('term_calendar_package_id', $activePackage?->id)->whereNotNull('confirmed_at')->count(),
                'runs' => ScheduleGenerationRun::query()->where('term_id', $term->id)->count(),
            ] : ['classes' => 0, 'confirmed' => 0, 'runs' => 0],
            'tabs' => [
                'overview' => 'Overview',
                'classes' => 'Cohorts & Classes',
                'resources' => 'Teaching Resources',
                'generate' => 'Generate & Review',
                'published' => 'Published Timetable',
            ],
            'destinations' => [
                'overview' => TermResource::getUrl(),
                'classes' => SectionResource::getUrl(),
                'resources' => RoomResource::getUrl(),
                'generate' => ScheduleGenerationRunResource::getUrl(),
                'published' => SectionMeetingResource::getUrl(),
            ],
            'readOnly' => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? true,
            'activeRun' => $activeRun,
            'allRuns' => $allRuns,
            'solverOutcome' => $solverOutcome,
            'solverDispatch' => $solverDispatch,
            'solverFailure' => $solverFailure,
            'qualityMeasures' => $qualityMeasures,
            'publicationSummary' => $publicationSummary,
            'candidateRows' => $filteredCandidateRows,
            'allCandidateRowsCount' => $allCandidateRows->count(),
            'candidateFacultyOptions' => $candidateFacultyOptions,
            'candidateRoomOptions' => $candidateRoomOptions,
            'candidateSectionOptions' => $candidateSectionOptions,
            'candidateModalityOptions' => $candidateModalityOptions,
            'matrixDays' => $matrixDays,
            'matrixHours' => $matrixHours,
        ];
    }

    private function selectedTerm(): Term
    {
        abort_if($this->termId === null, 404);

        return Term::query()->findOrFail($this->termId);
    }

    private function activeCalendarPackage(): ?TermCalendarPackage
    {
        if ($this->termId === null) {
            return null;
        }

        return TermCalendarPackage::query()
            ->where('term_id', $this->termId)
            ->where('state', TermCalendarPackage::StateActive)
            ->first();
    }
}
