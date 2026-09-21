<?php

namespace App\Filament\Pages;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Calendar\TermCalendarPackageReadinessService;
use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\FacultyAvailabilityRequestService;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\Terms\TermResource;
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
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
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
                    ->searchable(),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $package = TermCalendarPackage::query()
                    ->where('term_id', $this->selectedTerm()->id)
                    ->where('state', TermCalendarPackage::StateDraft)
                    ->find((int) $data['package_id']);

                if (! $package) {
                    throw ValidationException::withMessages([
                        'package_id' => 'The selected calendar package is not an activatable draft for this Term.',
                    ]);
                }

                try {
                    app(ActivateTermCalendarPackage::class)->execute($package, $actor);
                    Notification::make()->title('Calendar Package activated')->success()->send();
                } catch (ValidationException $exception) {
                    $errors = collect($exception->errors())->flatten()->all();
                    Notification::make()
                        ->title('Cannot activate Calendar Package')
                        ->body(implode(' ', $errors))
                        ->danger()
                        ->persistent()
                        ->send();

                    throw $exception;
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

    public function selectTerm(int $termId): void
    {
        abort_unless(Term::query()->whereKey($termId)->exists(), 404);
        $this->termId = $termId;
        $this->viewTab = 'overview';
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
            ? TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)->first()
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

        return [
            'terms' => $terms,
            'term' => $term,
            'activePackage' => $activePackage,
            'draftPackages' => $draftPackages,
            'currentVersion' => $currentVersion,
            'versions' => $versions,
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
