<?php

namespace App\Filament\Pages;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\TermCalendarPackageReadinessService;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\FacultyAvailabilityRequestService;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeadline;
use App\Actions\Scheduling\ReviewTimetableCandidate;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\SchedulePublicationImpactService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleSolverRetryService;
use App\Actions\Scheduling\TermCoverageProjection;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Filament\Resources\Terms\TermResource;
use App\Models\CandidateScheduleRow;
use App\Models\FacultyAvailabilityDeadline;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\HasClassPreparationActions;
use App\Support\TimetablePresentation;
use BackedEnum;
use Carbon\CarbonImmutable;
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
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

class TermPlanningWorkbench extends Page
{
    use HasClassPreparationActions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Academic Planning';

    protected static ?string $navigationLabel = 'Term Planning';

    protected static ?string $title = 'Term Planning';

    protected string $view = 'filament.pages.term-planning-workbench';

    /** @var array<string, string> */
    protected array $extraBodyAttributes = ['class' => 'tala-term-planning-page'];

    #[Url]
    #[Locked]
    public ?int $termId = null;

    #[Url]
    public bool $chooseTerm = false;

    public bool $termAutoSelected = false;

    #[Url]
    public string $viewTab = 'plan';

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

    /** @var list<string> */
    protected static bool $shouldRegisterNavigation = false;

    protected static string $planningTab = 'plan';

    private const Tabs = ['plan', 'overview', 'classes', 'resources', 'generate', 'published'];

    /** @param array<mixed> $parameters */
    public static function getUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {

        if (static::class === self::class) {

            $page = match ($parameters['viewTab'] ?? 'plan') {

                'overview' => TermCalendar::class,

                'classes' => TermClasses::class,

                'resources' => TermResources::class,

                'generate', 'published' => TermTimetable::class,

                default => TermOverview::class,

            };

            if (($parameters['viewTab'] ?? null) === 'published') {

                $parameters['timetableState'] = 'official';

            }

            unset($parameters['viewTab']);

            return $page::getUrl($parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);

        }

        return parent::getUrl($parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);

    }

    /** @return array<NavigationItem> */
    public function getSubNavigation(): array
    {
        return [];

    }

    /** @return array<NavigationItem> */
    public function getWorkspaceNavigation(): array
    {
        return TermPlanningCluster::workspaceNavigation($this->termId, static::$planningTab);

    }

    public function mount(): void
    {

        if ($this->chooseTerm) {
            $this->termId = null;
        } elseif ($this->termId !== null) {
            abort_unless(Term::query()->whereKey($this->termId)->exists(), 404);
        }

        if ($this->termId === null && ! $this->chooseTerm) {

            $activeTermIds = TermCalendarPackage::query()

                ->where('state', TermCalendarPackage::StateActive)

                ->distinct()

                ->pluck('term_id');

            if ($activeTermIds->count() === 1) {

                $this->termId = (int) $activeTermIds->sole();

                $this->termAutoSelected = true;

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

        if (static::class === self::class) {

            $this->redirect(self::getUrl(['termId' => $this->termId, 'chooseTerm' => $this->chooseTerm ?: null, 'viewTab' => $this->viewTab, 'selectedRunId' => $this->selectedRunId, 'selectedVersionId' => $this->selectedVersionId]));

            return;

        }

        if ($this->termId === null && static::class !== TermOverview::class) {
            $this->redirect(TermOverview::getUrl(['chooseTerm' => true]));

            return;
        }

        $this->viewTab = static::class === self::class ? (in_array($this->viewTab, self::Tabs, true) ? $this->viewTab : 'plan') : static::$planningTab;

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

        return [];

    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function recordCalendarPackageAction(): Action
    {

        return $this->calendarRecordingAction();

    }

    public function addOperationalDatesAction(): Action
    {

        return $this->calendarRecordingAction(datesOnly: true);

    }

    private function calendarRecordingAction(bool $datesOnly = false): Action
    {

        return Action::make($datesOnly ? 'addOperationalDates' : 'recordCalendarPackage')

            ->label(fn (): string => $datesOnly ? 'Update activity dates' : ($this->activeCalendarPackage() ? 'Change teaching calendar' : 'Record teaching calendar'))

            ->modalHeading(fn (): string => $datesOnly ? 'Record approved activity dates' : ($this->activeCalendarPackage() ? 'Change teaching calendar' : 'Record teaching calendar'))

            ->color(fn (): string => $this->activeCalendarPackage() ? 'gray' : 'primary')

            ->modalDescription($datesOnly ? 'The current teaching dates, hours and exceptions are retained. Record the approved activity dates and their source, then review and activate the new Draft.' : 'Record the approved teaching dates and hours. Review and activation are separate; the current calendar remains in effect until then.')

            ->modalSubmitActionLabel('Record approved Draft')

            ->modalWidth('5xl')

            ->fillForm(fn (): array => $this->activeCalendarPackage() ? $this->calendarPackageFormData($this->activeCalendarPackage()) : ['source_package_id' => null, 'source_token' => null, 'windows' => [], 'dated_exceptions' => []])

            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->termId !== null)

            ->schema([

                Hidden::make('source_package_id'),

                Hidden::make('source_token'),

                ...($datesOnly ? $this->operationalDateFields() : $this->calendarPackageFields()),

            ])

            ->when(! $datesOnly, fn (Action $action): Action => $action->steps($this->calendarSteps()))

            ->action(function (array $data, Schema $schema) use ($datesOnly): void {

                $actor = auth()->user();

                abort_unless($actor instanceof User, 403);

                Gate::forUser($actor)->authorize('update', $this->selectedTerm());

                DB::transaction(function () use ($data, $actor, $datesOnly, $schema): void {

                    $term = Term::query()->whereKey($this->selectedTerm()->id)->lockForUpdate()->firstOrFail();

                    $source = TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)->first();

                    if ((int) ($source?->id ?? 0) !== (int) ($data['source_package_id'] ?? 0)

                        || ($source && ! hash_equals($source->concurrencyToken(), (string) ($data['source_token'] ?? '')))) {

                        Notification::make()->title('Calendar source changed')->body('Reopen this form to review the current Active version before saving.')->danger()->persistent()->send();

                        throw ValidationException::withMessages([$schema->getStatePath().'.authority_reference' => 'The Active calendar source changed. Reopen this form to review the current version.']);
                    }

                    if ($datesOnly) {

                        abort_unless($source instanceof TermCalendarPackage, 422);

                        $data = [...$this->calendarPackageFormData($source), ...collect($data)->only(['authority_reference', 'authority_date', 'windows'])->all()];

                    }

                    $data['faculty_availability_due_at'] = $source?->faculty_availability_due_at;

                    $version = ((int) TermCalendarPackage::query()->where('term_id', $term->id)->max('version')) + 1;

                    $package = TermCalendarPackage::query()->create([

                        ...collect($data)->except(['source_package_id', 'source_token', 'windows', 'teaching_grid_rows', 'dated_exceptions'])->all(),

                        'term_id' => $term->id,

                        'version' => $version,

                        'state' => TermCalendarPackage::StateDraft,

                        'recorded_by' => $actor->id,

                    ]);

                    foreach ($data['windows'] ?? [] as $window) {

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

            ->modalSubmitActionLabel('Save Draft correction')

            ->modalWidth('5xl')

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

                        'cutoff_at' => $w->cutoff_at,

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

                            'cutoff_at' => $w->cutoff_at,

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

                ...$this->calendarPackageFields(),

            ])

            ->action(function (array $data): void {

                $actor = auth()->user();

                abort_unless($actor instanceof User, 403);

                $term = $this->selectedTerm();

                Gate::forUser($actor)->authorize('update', $term);

                DB::transaction(function () use ($data, $term, $actor): void {

                    Term::query()->whereKey($term)->lockForUpdate()->firstOrFail();

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

                    $data['faculty_availability_due_at'] = $package->faculty_availability_due_at;

                    $package->update([

                        ...collect($data)->except(['package_id', 'concurrency_token', 'original_updated_at', 'windows', 'teaching_grid_rows', 'dated_exceptions'])->all(),

                        'recorded_by' => $actor->id,

                    ]);

                    $package->windows()->delete();

                    foreach ($data['windows'] ?? [] as $window) {

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

            ->label('Activate teaching calendar')

            ->disabled(function (): bool {

                $drafts = TermCalendarPackage::query()->where('term_id', $this->termId)->where('state', TermCalendarPackage::StateDraft)->with(['windows', 'teachingGridRows', 'datedExceptions'])->get();

                $readiness = app(TermCalendarPackageReadinessService::class);

                return ! $drafts->contains(fn (TermCalendarPackage $draft): bool => $readiness->for($draft)['ready']);

            })

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

            ->modalHeading('Activate teaching calendar')

            ->modalDescription('Review the selected exact Term, package authority, readiness, and downstream operational windows before activating this package.')

            ->modalSubmitActionLabel('Activate teaching calendar')

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

                        'concurrency_token' => $drafts->first()->concurrencyToken(),

                    ];

                }

                return [

                    'package_id' => null,

                ];

            })

            ->schema([

                Hidden::make('concurrency_token'),

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

                    ->afterStateUpdated(function (Page $livewire, Set $set, ?string $state): void {

                        $livewire->dispatch('close-notification', id: 'calendar_package_activation_feedback');

                        $package = TermCalendarPackage::query()->where('term_id', $this->termId)->find($state);

                        $set('concurrency_token', $package?->concurrencyToken());

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

                    app(ActivateTermCalendarPackage::class)->execute($package, $actor, (string) ($data['concurrency_token'] ?? ''));

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

    /** @return list<Component> */
    private function operationalDateFields(): array
    {

        return [

            TextInput::make('authority_reference')->label('Approval reference (optional)')->maxLength(255),

            DatePicker::make('authority_date')->label('Approval date (optional)'),

            Repeater::make('windows')->label('Approved activity dates')->defaultItems(0)->addActionLabel('Add an activity')

                ->itemLabel(fn (array $state): string => TermCalendarWindow::typeOptions()[$state['window_type'] ?? ''] ?? 'Activity')

                ->schema([

                    Select::make('window_type')->label('Activity')->options(TermCalendarWindow::typeOptions())->required()->distinct(),

                    DatePicker::make('opens_on')->label('Opens on')->required(),

                    DatePicker::make('closes_on')->label('Closes on')->required()->afterOrEqual('opens_on'),

                    TimePicker::make('cutoff_at')->label('Closing time, if approved (Asia/Manila)')->timezone('UTC')->seconds(false),

                ])->columns(2)->reorderable(false)->helperText('Leave the closing time empty to include the entire closing day. Missing dates block only their related action.'),

        ];

    }

    /** @return list<Step> */
    private function calendarSteps(): array
    {
        $steps = [];
        foreach ($this->calendarPackageFields() as $index => $section) {
            $heading = (string) $section->getHeading();
            $steps[] = Step::make($heading)->schema([
                ...($index === 0 ? [Hidden::make('source_package_id'), Hidden::make('source_token')] : []),
                $section->heading(null)->collapsible(false),
            ]);
        }

        return $steps;
    }

    /** @return list<SchemaSection> */
    private function calendarPackageFields(bool $addingDates = false): array
    {

        $approval = SchemaSection::make('Teaching dates')->description('Record the approved dates. TALA records who saves and activates the calendar, and when.')

            ->collapsible()->collapsed($addingDates)

            ->schema([

                TextInput::make('authority_reference')->label('Approval reference (optional)')->maxLength(255),

                DatePicker::make('authority_date')->label('Approval date (optional)'),

                DatePicker::make('administrative_starts_on')->label('Term start')->required(),

                DatePicker::make('administrative_ends_on')->label('Term end')->required()->afterOrEqual('administrative_starts_on'),

                DatePicker::make('classes_start_on')->label('Classes start')->required(),

                DatePicker::make('classes_end_on')->label('Classes end')->required()->afterOrEqual('classes_start_on'),

                TextInput::make('special_term_schedule_basis')->label('Special Term class-day and class-hour basis')->maxLength(255)

                    ->visible(fn (): bool => $this->selectedTerm()->type === Term::TypeSpecialTerm)->columnSpanFull(),

            ])->columns(2);

        $grid = SchemaSection::make('Teaching hours')->description('Record each approved weekday and its own hours in Asia/Manila. Scheduling uses fixed 30-minute slots; days, hours and breaks come from the approved source.')

            ->collapsible()->collapsed($addingDates)

            ->schema([

                Repeater::make('teaching_grid_rows')->label('Approved teaching days')->schema([

                    Select::make('day_of_week')->options(SectionMeeting::dayOptions())->required()->distinct(),

                    TimePicker::make('starts_at')->timezone('UTC')->seconds(false)->required(),

                    TimePicker::make('ends_at')->timezone('UTC')->seconds(false)->required()->after('starts_at'),

                    Repeater::make('breaks')->label('Recurring institutional breaks')->defaultItems(0)->schema([

                        TimePicker::make('starts_at')->timezone('UTC')->seconds(false)->required(),

                        TimePicker::make('ends_at')->timezone('UTC')->seconds(false)->required()->after('starts_at'),

                    ])->columns(2)->columnSpanFull(),

                ])->minItems(1)->required()->reorderable(false)->columns(3),

            ]);

        $dates = SchemaSection::make('Activity dates')->description('Add only approved dates. Missing dates block their related action; the core calendar can activate without them.')

            ->collapsible()->collapsed(! $addingDates)->schema([

                Repeater::make('windows')->label('Approved activity dates')->defaultItems(0)->schema([

                    Select::make('window_type')->options(TermCalendarWindow::typeOptions())->required()->distinct(),

                    DatePicker::make('opens_on')->required(),

                    DatePicker::make('closes_on')->required()->afterOrEqual('opens_on'),

                    TimePicker::make('cutoff_at')->label('Approved close cutoff (Asia/Manila)')->timezone('UTC')->seconds(),

                ])->columns(2)->helperText('Record each activity for this Term. Leave the closing time empty to include the entire closing day.'),

                Hidden::make('faculty_availability_due_at')->dehydrated(false),

            ]);

        $exceptions = SchemaSection::make('Calendar exceptions')->description('Record approved no-class periods, make-up dates or informational exceptions, with their instructional effect and authority.')

            ->collapsible()->collapsed()->schema([

                Repeater::make('dated_exceptions')->hiddenLabel()->defaultItems(0)->schema([

                    DatePicker::make('starts_on')->required(),

                    DatePicker::make('ends_on')->required()->afterOrEqual('starts_on'),

                    TextInput::make('exception_type')->required()->maxLength(64),

                    TextInput::make('label')->required()->maxLength(255),

                    TextInput::make('authority_reference')->required()->maxLength(255),

                    Toggle::make('blocks_teaching')->label('No classes')->default(true),

                ])->columns(2),

            ]);

        return $addingDates ? [$dates, $approval, $grid, $exceptions] : [$approval, $grid, $dates, $exceptions];

    }

    public function recordFacultyDeadlineAction(): Action
    {

        return Action::make('recordFacultyDeadline')

            ->label(fn (): string => $this->activeCalendarPackage()?->facultyAvailabilityDueAt() ? 'Change reply deadline' : 'Set reply deadline')

            ->modalSubmitActionLabel('Save reply deadline')

            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->activeCalendarPackage() !== null)

            ->modalDescription('Set when Faculty must respond. This does not email anyone. Corrections retain their source and history.')

            ->fillForm(function (): array {

                $prior = FacultyAvailabilityDeadline::query()->where('term_id', $this->termId)->latest('id')->first();

                return ['expected_id' => $prior?->id ?? 0, 'due_at' => $this->activeCalendarPackage()?->facultyAvailabilityDueAt()?->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i:s'), 'authority_reference' => $prior?->authority_reference];

            })

            ->schema([

                Hidden::make('expected_id')->required(),

                DateTimePicker::make('due_at')->label('Reply by (Asia/Manila)')->timezone('Asia/Manila')->seconds(false)->required(),

                TextInput::make('authority_reference')->label('Approval reference (optional)')->helperText('Add a reference if one was provided. TALA records who set this deadline and when.')->maxLength(255),

                Textarea::make('correction_reason')->label('Reason for correction')->helperText('Required when a deadline is already recorded.')->maxLength(2000),

            ])

            ->action(function (array $data): void {

                $actor = auth()->user();

                abort_unless($actor instanceof User, 403);

                try {

                    app(RecordFacultyAvailabilityDeadline::class)->execute($this->selectedTerm(), $actor, $data['due_at'], $data['authority_reference'] ?? null, $data['correction_reason'] ?? null, (int) $data['expected_id']);

                } catch (ValidationException $exception) {

                    Notification::make()->title('Cannot record Faculty deadline')->body(collect($exception->errors())->flatten()->unique()->implode(' '))->danger()->persistent()->send();

                    throw $exception;
                }

                Notification::make()->title('Faculty deadline recorded')->body('Teaching Resources, My Availability and new request records use this source.')->success()->send();

            });

    }

    /** @return array<string, mixed> */
    private function calendarPackageFormData(TermCalendarPackage $package): array
    {

        $package->loadMissing(['windows', 'teachingGridRows', 'datedExceptions']);

        return [

            ...$package->only(['authority_reference', 'special_term_schedule_basis']),

            'source_package_id' => $package->id,

            'source_token' => $package->concurrencyToken(),

            'administrative_starts_on' => $package->administrative_starts_on->toDateString(),

            'administrative_ends_on' => $package->administrative_ends_on->toDateString(),

            'classes_start_on' => $package->classes_start_on->toDateString(),

            'classes_end_on' => $package->classes_end_on->toDateString(),

            'authority_date' => $package->authority_date?->toDateString(),

            'faculty_availability_due_at' => $package->faculty_availability_due_at?->timezone('Asia/Manila')->format('Y-m-d H:i'),

            'windows' => $package->windows->map(fn ($window): array => [

                'window_type' => $window->window_type, 'opens_on' => $window->opens_on->toDateString(), 'closes_on' => $window->closes_on->toDateString(), 'cutoff_at' => $window->cutoff_at,

            ])->all(),

            'teaching_grid_rows' => $package->teachingGridRows->map(fn ($row): array => $row->only(['day_of_week', 'starts_at', 'ends_at', 'breaks']))->all(),

            'dated_exceptions' => $package->datedExceptions->map(fn ($exception): array => [

                ...$exception->only(['exception_type', 'label', 'authority_reference', 'blocks_teaching']),

                'starts_on' => $exception->starts_on->toDateString(), 'ends_on' => $exception->ends_on->toDateString(),

            ])->all(),

        ];

    }

    public function requestFacultyAvailabilityAction(): Action
    {

        return Action::make('requestFacultyAvailability')

            ->label('Request availability')

            ->icon(Heroicon::OutlinedEnvelope)

            ->modalHeading('Request Faculty availability')

            ->modalSubmitActionLabel('Send availability requests')

            ->disabled(fn (): bool => $this->activeCalendarPackage()?->facultyAvailabilityDueAt() === null)

            ->modalDescription(fn (): string => 'Reply by '.($this->activeCalendarPackage()?->facultyAvailabilityDueAt()?->copy()->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'deadline not recorded').' (Asia/Manila). Email selected Faculty a link to submit availability. Only active Faculty who have not responded are listed.')

            ->fillForm(fn (): array => [

                'package_id' => $this->activeCalendarPackage()?->id,

                'package_token' => $this->activeCalendarPackage()?->concurrencyToken(),

                'deadline_id' => FacultyAvailabilityDeadline::query()->where('term_id', $this->termId)->latest('id')->value('id') ?? 0,

            ])

            ->schema([

                Hidden::make('package_id')->required(),

                Hidden::make('package_token')->required(),

                Hidden::make('deadline_id')->required(),

                Select::make('faculty_user_ids')

                    ->label('Recipients')

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

                $package = TermCalendarPackage::query()->where('term_id', $this->termId)->find($data['package_id']);

                abort_unless($package instanceof TermCalendarPackage, 409);

                try {

                    $events = app(FacultyAvailabilityRequestService::class)->request(

                        $package,

                        $data['faculty_user_ids'] ?? [],

                        $actor,

                        (string) $data['package_token'],

                        (int) $data['deadline_id'],

                    );

                } catch (ValidationException $exception) {

                    Notification::make()->title('Cannot request Faculty availability')->body(collect($exception->errors())->flatten()->unique()->implode(' '))->danger()->persistent()->send();

                    throw $exception;
                }

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

            ->label('Confirm class')
            ->modalHeading('Confirm class and student sections')
            ->modalDescription('Link the confirmed student sections to this class. Shared and mixed-year sections remain separate; their total must fit the approved class capacity.')
            ->modalSubmitActionLabel('Confirm class')
            ->fillForm(fn (array $arguments, ?Section $record = null): array => ['section_id' => $record?->id ?? $arguments['sectionId'] ?? null])
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->termId !== null)
            ->schema(fn (array $arguments, ?Section $record = null): array => [
                Select::make('section_id')->label('Class')->disabled($record !== null || isset($arguments['sectionId']))->dehydrated()
                    ->options(Section::query()->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $this->selectedTerm()->id))->whereNull('confirmed_at')->orderBy('code')->pluck('code', 'id'))->required()->searchable(),
                Repeater::make('cohorts')->label('Student sections')->schema([
                    Select::make('term_cohort_id')->label('Student section')->options(TermCohort::query()->where('term_id', $this->selectedTerm()->id)->orderBy('reference')->pluck('reference', 'id'))->required()->searchable()->distinct(),
                    TextInput::make('expected_count')->label('Learners in this class')->numeric()->integer()->minValue(1)->required(),
                ])->minItems(1)->required()->columns(2),
                TextInput::make('additional_authority_reference')->label('Additional class approval, when applicable')->maxLength(255),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $counts = collect($data['cohorts'])->mapWithKeys(fn (array $row): array => [(int) $row['term_cohort_id'] => (int) $row['expected_count']])->all();
                app(ConfirmClassOffering::class)->execute(
                    Section::query()->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $this->termId))->findOrFail((int) $data['section_id']),
                    $actor, $counts, $data['additional_authority_reference'] ?? null,
                );
                Notification::make()->title('Class confirmed')->success()->send();
            });
    }

    /** @return array{ready: bool, reason: ?string, tab?: string} */
    public function generationReadiness(): array
    {

        $term = $this->selectedTerm();

        if (! $term) {

            return ['ready' => false, 'reason' => 'Choose a Term first.'];

        }

        $readiness = app(ReadyTermPlanningProjection::class)->forTerm($term);

        if (! $readiness['ready']) {

            $blocker = $readiness['blockers'][0];

            return ['ready' => false, 'reason' => match ($blocker['code']) {
                'calendar_not_active' => 'Activate the teaching calendar for this Term before preparing a timetable.',
                'classes_not_confirmed' => 'Prepare and confirm classes for this Term before generating a timetable.',
                default => $blocker['reason'],
            }, 'tab' => match ($blocker['code']) {
                'calendar_not_active' => 'overview',
                'classes_not_confirmed' => 'classes',
                default => 'resources',
            }];

        }

        $demands = SchedulingDemand::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id));

        if (! (clone $demands)->exists()) {

            return ['ready' => false, 'reason' => auth()->user()?->hasRole(User::StaffRoleAcademicHead)
                ? 'No weekly meeting requirements are recorded for this Term’s classes.'
                : 'Prepare weekly meeting requirements for this Term’s classes.', 'tab' => 'classes'];

        }

        if ((clone $demands)->where('validation_state', '!=', SchedulingDemand::ValidationReadyForReview)->exists()) {

            return ['ready' => false, 'reason' => auth()->user()?->hasRole(User::StaffRoleAcademicHead)
                ? 'Recorded weekly meeting requirements have unresolved validation.'
                : 'Resolve the recorded weekly meeting requirements before generating.', 'tab' => 'classes'];

        }

        if (ScheduleGenerationRun::query()->where('term_id', $term->id)->whereIn('status', [ScheduleGenerationRun::StatusQueued, ScheduleGenerationRun::StatusDispatching])->exists()) {

            return ['ready' => false, 'reason' => 'A timetable is already being generated for this Term.', 'tab' => 'generate'];

        }

        return ['ready' => true, 'reason' => null];

    }

    public function generateTimetableAction(): Action
    {

        return Action::make('generateTimetable')

            ->label('Generate whole-Term timetable')

            ->icon(Heroicon::OutlinedPaperAirplane)

            ->color('primary')

            ->requiresConfirmation()

            ->modalHeading('Generate whole-Term timetable')

            ->modalWidth('5xl')

            ->modalDescription(function (): string {

                $term = $this->termId ? Term::query()->find($this->termId) : null;

                $label = $term ? "{$term->label}" : 'the selected term';

                return "Create a draft for all recorded classes in {$label}. Review and publication follow separately.";

            })

            ->modalContent(fn () => view('filament.pages.partials.term-generation-scope', ['coverage' => $this->termId ? app(TermCoverageProjection::class)->forTerm(Term::findOrFail($this->termId)) : null]))

            ->disabled(fn (): bool => ! $this->generationReadiness()['ready'])

            ->tooltip(fn (): ?string => $this->generationReadiness()['reason'])

            ->modalSubmitActionLabel('Generate draft timetable')

            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->termId !== null && $this->retainedCandidateNextStep() === null && ! $this->canRecoverActiveRun())

            ->action(function (): void {

                $actor = auth()->user();

                abort_unless($actor instanceof User, 403);

                try {

                    $term = Term::query()->findOrFail($this->termId);

                    $run = app(ScheduleGenerationService::class)->generate($term, $actor);

                    $this->selectedRunId = $run->id;
                    if ($this instanceof TermTimetable) {
                        $this->timetableState = 'draft';
                        $this->viewFilter = null;
                        $this->programFilter = null;
                        $this->yearFilter = null;
                        $this->resetTable();
                    } else {
                        $this->redirect(TermTimetable::getUrl(['termId' => $term->id, 'selectedRunId' => $run->id]));
                    }

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

            ->label('Accept timetable')

            ->icon(Heroicon::OutlinedCheck)

            ->color('primary')

            ->requiresConfirmation()

            ->modalHeading('Accept timetable')

            ->modalSubmitActionLabel('Accept timetable')
            ->modalIcon(Heroicon::OutlinedCheck)

            ->modalDescription(fn (): string => $this->selectedTerm()->label.' · Attempt #'.$this->activeScheduleRun()?->id.'. Record why this timetable meets the academic requirements. It becomes official only after publication.')

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

            ->label('Reject result')

            ->icon(Heroicon::OutlinedXMark)

            ->color('danger')

            ->requiresConfirmation()

            ->modalHeading('Reject result')

            ->modalSubmitActionLabel('Reject result')
            ->modalIcon(Heroicon::OutlinedXMark)

            ->modalDescription(fn (): string => $this->selectedTerm()->label.' · Attempt #'.$this->activeScheduleRun()?->id.'. Record why this result is rejected. A rejected result cannot be published.')

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

            ->label(fn (): string => $this->canRecoverActiveRun() ? 'Try again after a service fix' : (data_get($this->activeScheduleRun()?->diagnostics, 'solver_result.solver_status') === 'unknown' ? 'Retry search' : 'Retry connection'))

            ->icon(Heroicon::OutlinedArrowPath)

            ->color('warning')

            ->requiresConfirmation()

            ->modalHeading(fn (): string => $this->canRecoverActiveRun() ? 'Try again after a service fix' : 'Retry generation')

            ->modalDescription(fn (): string => $this->selectedTerm()->label.' / Attempt #'.$this->activeScheduleRun()?->id.'. '.($this->canRecoverActiveRun()
                ? 'After the System Administrator confirms the service fix, queue one new attempt after current planning data passes readiness checks. The failed attempt stays in history. Any original repair change is preserved.'
                : 'Retry the same whole-Term request. Earlier outcomes remain in the history.'))

            ->modalSubmitActionLabel(fn (): string => $this->canRecoverActiveRun() ? 'Queue new attempt' : 'Retry generation')
            ->schema([
                Textarea::make('correction_explanation')->label('What was fixed?')
                    ->helperText('Record the service correction confirmed by the System Administrator. This explanation stays with the new attempt.')
                    ->required(fn (): bool => $this->canRecoverActiveRun())->maxLength(2000)
                    ->visible(fn (): bool => $this->canRecoverActiveRun()),
            ])

            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->canRetryActiveRun())

            ->action(function (array $data): void {

                $actor = auth()->user();

                abort_unless($actor instanceof User, 403);

                $run = $this->activeScheduleRun();

                abort_unless($run instanceof ScheduleGenerationRun, 404);

                try {

                    $retried = $this->canRecoverActiveRun()
                        ? app(ScheduleSolverRetryService::class)->recover($run, $actor, (string) ($data['correction_explanation'] ?? ''))
                        : app(ScheduleSolverRetryService::class)->retry($run, $actor);

                    $this->selectedRunId = $retried->id;

                    Notification::make()

                        ->title($retried->id !== $run->id ? 'New attempt queued' : 'Solver run requeued')

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

            ->label('Publish official timetable')

            ->icon(Heroicon::OutlinedCheckCircle)

            ->color('success')

            ->requiresConfirmation()

            ->modalHeading('Publish official timetable')

            ->modalDescription(fn (): string => $this->selectedTerm()->label.' / Attempt #'.$this->activeScheduleRun()?->id.'. '.$this->publicationModalDescription())

            ->modalSubmitActionLabel('Publish official timetable')

            ->schema([

                TextInput::make('authority_reference')

                    ->label('Approval reference (optional)')

                    ->maxLength(255)

                    ->helperText('TALA records the publishing Registrar and time automatically.'),

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

    public function retainedCandidateNextStep(): ?string
    {
        $run = $this->activeScheduleRun();

        if (! $run instanceof ScheduleGenerationRun || $run->status !== ScheduleGenerationRun::StatusUnderReview) {
            return null;
        }

        return match ($run->candidate_state) {
            'Accepted' => 'Publish the accepted timetable before starting another attempt.',
            'Rejected', 'Stale', 'Superseded' => null,
            default => 'Review or reject this retained candidate before starting another attempt.',
        };
    }

    public function canRetryActiveRun(): bool
    {

        $actor = auth()->user();

        $run = $this->activeScheduleRun();

        return $actor instanceof User

            && $run instanceof ScheduleGenerationRun

            && (Gate::forUser($actor)->allows('retry', $run) || Gate::forUser($actor)->allows('recover', $run));

    }

    public function canRecoverActiveRun(): bool
    {
        $actor = auth()->user();
        $run = $this->activeScheduleRun();

        return $actor instanceof User && $run instanceof ScheduleGenerationRun
            && ! $run->canRetrySolver() && Gate::forUser($actor)->allows('recover', $run);
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

    public function termSelection(Schema $schema): Schema
    {

        return $schema->components([

            Select::make('termId')

                ->label('Academic Year and Term')

                ->hint(fn (): ?string => $this->termId !== null && ($term = $this->selectedTerm()) ? (Term::stateOptions()[$term->state] ?? $term->state).(auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? ' · Read-only · Registrar owns these records' : '') : null)

                ->extraInputAttributes(['aria-label' => 'Academic Year and Term'])

                ->placeholder('Choose a Term')

                ->selectablePlaceholder(false)

                ->options(fn (): array => Term::query()->with('academicYear')->latest('starts_on')->get()

                    ->mapWithKeys(fn (Term $term): array => [$term->id => implode(' · ', array_unique(array_filter([
                        $term->academicYear?->label,
                        Term::typeOptions()[$term->type] ?? $term->type,
                        $term->label,
                    ])))])->all())

                ->searchable()
                ->live()
                ->afterStateUpdated(fn ($state) => $this->selectTerm((int) $state)),

        ]);

    }

    public function selectTerm(int $termId): void
    {

        abort_unless(Term::query()->whereKey($termId)->exists(), 404);

        $this->termId = $termId;

        $this->chooseTerm = false;
        $this->termAutoSelected = false;

        $this->viewTab = static::$planningTab;

        $this->selectedRunId = null;

        $this->selectedVersionId = null;

        $this->resetCandidateFilters();

        if (static::class !== self::class) {

            $this->redirect(static::getUrl(['termId' => $termId]));

        }

    }

    public function showTab(string $tab): void
    {

        abort_unless(in_array($tab, self::Tabs, true), 404);

        $this->viewTab = $tab;

    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {

        $terms = Term::query()->with('academicYear')->withCount(['termOfferings' => fn ($query) => $query->where('state', '!=', TermOffering::StateCancelled)->whereHas('sections', fn ($sections) => $sections->whereNotIn('state', [Section::StateCancelled, Section::StateClosed]))])->latest('starts_on')->get();

        $term = $terms->firstWhere('id', $this->termId);

        $activePackage = $term instanceof Term

            ? TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)->with(['teachingGridRows', 'windows', 'datedExceptions'])->first()

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

        $calendarHistory = $term instanceof Term ? $term->calendarPackages()->with(['windows', 'teachingGridRows', 'datedExceptions'])->latest('version')->get() : collect();

        $overviewPackage = $draftPackages->last()['package'] ?? $activePackage ?? $calendarHistory->first();

        $contextClasses = $term ? Section::query()->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $term->id))

            ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])

            ->with(['calendarPackage.teachingGridRows', 'calendarPackage.datedExceptions'])->get()

            ->filter(fn ($section): bool => $activePackage && $section->calendarPackage->isActivatedCoreSource() && $section->calendarPackage->coreSignature() === $activePackage->coreSignature()) : collect();

        $facultyDeadlineSource = FacultyAvailabilityDeadline::query()->where('term_id', $term?->id)->latest('id')->first();

        $operationalDates = collect(TermCalendarWindow::typeOptions())->map(function (string $label, string $type) use ($activePackage): array {

            $window = $activePackage?->windows->firstWhere('window_type', $type);

            $bounds = $window ? app(CalendarPhaseGateService::class)->windowBounds($window) : null;

            $at = CarbonImmutable::now('Asia/Manila');

            return ['type' => $type, 'label' => $label, 'window' => $window, 'status' => ! $bounds ? 'Dates not configured' : ($at->lt($bounds['opens_at']) ? 'Upcoming' : ($at->gt($bounds['closes_at']) ? 'Closed' : 'Open'))];

        })->values();

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

            $solverOutcome = (array) ($diagnostics['solver_outcome'] ?? $diagnostics['solver_result'] ?? []);

            $solverDispatch = (array) ($diagnostics['solver_dispatch'] ?? []);

            $solverFailure = $activeRun->finalSolverFailure();

            $qualityMeasures = is_array($activeRun->quality_measures) ? $activeRun->quality_measures : [];

            $publicationSummary = $activeRun->publicationSummary();

            $allCandidateRows = $activeRun->candidateRows()

                ->with([

                    'faculty',

                    'room',

                    'schedulingDemand.courseComponent.courseSpecification.course',

                    'schedulingDemand.sectionDeliveryGroup.section.cohorts.program',
                    'schedulingDemand.sectionDeliveryGroup.section.cohorts.curriculumVersion.entries.courseSpecification',

                    'schedulingDemand.termOffering.curriculumEntry.curriculumVersion.program',

                    'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',

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

                    return $modality ? [(string) $modality => TermOffering::modalityOptions()[$modality] ?? (string) $modality] : [];

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

                    'classOffering.courseRevision.course',

                    'classOffering.cohorts.program',
                    'classOffering.cohorts.curriculumVersion.entries.courseSpecification',

                    'faculty',

                    'room',

                    'schedulingDemand.courseComponent.courseSpecification.course',

                    'schedulingDemand.sectionDeliveryGroup.section.cohorts.program',
                    'schedulingDemand.sectionDeliveryGroup.section.cohorts.curriculumVersion.entries.courseSpecification',

                    'schedulingDemand.termOffering.curriculumEntry.curriculumVersion.program',

                    'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',

                ])

                ->orderBy('day_of_week')

                ->orderBy('starts_at')

                ->get()

            : collect();

        $offerings = $term ? TermOffering::query()->where('term_id', $term->id)

            ->where('state', '!=', TermOffering::StateCancelled)

            ->with(['curriculumEntry.courseSpecification.course', 'sections'])

            ->get() : collect();

        $sourceClasses = $term ? Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))

            ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])

            ->with(['termOffering.curriculumEntry.courseSpecification.course', 'courseRevision.course', 'cohorts', 'deliveryGroups.schedulingDemands', 'calendarPackage.teachingGridRows', 'calendarPackage.datedExceptions'])

            ->orderBy('code')->get() : collect();

        $cohorts = $term ? TermCohort::query()->where('term_id', $term->id)->orderBy('reference')->get() : collect();

        $demands = $term ? SchedulingDemand::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))

            ->with(['courseComponent.courseSpecification.course', 'sectionDeliveryGroup.section'])->get() : collect();

        $courseIds = $offerings->map(fn (TermOffering $offering) => $offering->course()?->id)->filter()->unique();

        $courseTitles = $offerings->groupBy(fn (TermOffering $offering): ?int => $offering->course()?->id)
            ->map(fn (Collection $courseOfferings): string => $courseOfferings->pluck('curriculumEntry.courseSpecification.title')->filter()->unique()->implode(' / '));

        $qualifications = FacultyQualification::query()->active()->whereIn('course_id', $courseIds)->with(['faculty', 'course'])->get();

        $declarations = $term ? FacultyAvailabilityDeclaration::query()->where('term_id', $term->id)->latest('version')->get()->unique('faculty_user_id')->keyBy('faculty_user_id') : collect();

        $facultyRecords = $qualifications->groupBy('faculty_user_id')->map(fn ($qualifications, $facultyId): array => [

            'faculty' => $qualifications->first()->faculty,

            'courses' => $qualifications->pluck('course.code')->unique()->values()->all(),

            'course_labels' => $qualifications->map(fn (FacultyQualification $qualification): string => ($courseTitles->get($qualification->course_id) ? $courseTitles->get($qualification->course_id).' ('.$qualification->course->code.')' : $qualification->course->code))->unique()->values()->all(),

            'declaration' => $declarations->get($facultyId),

        ])->filter(fn (array $row): bool => $row['faculty'] !== null)->values();

        $rooms = Room::query()->where('is_active', true)->orderBy('code')->get();

        $needsAttention = [];

        if (! $activePackage || $draftPackages->isNotEmpty()) {

            $needsAttention[] = ['tab' => 'overview', 'title' => $draftPackages->isNotEmpty() ? 'Review the pending calendar' : 'Record the approved teaching calendar', 'detail' => 'Instruction dates and teaching hours establish the source for class planning.'];

        }

        if ($offerings->isEmpty() || $sourceClasses->isEmpty() || $contextClasses->whereNotNull('confirmed_at')->count() < $sourceClasses->count()) {

            $needsAttention[] = ['tab' => 'classes', 'title' => $sourceClasses->isEmpty() ? 'Choose courses and create classes' : 'Complete class preparation', 'detail' => 'Each class needs its approved course facts and confirmed learners.'];

        }

        $missingQualifications = $courseIds->diff($qualifications->pluck('course_id')->unique())->count();

        $missingDeclarations = $facultyRecords->filter(fn (array $row): bool => $row['declaration'] === null)->count();

        if ($missingQualifications > 0 || $missingDeclarations > 0 || ($sourceClasses->contains(fn (Section $class): bool => $class->termOffering->modality === TermOffering::ModalityFaceToFace) && $rooms->isEmpty())) {

            $needsAttention[] = ['tab' => 'resources', 'title' => 'Review teaching resources', 'detail' => $missingQualifications.' course(s) without an eligible Faculty record; '.$missingDeclarations.' Faculty response(s) not recorded.'];

        }

        if ($demands->isEmpty() && $sourceClasses->isNotEmpty()) {

            $needsAttention[] = ['tab' => 'classes', 'title' => 'Prepare the meeting requirements', 'detail' => 'Requirements connect each class to the meetings that generation must place.'];

        }

        $nextTask = $needsAttention[0] ?? ['tab' => $activeRun ? 'generate' : 'generate', 'title' => $activeRun ? 'Review the latest timetable result' : 'Generate a timetable candidate', 'detail' => 'Generation validates the captured inputs. Review and publication are separate decisions.'];

        $selectedVersion?->loadMissing('scheduleRun');

        return [

            'offerings' => $offerings,

            'sourceClasses' => $sourceClasses,

            'contextClasses' => $contextClasses,

            'cohorts' => $cohorts,

            'demands' => $demands,

            'facultyRecords' => $facultyRecords,

            'rooms' => $rooms,

            'missingQualifications' => $missingQualifications,

            'missingDeclarations' => $missingDeclarations,

            'needsAttention' => $needsAttention,

            'nextTask' => $nextTask,

            'candidatePresentation' => $activeRun ? TimetablePresentation::forCandidate($activeRun, $allCandidateRows) : null,

            'publishedPresentation' => $selectedVersion ? TimetablePresentation::forPublished($selectedVersion, $publishedMeetings) : null,

            'terms' => $terms,

            'term' => $term,

            'activePackage' => $activePackage,

            'draftPackages' => $draftPackages,

            'overviewPackage' => $overviewPackage,

            'coreReadiness' => $overviewPackage ? $readinessService->for($overviewPackage) : null,

            'calendarHistory' => $calendarHistory,

            'calendarRecorders' => User::query()->whereIn('id', $calendarHistory->pluck('recorded_by')->filter()->unique())->pluck('name', 'id'),

            'operationalDates' => $operationalDates,

            'facultyDeadline' => $facultyDeadlineSource?->due_at ?? $activePackage?->faculty_availability_due_at,

            'facultyDeadlineSource' => $facultyDeadlineSource,

            'currentVersion' => $currentVersion,

            'versions' => $versions,

            'selectedVersion' => $selectedVersion,

            'publishedMeetings' => $publishedMeetings,

            'readiness' => $term instanceof Term ? app(ReadyTermPlanningProjection::class)->forTerm($term) : null,

            'counts' => $term instanceof Term ? [

                'classes' => $contextClasses->count(),

                'confirmed' => $contextClasses->whereNotNull('confirmed_at')->count(),

                'runs' => ScheduleGenerationRun::query()->where('term_id', $term->id)->count(),

            ] : ['classes' => 0, 'confirmed' => 0, 'runs' => 0],

            'tabs' => [

                'plan' => 'Plan',

                'overview' => 'Calendar',

                'classes' => 'Classes',

                'resources' => 'Teaching Resources',

                'generate' => 'Generate & Review',

                'published' => 'Published Timetable',

            ],

            'taskSummaries' => [

                'plan' => 'Preparation and next work',

                'overview' => $draftPackages->isNotEmpty() ? 'Draft v'.$overviewPackage->version.' to review' : ($activePackage ? 'Active calendar v'.$activePackage->version : 'Start with approved dates'),

                'classes' => $contextClasses->whereNotNull('confirmed_at')->count().' / '.$contextClasses->count().' confirmed',

                'resources' => $facultyRecords->count().' eligible Faculty · '.$missingDeclarations.' awaiting response',

                'generate' => $activeRun ? (ScheduleGenerationRun::statusOptions()[$activeRun->status] ?? str($activeRun->status)->headline()->toString()) : 'No generation yet',

                'published' => $currentVersion ? 'Current version '.$currentVersion->version : 'Not published',

            ],

            'destinations' => [

                'overview' => $term ? TermResource::getUrl('view', ['record' => $term, 'viewTab' => $this->viewTab]) : TermResource::getUrl(),

                'classes' => SectionResource::getUrl('index', ['termId' => $term?->id]),

                'resources' => RoomResource::getUrl('index', ['termId' => $term?->id]),

                'generate' => ScheduleGenerationRunResource::getUrl('index', ['termId' => $term?->id, 'filters' => ['term_id' => ['value' => $term?->id]]]),

                'published' => SectionMeetingResource::getUrl('index', ['termId' => $term?->id, 'filters' => ['term_id' => ['value' => $term?->id]]]),

            ],

            'classSources' => collect([

                ['resource' => TermOfferingResource::class, 'label' => 'Term offerings', 'description' => 'Choose curriculum courses before creating sections.', 'filter' => 'term'],

                ['resource' => SectionResource::class, 'label' => 'Sections & delivery groups', 'description' => 'Create section records and set their delivery groups.', 'filter' => null],

                ['resource' => SchedulingDemandResource::class, 'label' => 'Schedule requirements', 'description' => 'Review the requirements and blockers used by generation.', 'filter' => 'term_id'],

            ])->filter(fn (array $source): bool => $source['resource']::canAccess())->map(fn (array $source): array => [

                ...$source,

                'url' => $source['resource']::getUrl('index', ['termId' => $term?->id, ...($source['filter'] ? ['filters' => [$source['filter'] => ['value' => $term?->id]]] : [])]),

            ]),

            'resourceSources' => collect([

                ['resource' => RoomResource::class, 'label' => 'Rooms & features', 'description' => 'Maintain room capacity, equipment and availability.', 'filter' => null],

                ['resource' => FacultyQualificationResource::class, 'label' => 'Faculty qualifications', 'description' => 'Record which courses each Faculty member may teach.', 'filter' => null],

                ['resource' => FacultyTermLoadOverrideResource::class, 'label' => 'Faculty Term loads', 'description' => 'Record approved load exceptions for this Term.', 'filter' => 'term_id'],

                ['resource' => CalendarEventResource::class, 'label' => 'Scheduling availability', 'description' => 'Review recurring Faculty, room and institutional blocks.', 'filter' => 'term_id'],

            ])->filter(fn (array $source): bool => $source['resource']::canAccess())->map(fn (array $source): array => [

                ...$source,

                'url' => $source['resource']::getUrl('index', ['termId' => $term?->id, ...($source['filter'] ? ['filters' => [$source['filter'] => ['value' => $term?->id]]] : [])]),

            ]),

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

        ];

    }

    protected function selectedTerm(): Term
    {

        abort_if($this->termId === null, 404);

        return Term::query()->findOrFail($this->termId);

    }

    protected function activeCalendarPackage(): ?TermCalendarPackage
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
