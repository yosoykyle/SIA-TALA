<?php

namespace App\Filament\Pages;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\TermCalendarPackageReadinessService;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\FacultyAvailabilityRequestService;
use App\Actions\Scheduling\FacultyAvailabilityStatusProjection;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeadline;
use App\Actions\Scheduling\ReviewTimetableCandidate;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\SchedulePublicationImpactService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleSolverRetryService;
use App\Actions\Scheduling\TermCoverageProjection;
use App\Actions\Scheduling\TimetableFailureLanguage;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Models\AcademicYear;
use App\Models\AdmissionCycle;
use App\Models\CandidateScheduleRow;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\FacultyAvailabilityDeadline;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\FeePlan;
use App\Models\GradeRoster;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingCommitment;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TimetableRevision;
use App\Models\User;
use App\Support\HasClassPreparationActions;
use App\Support\TimetablePresentation;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
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
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
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

    /** @var array<string, string> */
    protected array $extraBodyAttributes = ['class' => 'tala-term-planning-page'];

    #[Url]
    #[Locked]
    public ?int $termId = null;

    public bool $termAutoSelected = false;

    public ?string $termAutoSelectBasis = null;

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

    /** @var array{title: string, explanation: string, nextStep: string, technical: string}|null */
    public ?array $generationFailure = null;

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
        if ($this->termId !== null) {
            abort_unless(Term::query()->whereKey($this->termId)->exists(), 404);
        }

        if (static::class === TermOverview::class) {
            if ($this->termId !== null) {
                $this->redirect(TermCalendar::getUrl(['termId' => $this->termId]));
                $this->skipRender();
            }

            return;
        }

        if ($this->termId === null) {
            [$resolvedId, $basis] = $this->resolveDefaultPlanningTermId();
            if ($resolvedId !== null) {
                $this->termId = $resolvedId;
                $this->termAutoSelected = $basis !== null;
                $this->termAutoSelectBasis = $basis;
            }
        }

        \Filament\Support\original_request()->attributes->set('tala.selected_term_id', $this->termId);

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
            $this->redirect(self::getUrl([
                'termId' => $this->termId,
                'viewTab' => $this->viewTab,
                'selectedRunId' => $this->selectedRunId,
                'selectedVersionId' => $this->selectedVersionId,
            ]));
            $this->skipRender();

            return;
        }

        if ($this->termId === null && static::class !== TermOverview::class) {
            $this->redirect(TermOverview::getUrl());

            return;
        }

        $this->viewTab = static::class === self::class ? (in_array($this->viewTab, self::Tabs, true) ? $this->viewTab : 'plan') : static::$planningTab;
    }

    /** @return array{0: ?int, 1: ?string} */
    protected function resolveDefaultPlanningTermId(): array
    {
        $activeCalendarTermIds = TermCalendarPackage::query()
            ->where('state', TermCalendarPackage::StateActive)
            ->distinct()
            ->pluck('term_id');

        if ($activeCalendarTermIds->count() === 1) {
            return [(int) $activeCalendarTermIds->sole(), 'the only term with an active calendar'];
        }

        $activeId = Term::query()
            ->where('state', Term::StateActive)
            ->latest('starts_on')
            ->value('id');

        if ($activeId !== null) {
            return [(int) $activeId, null];
        }

        $draftId = Term::query()
            ->where('state', Term::StateDraft)
            ->latest('starts_on')
            ->value('id');

        if ($draftId !== null) {
            return [(int) $draftId, null];
        }

        $latestId = Term::query()->latest('starts_on')->value('id');

        return [$latestId !== null ? (int) $latestId : null, null];
    }

    /** @return array<int, string> */
    public function termSwitcherOptions(): array
    {
        $stateOrder = [
            Term::StateActive => 0,
            Term::StateDraft => 1,
            Term::StateClosed => 2,
        ];

        return Term::query()
            ->with('academicYear')
            ->latest('starts_on')
            ->get()
            ->sortBy(fn (Term $term): string => sprintf('%d-%s', $stateOrder[$term->state] ?? 9, str_pad((string) (99999999 - (int) $term->starts_on->format('Ymd')), 8, '0', STR_PAD_LEFT)))
            ->mapWithKeys(function (Term $term): array {
                $parts = array_filter([
                    $term->typeLabel(),
                    $term->academicYear?->label,
                ]);
                $stateLabel = Term::stateOptions()[$term->state] ?? $term->state;

                return [(int) $term->id => implode(' · ', $parts).' ['.$stateLabel.']'];
            })
            ->all();
    }

    public function createTermAction(): Action
    {
        return Action::make('createTerm')
            ->label('New term')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->modalHeading('New term')
            ->modalDescription('Choose the academic year, term, and dates. New terms start in Draft.')
            ->modalSubmitActionLabel('Create term')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => (bool) auth()->user()?->can('create', Term::class))
            ->schema([
                Select::make('academic_year_id')
                    ->label('Academic year')
                    ->options(fn (): array => AcademicYear::query()
                        ->orderByDesc('starts_on')
                        ->get()
                        ->mapWithKeys(fn (AcademicYear $record): array => [$record->id => $record->state === AcademicYear::StateActive ? (string) $record->label : $record->displayLabel()])
                        ->all())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->default(fn (): ?int => $this->termId !== null ? Term::query()->whereKey($this->termId)->value('academic_year_id') : AcademicYear::query()->latest('starts_on')->value('id'))
                    ->createOptionAction(fn (Action $action): Action => $action->label('Add academic year')->modalHeading('Add academic year')->modalSubmitActionLabel('Create academic year'))
                    ->createOptionForm([
                        TextInput::make('label')->label('Academic year')->required()->maxLength(255)->unique('academic_years', 'label'),
                        Grid::make(['default' => 1, 'sm' => 2])
                            ->schema([
                                DatePicker::make('starts_on')
                                    ->label('Academic year start')
                                    ->native(false)
                                    ->displayFormat('M j, Y')
                                    ->suffixIcon(Heroicon::OutlinedCalendar)
                                    ->required(),
                                DatePicker::make('ends_on')
                                    ->label('Academic year end')
                                    ->native(false)
                                    ->displayFormat('M j, Y')
                                    ->suffixIcon(Heroicon::OutlinedCalendar)
                                    ->required()
                                    ->after('starts_on'),
                            ]),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        Gate::authorize('create', AcademicYear::class);

                        return (int) AcademicYear::query()->create([...$data, 'state' => AcademicYear::StateDraft])->id;
                    })
                    ->required(),
                Select::make('type')
                    ->label('Term')
                    ->options(Term::typeOptions())
                    ->required()
                    ->unique(
                        table: Term::class,
                        column: 'type',
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('academic_year_id', $get('academic_year_id')),
                    )
                    ->validationMessages([
                        'unique' => 'This academic year already has that term. Switch to the existing term or choose a different one.',
                    ]),
                Grid::make(['default' => 1, 'sm' => 2])
                    ->schema([
                        DatePicker::make('starts_on')
                            ->label('Start date')
                            ->native(false)
                            ->displayFormat('M j, Y')
                            ->suffixIcon(Heroicon::OutlinedCalendar)
                            ->minDate(fn (Get $get): ?string => $this->academicYearDateBound($get('academic_year_id'), 'start'))
                            ->maxDate(fn (Get $get): ?string => $this->academicYearDateBound($get('academic_year_id'), 'end'))
                            ->helperText(fn (Get $get): ?string => $this->academicYearBoundsHelperText($get('academic_year_id')))
                            ->required()
                            ->rules([$this->academicYearBoundRule('start')]),
                        DatePicker::make('ends_on')
                            ->label('End date')
                            ->native(false)
                            ->displayFormat('M j, Y')
                            ->suffixIcon(Heroicon::OutlinedCalendar)
                            ->minDate(fn (Get $get): ?string => $this->academicYearDateBound($get('academic_year_id'), 'start'))
                            ->maxDate(fn (Get $get): ?string => $this->academicYearDateBound($get('academic_year_id'), 'end'))
                            ->helperText('Must be on or after the term start date.')
                            ->required()
                            ->after('starts_on')
                            ->rules([$this->academicYearBoundRule('end')]),
                    ]),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                Gate::forUser($actor)->authorize('create', Term::class);

                $term = Term::query()->create([
                    'academic_year_id' => $data['academic_year_id'],
                    'type' => $data['type'],
                    'label' => Term::typeOptions()[$data['type']] ?? (string) $data['type'],
                    'starts_on' => $data['starts_on'],
                    'ends_on' => $data['ends_on'],
                    'default_max_units' => 24,
                    'state' => Term::StateDraft,
                    'scheduling_slot_minutes' => 30,
                    'scheduling_days' => null,
                ]);

                Notification::make()
                    ->title('Term created')
                    ->body("{$term->typeLabel()} is ready. Set its calendar to begin planning.")
                    ->success()
                    ->send();

                $this->redirect(TermCalendar::getUrl(['termId' => $term->id]));
            });
    }

    public function isTermClosed(?Term $term = null): bool
    {
        $resolved = $term ?? ($this->termId !== null ? Term::query()->find($this->termId) : null);

        return $resolved instanceof Term && $resolved->state === Term::StateClosed;
    }

    public function hasPublishedTimetable(?Term $term = null): bool
    {
        $resolved = $term ?? ($this->termId !== null ? Term::query()->find($this->termId) : null);
        if (! $resolved instanceof Term) {
            return false;
        }

        if ($resolved->relationLoaded('timetableVersions')) {
            return $resolved->timetableVersions->contains('state', PublishedTimetableVersion::StatePublished);
        }

        return PublishedTimetableVersion::query()
            ->where('term_id', $resolved->id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->exists();
    }

    public function termLifecycleMode(?Term $term = null): string
    {
        $resolved = $term ?? ($this->termId !== null ? Term::query()->find($this->termId) : null);
        if (! $resolved instanceof Term) {
            return 'draft';
        }

        if ($this->isTermClosed($resolved)) {
            return 'closed';
        }

        if ($this->hasPublishedTimetable($resolved)) {
            return 'live';
        }

        $hasActiveCalendar = $resolved->relationLoaded('calendarPackages')
            ? $resolved->calendarPackages->contains('state', TermCalendarPackage::StateActive)
            : TermCalendarPackage::query()
                ->where('term_id', $resolved->id)
                ->where('state', TermCalendarPackage::StateActive)
                ->exists();

        if ($resolved->state === Term::StateDraft && ! $hasActiveCalendar) {
            return 'draft';
        }

        return 'planning';
    }

    public function hasOfficialStudentEnrollments(?Term $term = null): bool
    {
        $resolved = $term ?? ($this->termId !== null ? Term::query()->find($this->termId) : null);
        if (! $resolved instanceof Term) {
            return false;
        }

        return CourseEnrollment::query()
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->whereHas('enrollment', fn ($query) => $query->where('term_id', $resolved->id))
            ->exists()
            || Enrollment::query()
                ->where('term_id', $resolved->id)
                ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
                ->exists();
    }

    public function canDeleteDraftTerm(?Term $term = null): bool
    {
        $resolved = $term ?? ($this->termId !== null ? Term::query()->find($this->termId) : null);
        if (! $resolved instanceof Term || $resolved->state !== Term::StateDraft) {
            return false;
        }

        return ! $resolved->termOfferings()->exists()
            && ! $resolved->enrollments()->exists()
            && ! $resolved->timetableVersions()->exists()
            && ! $resolved->hasActivatedCalendar()
            && ! TermCohort::query()->where('term_id', $resolved->id)->exists()
            && ! AdmissionCycle::query()->where('term_id', $resolved->id)->exists()
            && ! FeePlan::query()->where('term_id', $resolved->id)->exists()
            && ! $resolved->scheduleGenerationRuns()->exists();
    }

    /**
     * @return array{
     *     active_solver_runs: int,
     *     draft_revisions: int,
     *     open_admission_cycles: int,
     *     unreleased_grade_rosters: int,
     *     in_progress_enrollments: int,
     * }
     */
    protected function preCloseTermCheck(Term $term): array
    {
        return [
            'active_solver_runs' => ScheduleGenerationRun::query()
                ->where('term_id', $term->id)
                ->whereIn('status', [
                    ScheduleGenerationRun::StatusQueued,
                    ScheduleGenerationRun::StatusDispatching,
                ])
                ->count(),
            'draft_revisions' => TimetableRevision::query()
                ->where('term_id', $term->id)
                ->where('state', TimetableRevision::StateDraft)
                ->count(),
            'open_admission_cycles' => AdmissionCycle::query()
                ->where('term_id', $term->id)
                ->where('state', AdmissionCycle::StatePublished)
                ->where(function ($query): void {
                    $query->whereNull('closes_at')
                        ->orWhere('closes_at', '>', now());
                })
                ->count(),
            'unreleased_grade_rosters' => GradeRoster::query()
                ->where('state', '!=', GradeRoster::StateReleased)
                ->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
                ->count(),
            'in_progress_enrollments' => Enrollment::query()
                ->where('term_id', $term->id)
                ->where('canonical_outcome', Enrollment::OutcomeInProgress)
                ->count(),
        ];
    }

    /**
     * @param  array{
     *     active_solver_runs: int,
     *     draft_revisions: int,
     *     open_admission_cycles: int,
     *     unreleased_grade_rosters: int,
     *     in_progress_enrollments: int,
     * }  $check
     * @return list<string>
     */
    private function preCloseTermBlockerMessages(array $check): array
    {
        $blockers = [];

        if ($check['active_solver_runs'] > 0) {
            $blockers[] = sprintf(
                '%d timetable generation %s still queued or running. Wait for generation to finish before closing.',
                $check['active_solver_runs'],
                Str::plural('attempt is', $check['active_solver_runs']),
            );
        }

        if ($check['draft_revisions'] > 0) {
            $blockers[] = sprintf(
                '%d draft timetable %s still pending. Publish or cancel the draft revision before closing.',
                $check['draft_revisions'],
                Str::plural('revision is', $check['draft_revisions']),
            );
        }

        if ($check['open_admission_cycles'] > 0) {
            $blockers[] = sprintf(
                '%d published admission %s still open for this term. Close or cancel open admission cycles first.',
                $check['open_admission_cycles'],
                Str::plural('cycle is', $check['open_admission_cycles']),
            );
        }

        return $blockers;
    }

    public function termSettingsAction(): Action
    {
        return Action::make('termSettings')
            ->label('Term settings')
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->color('gray')
            ->modalHeading(function (): string {
                $term = $this->termId !== null ? Term::query()->find($this->termId) : null;

                if ($this->isTermClosed($term)) {
                    return 'Term settings';
                }

                return $term && $term->hasActivatedCalendar()
                    ? 'Term settings'
                    : 'Edit term settings';
            })
            ->modalDescription(function (): string {
                $readOnly = (bool) auth()->user()?->hasRole(User::StaffRoleAcademicHead);
                if ($readOnly) {
                    return 'Read-only term settings maintained by the Registrar.';
                }

                $term = $this->termId !== null ? Term::query()->find($this->termId) : null;

                if ($this->isTermClosed($term)) {
                    return 'This term is closed and read-only.';
                }

                return $term && $term->hasActivatedCalendar()
                    ? 'Term identity and dates are locked while calendar history is active. You can still update the default maximum teaching units.'
                    : 'Update term identity, dates, or the default faculty teaching-unit limit.';
            })
            ->modalSubmitAction(fn (Action $action): Action|false => auth()->user()?->hasRole(User::StaffRoleAcademicHead) || $this->isTermClosed() ? false : $action)
            ->modalSubmitActionLabel('Save changes')
            ->modalCancelActionLabel(fn (): string => auth()->user()?->hasRole(User::StaffRoleAcademicHead) || $this->isTermClosed() ? 'Close' : 'Cancel')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => $this->termId !== null)
            ->fillForm(function (): array {
                $term = $this->selectedTerm();

                return [
                    'academic_year_id' => $term->academic_year_id,
                    'type' => $term->type,
                    'starts_on' => $term->starts_on?->toDateString(),
                    'ends_on' => $term->ends_on?->toDateString(),
                    'default_max_units' => $term->default_max_units ?? 24,
                ];
            })
            ->schema(function (): array {
                $term = $this->selectedTerm()->loadMissing('academicYear');
                $isRegistrar = (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar);
                $readOnly = (bool) auth()->user()?->hasRole(User::StaffRoleAcademicHead) || $this->isTermClosed($term);
                $identityLocked = $readOnly || $term->hasActivatedCalendar();
                $canCloseTerm = $isRegistrar
                    && ! $readOnly
                    && ($term->state === Term::StateActive || $this->hasPublishedTimetable($term));
                $canDeleteDraft = $isRegistrar
                    && ! $readOnly
                    && $this->canDeleteDraftTerm($term);

                if ($readOnly) {
                    $defaultUnits = $term->default_max_units === null
                        ? '24 units (default)'
                        : rtrim(rtrim(number_format((float) $term->default_max_units, 2, '.', ''), '0'), '.').' units';

                    return [
                        SchemaSection::make('Recorded term settings')
                            ->icon(Heroicon::OutlinedLockClosed)
                            ->compact()
                            ->extraAttributes(['class' => 'tala-term-settings-identity'])
                            ->columns(2)
                            ->schema([
                                Placeholder::make('term_identity')
                                    ->label('Term')
                                    ->content(implode(' · ', array_filter([$term->typeLabel(), $term->academicYear?->label]))),
                                Placeholder::make('term_dates')
                                    ->label('Dates')
                                    ->content($term->starts_on->format('M j, Y').' – '.$term->ends_on->format('M j, Y')),
                                Placeholder::make('term_state')
                                    ->label('Status')
                                    ->content(Term::stateOptions()[$term->state] ?? $term->state),
                                Placeholder::make('term_max_units')
                                    ->label('Default maximum teaching units')
                                    ->content($defaultUnits),
                            ]),
                    ];
                }

                $sections = [
                    SchemaSection::make('Term details')
                        ->icon(Heroicon::OutlinedCalendarDays)
                        ->compact()
                        ->hidden($identityLocked)
                        ->schema([
                            Select::make('academic_year_id')
                                ->label('Academic year')
                                ->options(fn (): array => AcademicYear::query()
                                    ->orderByDesc('starts_on')
                                    ->get()
                                    ->mapWithKeys(fn (AcademicYear $record): array => [$record->id => $record->state === AcademicYear::StateActive ? (string) $record->label : $record->displayLabel()])
                                    ->all())
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required()
                                ->disabled($identityLocked)
                                ->validatedWhenNotDehydrated(false),
                            Select::make('type')
                                ->label('Term')
                                ->options(Term::typeOptions())
                                ->required()
                                ->unique(
                                    table: Term::class,
                                    column: 'type',
                                    ignorable: $term,
                                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('academic_year_id', $get('academic_year_id')),
                                )
                                ->validationMessages([
                                    'unique' => 'This academic year already has that term. Switch to the existing term or choose a different one.',
                                ])
                                ->disabled($identityLocked)
                                ->validatedWhenNotDehydrated(false),
                            DatePicker::make('starts_on')
                                ->label('Start date')
                                ->native(false)
                                ->displayFormat('M j, Y')
                                ->suffixIcon(Heroicon::OutlinedCalendar)
                                ->minDate(fn (Get $get): ?string => $this->academicYearDateBound($get('academic_year_id'), 'start'))
                                ->maxDate(fn (Get $get): ?string => $this->academicYearDateBound($get('academic_year_id'), 'end'))
                                ->helperText(fn (Get $get): ?string => $this->academicYearBoundsHelperText($get('academic_year_id')))
                                ->required()
                                ->rules([$this->academicYearBoundRule('start')])
                                ->disabled($identityLocked)
                                ->validatedWhenNotDehydrated(false),
                            DatePicker::make('ends_on')
                                ->label('End date')
                                ->native(false)
                                ->displayFormat('M j, Y')
                                ->suffixIcon(Heroicon::OutlinedCalendar)
                                ->minDate(fn (Get $get): ?string => $this->academicYearDateBound($get('academic_year_id'), 'start'))
                                ->maxDate(fn (Get $get): ?string => $this->academicYearDateBound($get('academic_year_id'), 'end'))
                                ->helperText('Must be on or after the term start date.')
                                ->required()
                                ->after('starts_on')
                                ->rules([$this->academicYearBoundRule('end')])
                                ->disabled($identityLocked)
                                ->validatedWhenNotDehydrated(false),
                        ])
                        ->columns(['default' => 1, 'sm' => 2]),
                    SchemaSection::make('Recorded term identity')
                        ->icon(Heroicon::OutlinedLockClosed)
                        ->description('Locked while calendar history is active.')
                        ->compact()
                        ->extraAttributes(['class' => 'tala-term-settings-identity'])
                        ->visible($identityLocked)
                        ->schema([
                            Placeholder::make('locked_identity')
                                ->label('Term')
                                ->content(implode(' · ', array_filter([$term->typeLabel(), $term->academicYear?->label]))),
                            Placeholder::make('locked_dates')
                                ->label('Dates')
                                ->content($term->starts_on->format('M j, Y').' – '.$term->ends_on->format('M j, Y')),
                        ])
                        ->columns(2),
                    SchemaSection::make('Default teaching load')
                        ->icon(Heroicon::OutlinedAcademicCap)
                        ->description('Maximum teaching units per faculty member for this term (24 units default per CHED CMO 40 s. 2008). Individual load overrides take precedence.')
                        ->compact()
                        ->extraAttributes(['class' => 'tala-term-settings-load'])
                        ->schema([
                            TextInput::make('default_max_units')
                                ->label('Maximum teaching units per faculty member')
                                ->numeric()
                                ->default(24)
                                ->minValue(0)
                                ->suffix('units')
                                ->step(0.25)
                                ->required(),
                        ]),
                ];

                if ($canCloseTerm || $canDeleteDraft) {
                    $lifecycleComponents = [];

                    if ($canCloseTerm) {
                        $lifecycleComponents[] = Placeholder::make('close_term_consequence')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<p class="text-sm text-gray-600 dark:text-gray-300"><strong>Close term</strong> — Marks this term as Closed and locks its calendar, classes, staffing, timetable, enrollment, and grading as a read-only historical record. Closed terms remain viewable in Term Planning under the Closed filter. Active solver runs, draft timetable revisions, and open admission cycles must be resolved first.</p>'
                            ));
                    }

                    if ($canDeleteDraft) {
                        $lifecycleComponents[] = Placeholder::make('delete_draft_consequence')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<p class="text-sm text-gray-600 dark:text-gray-300"><strong>Delete draft term</strong> — Permanently removes this unused draft term and any draft calendar rows. This cannot be undone.</p>'
                            ));
                    }

                    $lifecycleActions = [];

                    if ($canCloseTerm) {
                        $lifecycleActions[] = Action::make('openCloseTerm')
                            ->label('Close term')
                            ->icon(Heroicon::OutlinedLockClosed)
                            ->color('warning')
                            ->action(fn () => $this->replaceMountedAction('closeTerm'));
                    }

                    if ($canDeleteDraft) {
                        $lifecycleActions[] = Action::make('openDeleteDraftTerm')
                            ->label('Delete draft term')
                            ->icon(Heroicon::OutlinedTrash)
                            ->color('danger')
                            ->action(fn () => $this->replaceMountedAction('deleteDraftTerm'));
                    }

                    $lifecycleComponents[] = Actions::make($lifecycleActions);

                    $sections[] = SchemaSection::make('Term lifecycle · Danger zone')
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->iconColor('danger')
                        ->description('Irreversible or locking lifecycle actions for this term.')
                        ->compact()
                        ->extraAttributes(['class' => 'tala-term-settings-danger'])
                        ->schema($lifecycleComponents);
                }

                return $sections;
            })
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $selected = $this->selectedTerm();
                $needsDemandRefresh = false;

                DB::transaction(function () use ($selected, $data, $actor, $schema, &$needsDemandRefresh): void {
                    $term = Term::query()->whereKey($selected->id)->lockForUpdate()->firstOrFail();
                    Gate::forUser($actor)->authorize('update', $term);

                    if ($term->state === Term::StateClosed) {
                        throw ValidationException::withMessages([
                            'state' => 'This term is closed and read-only.',
                        ]);
                    }

                    $oldDefaultMaxUnits = (float) ($term->default_max_units ?? 24);
                    $newDefaultMaxUnits = (float) ($data['default_max_units'] ?? $term->default_max_units ?? 24);
                    $defaultUnitsChanged = abs($oldDefaultMaxUnits - $newDefaultMaxUnits) > 0.0001;

                    if ($this->hasPublishedTimetable($term) && $newDefaultMaxUnits + 0.0001 < $oldDefaultMaxUnits) {
                        $facultyIdsWithActiveOverride = FacultyTermLoadOverride::query()
                            ->where('term_id', $term->id)
                            ->where('is_active', true)
                            ->pluck('faculty_user_id')
                            ->map(fn (mixed $id): int => (int) $id)
                            ->all();

                        $activeMeetings = SectionMeeting::query()
                            ->activeOfficial()
                            ->whereNotNull('faculty_user_id')
                            ->whereHas('scheduleRun', fn ($query) => $query->where('term_id', $term->id))
                            ->with([
                                'schedulingDemand.courseComponent.courseSpecification',
                                'schedulingDemand.sectionDeliveryGroup',
                                'schedulingDemand.termOffering.curriculumEntry.courseSpecification',
                            ])
                            ->get();

                        $maxAssignedUnitsWithoutOverride = 0.0;

                        foreach ($activeMeetings->groupBy('faculty_user_id') as $facultyId => $meetings) {
                            if (in_array((int) $facultyId, $facultyIdsWithActiveOverride, true)) {
                                continue;
                            }

                            $countedKeys = [];
                            $facultyUnits = 0.0;

                            foreach ($meetings as $meeting) {
                                $demand = $meeting->schedulingDemand;
                                if (! $demand instanceof SchedulingDemand) {
                                    continue;
                                }

                                $sectionId = $demand->sectionDeliveryGroup?->section_id;
                                $unitKey = $sectionId !== null
                                    ? 'section:'.$sectionId
                                    : 'demand:'.$demand->id;

                                if (isset($countedKeys[$unitKey])) {
                                    continue;
                                }
                                $countedKeys[$unitKey] = true;

                                $creditUnits = $demand->courseComponent?->courseSpecification?->credit_units
                                    ?? $demand->termOffering?->curriculumEntry?->courseSpecification?->credit_units
                                    ?? 0;
                                $facultyUnits += (float) $creditUnits;
                            }

                            if ($facultyUnits > $maxAssignedUnitsWithoutOverride) {
                                $maxAssignedUnitsWithoutOverride = $facultyUnits;
                            }
                        }

                        if ($maxAssignedUnitsWithoutOverride > $newDefaultMaxUnits + 0.0001) {
                            $minRequired = rtrim(rtrim(number_format($maxAssignedUnitsWithoutOverride, 2, '.', ''), '0'), '.');

                            throw ValidationException::withMessages([
                                $schema->getStatePath().'.default_max_units' => "Cannot lower default maximum teaching units below {$minRequired} units because at least one faculty member without an individual load override is already assigned {$minRequired} units on the published timetable.",
                            ]);
                        }
                    }

                    if ($term->hasActivatedCalendar()) {
                        $term->update([
                            'default_max_units' => $newDefaultMaxUnits,
                        ]);
                    } else {
                        $term->update([
                            'academic_year_id' => $data['academic_year_id'],
                            'type' => $data['type'],
                            'label' => Term::typeOptions()[$data['type']] ?? (string) $data['type'],
                            'starts_on' => $data['starts_on'],
                            'ends_on' => $data['ends_on'],
                            'default_max_units' => $newDefaultMaxUnits,
                        ]);
                    }

                    if ($defaultUnitsChanged && ! $this->hasPublishedTimetable($term)) {
                        $needsDemandRefresh = true;
                        $staleNote = 'Marked stale because the term default maximum teaching units changed.';
                        $activeRuns = ScheduleGenerationRun::query()
                            ->where('term_id', $term->id)
                            ->whereIn('status', [
                                ScheduleGenerationRun::StatusQueued,
                                ScheduleGenerationRun::StatusDispatching,
                                ScheduleGenerationRun::StatusUnderReview,
                            ])
                            ->lockForUpdate()
                            ->get();

                        foreach ($activeRuns as $activeRun) {
                            $diagnostics = is_array($activeRun->diagnostics) ? $activeRun->diagnostics : [];
                            $diagnostics['stale_reason'] = $staleNote;

                            $activeRun->forceFill([
                                'candidate_state' => 'Stale',
                                'candidate_review_reason' => $staleNote,
                                'diagnostics' => $diagnostics,
                            ])->save();
                        }
                    }
                }, attempts: 3);

                if ($needsDemandRefresh && $actor->can('create', SchedulingDemand::class)) {
                    app(GenerateSchedulingDemand::class)->forTerm($actor, $this->selectedTerm());
                }

                Notification::make()
                    ->title('Term settings saved')
                    ->body($needsDemandRefresh ? 'Scheduling requirements refreshed and active draft runs marked stale.' : null)
                    ->success()
                    ->send();
            });
    }

    public function closeTermAction(): Action
    {
        return Action::make('closeTerm')
            ->label('Close term')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->modalHeading('Close term')
            ->modalDescription(function (): string {
                $term = $this->termId !== null ? Term::query()->find($this->termId) : null;
                $label = $term ? $term->typeLabel() : 'this term';

                return "Closing {$label} marks it Closed and locks its calendar, classes, staffing, and timetable as a read-only record in Term Planning.";
            })
            ->modalContent(function (): ?HtmlString {
                $term = $this->termId !== null ? Term::query()->find($this->termId) : null;
                if (! $term instanceof Term) {
                    return null;
                }

                $check = $this->preCloseTermCheck($term);
                $blockers = $this->preCloseTermBlockerMessages($check);
                $warnings = [];

                if ($check['unreleased_grade_rosters'] > 0) {
                    $warnings[] = sprintf(
                        '%d unreleased grade %s (%s).',
                        $check['unreleased_grade_rosters'],
                        Str::plural('roster', $check['unreleased_grade_rosters']),
                        'not yet posted and released',
                    );
                }

                if ($check['in_progress_enrollments'] > 0) {
                    $warnings[] = sprintf(
                        '%d in-progress student %s still open.',
                        $check['in_progress_enrollments'],
                        Str::plural('enrollment', $check['in_progress_enrollments']),
                    );
                }

                if ($blockers === [] && $warnings === []) {
                    return new HtmlString(
                        '<p class="text-sm text-gray-600 dark:text-gray-300">Pre-close checks passed: no active solver runs, draft timetable revisions, open admission cycles, unreleased grade rosters, or in-progress enrollments found.</p>'
                    );
                }

                $parts = [];

                if ($blockers !== []) {
                    $items = implode('', array_map(fn (string $msg): string => '<li>'.e($msg).'</li>', $blockers));
                    $parts[] = '<div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950/40 dark:text-danger-200">'
                        .'<p class="font-semibold">Closing is blocked until resolved:</p>'
                        .'<ul class="mt-1 list-disc space-y-1 ps-5">'.$items.'</ul>'
                        .'</div>';
                }

                if ($warnings !== []) {
                    $items = implode('', array_map(fn (string $msg): string => '<li>'.e($msg).'</li>', $warnings));
                    $parts[] = '<div class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950/40 dark:text-warning-200">'
                        .'<p class="font-semibold">Outstanding term records (warning):</p>'
                        .'<ul class="mt-1 list-disc space-y-1 ps-5">'.$items.'</ul>'
                        .'</div>';
                }

                return new HtmlString('<div class="space-y-3">'.implode('', $parts).'</div>');
            })
            ->modalSubmitActionLabel('Close term')
            ->visible(function (): bool {
                if (! auth()->user()?->hasRole(User::StaffRoleRegistrar) || $this->termId === null) {
                    return false;
                }

                $term = Term::query()->find($this->termId);

                return $term instanceof Term
                    && ! $this->isTermClosed($term)
                    && ($term->state === Term::StateActive || $this->hasPublishedTimetable($term));
            })
            ->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $selected = $this->selectedTerm();

                try {
                    DB::transaction(function () use ($selected, $actor): void {
                        $term = Term::query()->whereKey($selected->id)->lockForUpdate()->firstOrFail();
                        Gate::forUser($actor)->authorize('update', $term);

                        if ($term->state === Term::StateClosed) {
                            return;
                        }

                        if ($term->state !== Term::StateActive && ! $this->hasPublishedTimetable($term)) {
                            throw ValidationException::withMessages([
                                'state' => 'Only active or published terms can be closed.',
                            ]);
                        }

                        $check = $this->preCloseTermCheck($term);
                        $blockers = $this->preCloseTermBlockerMessages($check);

                        if ($blockers !== []) {
                            throw ValidationException::withMessages([
                                'state' => implode(' ', $blockers),
                            ]);
                        }

                        $term->update(['state' => Term::StateClosed]);
                    }, attempts: 3);
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Term cannot be closed')
                        ->body(is_string($message) ? $message : 'Resolve active scheduling or admission blockers before closing this term.')
                        ->danger()
                        ->persistent()
                        ->send();

                    throw $exception;
                }

                Notification::make()
                    ->title('Term closed')
                    ->body('The term is now read-only.')
                    ->success()
                    ->send();
            });
    }

    public function deleteDraftTermAction(): Action
    {
        return Action::make('deleteDraftTerm')
            ->label('Delete draft term')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete draft term')
            ->modalDescription('Delete this unused draft term permanently. This cannot be undone.')
            ->modalSubmitActionLabel('Delete draft term')
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->termId !== null
                && $this->canDeleteDraftTerm())
            ->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $selected = $this->selectedTerm();

                try {
                    DB::transaction(function () use ($selected, $actor): void {
                        $term = Term::query()->whereKey($selected->id)->lockForUpdate()->firstOrFail();
                        Gate::forUser($actor)->authorize('update', $term);

                        if (! $this->canDeleteDraftTerm($term)) {
                            throw ValidationException::withMessages([
                                'state' => 'Only unused draft terms with no offerings, enrollments, or timetable versions can be deleted.',
                            ]);
                        }

                        $term->calendarPackages()
                            ->where('state', TermCalendarPackage::StateDraft)
                            ->each(function (TermCalendarPackage $package): void {
                                $package->windows()->delete();
                                $package->teachingGridRows()->delete();
                                $package->datedExceptions()->delete();
                                $package->delete();
                            });

                        $term->delete();
                    }, attempts: 3);
                } catch (QueryException) {
                    Notification::make()
                        ->title('Draft term cannot be deleted')
                        ->body('This draft term is still referenced by linked admission or student records.')
                        ->danger()
                        ->persistent()
                        ->send();

                    throw ValidationException::withMessages([
                        'term' => 'This draft term is still referenced by linked admission or student records.',
                    ]);
                }

                Notification::make()
                    ->title('Draft term deleted')
                    ->success()
                    ->send();

                $this->redirect(TermOverview::getUrl());
            });
    }

    private function academicYearDateBound(mixed $academicYearId, string $boundary): ?string
    {
        if (blank($academicYearId)) {
            return null;
        }

        $academicYear = AcademicYear::query()->find($academicYearId);
        if (! $academicYear instanceof AcademicYear) {
            return null;
        }

        return $boundary === 'start'
            ? $academicYear->starts_on?->toDateString()
            : $academicYear->ends_on?->toDateString();
    }

    private function academicYearBoundsHelperText(mixed $academicYearId): ?string
    {
        if (blank($academicYearId)) {
            return 'Select an academic year first to constrain valid term dates.';
        }

        $academicYear = AcademicYear::query()->find($academicYearId);
        if (! $academicYear instanceof AcademicYear || ! $academicYear->starts_on || ! $academicYear->ends_on) {
            return null;
        }

        return sprintf(
            'Within AY %s (%s – %s).',
            $academicYear->displayLabel(),
            $academicYear->starts_on->format('M j, Y'),
            $academicYear->ends_on->format('M j, Y'),
        );
    }

    private function academicYearBoundRule(string $boundary): Closure
    {
        return function (Get $get) use ($boundary): Closure {
            return function (string $attribute, mixed $value, Closure $fail) use ($get, $boundary): void {
                $academicYearId = $get('academic_year_id');

                if (blank($academicYearId) || blank($value)) {
                    return;
                }

                $academicYear = AcademicYear::query()->find($academicYearId);

                if (! $academicYear instanceof AcademicYear) {
                    return;
                }

                $date = CarbonImmutable::parse($value)->startOfDay();
                $startsOn = CarbonImmutable::parse($academicYear->starts_on)->startOfDay();
                $endsOn = CarbonImmutable::parse($academicYear->ends_on)->startOfDay();

                if ($date->betweenIncluded($startsOn, $endsOn)) {
                    return;
                }

                $label = $boundary === 'start' ? 'start' : 'end';
                $fail("The term {$label} date must be within {$academicYear->displayLabel()}.");
            };
        };
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

    public function addOperationalDatesAction(): Action
    {
        return Action::make('addOperationalDates')
            ->label(fn (): string => $this->activeCalendarPackage()?->windows()->exists() ? 'Edit dates' : 'Set dates')
            ->modalHeading('Key term dates')
            ->color('gray')
            ->modalDescription('Optional for timetable generation. Required before opening student enrollment or faculty grading. Current teaching dates, hours, and school events are retained.')
            ->modalSubmitActionLabel('Save dates')
            ->modalWidth('5xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->fillForm(function (): array {
                $package = $this->activeCalendarPackage();
                if (! $package instanceof TermCalendarPackage) {
                    return ['source_package_id' => null, 'source_token' => null, 'windows' => []];
                }

                $data = $this->calendarPackageFormData($package);
                if ($data['windows'] === []) {
                    $data['windows'] = [[
                        'window_type' => null,
                        'opens_on' => null,
                        'closes_on' => null,
                        'cutoff_at' => null,
                    ]];
                }

                return $data;
            })
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->termId !== null
                && ! $this->isTermClosed())
            ->schema([
                Hidden::make('source_package_id'),
                Hidden::make('source_token'),
                ...$this->operationalDateFields(),
            ])
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $selectedTerm = $this->selectedTerm();
                Gate::forUser($actor)->authorize('update', $selectedTerm);

                if ($this->isTermClosed($selectedTerm)) {
                    throw ValidationException::withMessages([
                        $schema->getStatePath().'.windows' => 'This term is closed and read-only.',
                    ]);
                }

                try {
                    DB::transaction(function () use ($data, $actor, $schema, $selectedTerm): void {
                        $term = Term::query()->whereKey($selectedTerm->id)->lockForUpdate()->firstOrFail();
                        $source = TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)->first();

                        if ((int) ($source?->id ?? 0) !== (int) ($data['source_package_id'] ?? 0)
                            || ($source && ! hash_equals($source->concurrencyToken(), (string) ($data['source_token'] ?? '')))) {
                            Notification::make()->title('Calendar source changed')->body('Reopen this form to review the current active calendar before saving.')->danger()->persistent()->send();

                            throw ValidationException::withMessages([$schema->getStatePath().'.windows' => 'The active calendar changed. Reopen this form to review the current version.']);
                        }

                        abort_unless($source instanceof TermCalendarPackage, 422);

                        $merged = [...$this->calendarPackageFormData($source), ...collect($data)->only(['windows'])->all()];
                        $version = ((int) TermCalendarPackage::query()->where('term_id', $term->id)->max('version')) + 1;

                        $package = TermCalendarPackage::query()->create([
                            ...collect($merged)->except(['source_package_id', 'source_token', 'windows', 'teaching_grid_rows', 'dated_exceptions'])->all(),
                            'term_id' => $term->id,
                            'version' => $version,
                            'state' => TermCalendarPackage::StateDraft,
                            'recorded_by' => $actor->id,
                        ]);

                        foreach ($merged['windows'] ?? [] as $window) {
                            $package->windows()->create($window);
                        }

                        foreach ($merged['teaching_grid_rows'] as $row) {
                            $package->teachingGridRows()->create($row);
                        }

                        foreach ($merged['dated_exceptions'] ?? [] as $exception) {
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
                    }, 3);
                } catch (ValidationException $exception) {
                    if (! array_key_exists($schema->getStatePath().'.windows', $exception->errors())) {
                        Notification::make()
                            ->title('Cannot save activity dates')
                            ->body(collect($exception->errors())->flatten()->unique()->implode(' '))
                            ->danger()
                            ->persistent()
                            ->send();
                    }

                    throw $exception;
                }

                Notification::make()
                    ->title('Activity dates updated')
                    ->body("The updated activity dates are now active for {$selectedTerm->label}.")
                    ->success()
                    ->send();
            });
    }

    /** @return list<Component> */
    private function operationalDateFields(): array
    {
        return [
            Repeater::make('windows')
                ->label('Date periods')
                ->defaultItems(0)
                ->addActionLabel('Add date period')
                ->itemLabel(fn (array $state): string => TermCalendarWindow::typeOptions()[$state['window_type'] ?? ''] ?? 'Date period')
                ->schema([
                    Select::make('window_type')->label('Period')->options(TermCalendarWindow::typeOptions())->required()->distinct(),
                    DatePicker::make('opens_on')
                        ->label('Opens on')
                        ->native(false)
                        ->displayFormat('M j, Y')
                        ->suffixIcon(Heroicon::OutlinedCalendar)
                        ->required(),
                    DatePicker::make('closes_on')
                        ->label('Closes on')
                        ->native(false)
                        ->displayFormat('M j, Y')
                        ->suffixIcon(Heroicon::OutlinedCalendar)
                        ->required()
                        ->afterOrEqual('opens_on'),
                    TimePicker::make('cutoff_at')->label('Close time (Asia/Manila)')->timezone('UTC')->seconds(false),
                ])
                ->columns(2)
                ->reorderable(false)
                ->helperText('Leave Close time blank to keep the period open until 11:59 PM on the closing date.'),
        ];
    }

    /** @return array<string, string> */
    protected function datedExceptionTypeOptions(?string $current = null): array
    {
        $options = [
            'Holiday' => 'Holiday',
            'School Event' => 'School Event',
            'Class Suspension' => 'Class Suspension / No-Class Day',
            'Make-up Class Day' => 'Make-up Class Day',
            'Other' => 'Other',
        ];

        if (filled($current) && ! array_key_exists($current, $options)) {
            $legacyLabels = [
                'NoClasses' => 'No Classes (legacy)',
                'MakeUpAllowed' => 'Make-up Allowed (legacy)',
                'InformationOnly' => 'Information Only (legacy)',
            ];

            $options[$current] = $legacyLabels[$current] ?? $current;
        }

        return $options;
    }

    protected function defaultBlocksTeachingForExceptionType(?string $type): ?bool
    {
        return match ($type) {
            'Holiday', 'Class Suspension', 'NoClasses' => true,
            'Make-up Class Day', 'MakeUpAllowed', 'InformationOnly' => false,
            default => null,
        };
    }

    public function sendAvailabilityReminderAction(): Action
    {
        return Action::make('sendAvailabilityReminder')
            ->label('Send reminder & due date')
            ->icon(Heroicon::OutlinedBell)
            ->color('gray')
            ->modalHeading('Send reminder & due date')
            ->modalDescription('Faculty can already submit availability anytime once the teaching calendar is active. Set an optional reply-by date and send email reminders to faculty with no response.')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Save & send')
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->termId !== null
                && ! $this->isTermClosed()
                && ! $this->hasPublishedTimetable())
            ->disabled(fn (): bool => ! $this->activeCalendarPackage() instanceof TermCalendarPackage)
            ->tooltip(fn (): ?string => $this->activeCalendarPackage() instanceof TermCalendarPackage ? null : 'Activate the teaching calendar first.')
            ->fillForm(function (): array {
                $term = $this->selectedTerm();
                $package = $this->activeCalendarPackage();
                $prior = FacultyAvailabilityDeadline::query()->where('term_id', $term->id)->latest('id')->first();
                $projection = app(FacultyAvailabilityStatusProjection::class)->forTerm($term);
                $noResponseIds = collect($projection['faculty_rows'])
                    ->whereNull('declaration')
                    ->pluck('faculty_user_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();

                return [
                    'package_id' => $package?->id,
                    'package_token' => $package?->concurrencyToken(),
                    'expected_deadline_id' => $prior?->id ?? 0,
                    'original_due_at_utc' => $prior?->due_at?->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                    'original_due_at_display' => $prior?->due_at?->copy()->timezone('Asia/Manila')->format('Y-m-d H:i'),
                    'due_at' => $prior?->due_at?->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i:s'),
                    'faculty_user_ids' => $noResponseIds,
                ];
            })
            ->schema([
                Hidden::make('package_id')->required(),
                Hidden::make('package_token')->required(),
                Hidden::make('expected_deadline_id')->required(),
                Hidden::make('original_due_at_utc'),
                Hidden::make('original_due_at_display'),
                Select::make('faculty_user_ids')
                    ->label('Recipients (no response)')
                    ->helperText('Pre-filled with Term faculty who have not replied yet. Clear to update only the due date.')
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
                    ->searchable(),
                DateTimePicker::make('due_at')
                    ->label('Reply by (Asia/Manila)')
                    ->timezone('Asia/Manila')
                    ->seconds(false)
                    ->live()
                    ->helperText('Required when sending first-time reminders; optional otherwise. Shown to faculty as a due date, though they can still answer anytime while the teaching calendar is active.')
                    ->required(fn (Get $get): bool => filled($get('faculty_user_ids')) && (int) $get('expected_deadline_id') === 0),
                Textarea::make('correction_reason')
                    ->label('Reason for changing due date')
                    ->helperText('Required when changing an existing due date.')
                    ->maxLength(2000)
                    ->required(fn (Get $get): bool => (int) $get('expected_deadline_id') > 0 && $this->deadlineInputChanged($get('due_at'), $get('original_due_at_display'), 'Asia/Manila'))
                    ->visible(fn (Get $get): bool => (int) $get('expected_deadline_id') > 0 && $this->deadlineInputChanged($get('due_at'), $get('original_due_at_display'), 'Asia/Manila')),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $term = $this->selectedTerm();
                if ($this->isTermClosed($term) || $this->hasPublishedTimetable($term)) {
                    throw ValidationException::withMessages([
                        'faculty_user_ids' => 'Availability reminders cannot be sent for closed or published terms.',
                    ]);
                }

                $package = TermCalendarPackage::query()->where('term_id', $term->id)->find($data['package_id'] ?? null);
                abort_unless($package instanceof TermCalendarPackage, 409);

                $expectedDeadlineId = (int) ($data['expected_deadline_id'] ?? 0);
                $recipientIds = collect($data['faculty_user_ids'] ?? [])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $dueAtChanged = $this->deadlineInputChanged($data['due_at'] ?? null, $data['original_due_at_utc'] ?? null, (string) config('app.timezone'));
                $hasCorrectionReason = filled($data['correction_reason'] ?? null);
                $shouldRecordDeadline = ($expectedDeadlineId === 0 && filled($data['due_at'] ?? null))
                    || ($expectedDeadlineId > 0 && ($dueAtChanged || $hasCorrectionReason));

                if (! $shouldRecordDeadline && $recipientIds === []) {
                    $message = 'Select at least one recipient or enter a reply-by date.';
                    Notification::make()->title('Nothing to send or save')->body($message)->danger()->persistent()->send();

                    throw ValidationException::withMessages(['faculty_user_ids' => $message]);
                }

                $deadlineSaved = false;
                $effectiveDeadlineId = $expectedDeadlineId;

                try {
                    if ($shouldRecordDeadline) {
                        $savedDeadline = app(RecordFacultyAvailabilityDeadline::class)->execute(
                            $term,
                            $actor,
                            (string) $data['due_at'],
                            $data['authority_reference'] ?? null,
                            $data['correction_reason'] ?? null,
                            $expectedDeadlineId,
                        );
                        $deadlineSaved = true;
                        $effectiveDeadlineId = (int) $savedDeadline->id;
                    }

                    $events = collect();
                    if ($recipientIds !== []) {
                        $events = app(FacultyAvailabilityRequestService::class)->request(
                            $package->fresh(),
                            $recipientIds,
                            $actor,
                            (string) $data['package_token'],
                            $effectiveDeadlineId,
                        );
                    }
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Cannot send availability reminder')
                        ->body(collect($exception->errors())->flatten()->unique()->implode(' '))
                        ->danger()
                        ->persistent()
                        ->send();

                    throw $exception;
                }

                if ($deadlineSaved && $recipientIds !== []) {
                    Notification::make()
                        ->title('Due date saved and availability reminders queued')
                        ->body($events->count().' reminder record(s) are available for delivery tracking.')
                        ->success()
                        ->send();
                } elseif ($deadlineSaved) {
                    Notification::make()
                        ->title('Reply due date recorded')
                        ->body('Faculty & rooms and My Availability now reflect this due date.')
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Availability reminders queued')
                        ->body($events->count().' reminder record(s) are available for delivery tracking.')
                        ->success()
                        ->send();
                }
            });
    }

    private function deadlineInputChanged(mixed $current, mixed $original, string $timezone): bool
    {
        if (blank($current)) {
            return false;
        }

        if (blank($original)) {
            return true;
        }

        try {
            $currentNormalized = CarbonImmutable::parse((string) $current, $timezone)->format('Y-m-d H:i');
            $originalNormalized = CarbonImmutable::parse((string) $original, $timezone)->format('Y-m-d H:i');

            return $currentNormalized !== $originalNormalized;
        } catch (Throwable) {
            return trim((string) $current) !== trim((string) $original);
        }
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

    public function confirmClassAction(): Action
    {
        return Action::make('confirmClassOffering')

            ->label('Confirm class')
            ->modalHeading('Confirm students & class')
            ->modalWidth(Width::ThreeExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Link the confirmed student sections to this class. Shared and mixed-year sections remain separate; their total must fit the approved class capacity.')
            ->modalSubmitActionLabel('Confirm students')
            ->fillForm(fn (array $arguments, ?Section $record = null): array => ['section_id' => $record?->id ?? $arguments['sectionId'] ?? null])
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->termId !== null
                && ! $this->isTermClosed()
                && ! $this->hasPublishedTimetable())
            ->schema(function (array $arguments, ?Section $record = null): array {
                $term = $this->selectedTerm();
                $cohorts = TermCohort::query()
                    ->where('term_id', $term->id)
                    ->orderBy('reference')
                    ->get();
                $cohortOptions = $cohorts
                    ->mapWithKeys(fn (TermCohort $cohort): array => [
                        $cohort->id => "{$cohort->reference} · {$cohort->confirmed_count} students",
                    ])
                    ->all();
                $cohortCountsById = $cohorts
                    ->mapWithKeys(fn (TermCohort $cohort): array => [$cohort->id => (int) $cohort->confirmed_count])
                    ->all();

                return [
                    Select::make('section_id')->label('Class')->disabled($record !== null || isset($arguments['sectionId']))->dehydrated()
                        ->options(Section::query()->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $term->id))->whereNull('confirmed_at')->orderBy('code')->pluck('code', 'id'))->required()->searchable()->live(),
                    Repeater::make('cohorts')->label('Student sections')
                        ->schema([
                            Select::make('term_cohort_id')
                                ->label('Student section')
                                ->options($cohortOptions)
                                ->required()
                                ->searchable()
                                ->distinct()
                                ->live()
                                ->afterStateUpdated(function (mixed $state, Set $set, Get $get) use ($cohortCountsById): void {
                                    $cohortCount = $cohortCountsById[(int) $state] ?? null;
                                    if ($cohortCount !== null && $cohortCount > 0) {
                                        $set('expected_count', $cohortCount);
                                    }

                                    $rows = collect($get('../../cohorts') ?? []);
                                    $total = $rows->sum(fn (array $row): int => max(0, (int) ($row['expected_count'] ?? 0)));
                                    if ($total > 0 && $get('../../capacity') !== null) {
                                        $set('../../capacity', $total);
                                    }
                                }),
                            TextInput::make('expected_count')->label('Students in this class')->numeric()->integer()->minValue(1)->required(),
                        ])
                        ->minItems(1)
                        ->required()
                        ->addActionLabel('Link a student section')
                        ->reorderable(false)
                        ->extraAttributes(['class' => 'tala-cohort-repeater'])
                        ->columns(2),
                    TextInput::make('additional_authority_reference')
                        ->label('Additional class approval reference')
                        ->helperText('Required for an externally approved Additional class.')
                        ->maxLength(255)
                        ->visible(function (Get $get) use ($record): bool {
                            $sectionId = (int) ($get('section_id') ?? $record?->id ?? 0);
                            if ($sectionId <= 0) {
                                return false;
                            }

                            if ($record instanceof Section && (int) $record->id === $sectionId) {
                                return $record->source === Section::SourceAdditional;
                            }

                            return Section::query()->whereKey($sectionId)->value('source') === Section::SourceAdditional;
                        })
                        ->required(function (Get $get) use ($record): bool {
                            $sectionId = (int) ($get('section_id') ?? $record?->id ?? 0);
                            if ($sectionId <= 0) {
                                return false;
                            }

                            if ($record instanceof Section && (int) $record->id === $sectionId) {
                                return $record->source === Section::SourceAdditional;
                            }

                            return Section::query()->whereKey($sectionId)->value('source') === Section::SourceAdditional;
                        }),
                ];
            })
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $term = $this->selectedTerm();
                if ($this->isTermClosed($term) || $this->hasPublishedTimetable($term)) {
                    throw ValidationException::withMessages([
                        'section_id' => 'Classes cannot be confirmed after the official timetable is published or the term is closed.',
                    ]);
                }
                $counts = collect($data['cohorts'])->mapWithKeys(fn (array $row): array => [(int) $row['term_cohort_id'] => (int) $row['expected_count']])->all();
                app(ConfirmClassOffering::class)->execute(
                    Section::query()->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $this->termId))->findOrFail((int) $data['section_id']),
                    $actor, $counts, $data['additional_authority_reference'] ?? null,
                );
                Notification::make()->title('Class confirmed')->success()->send();
            });
    }

    /** @return array{ready: bool, reason: ?string, tab?: string, blockers?: list<array{code: string, reason: string, label: string, url: string}>} */
    public function generationReadiness(): array
    {
        $term = $this->selectedTerm();

        if (! $term) {
            return [
                'ready' => false,
                'reason' => 'Choose a term first.',
                'blockers' => [],
            ];
        }

        $readiness = app(ReadyTermPlanningProjection::class)->forTerm($term);
        $isAcademicHead = (bool) auth()->user()?->hasRole(User::StaffRoleAcademicHead);
        $blockers = [];

        foreach ($readiness['blockers'] as $blocker) {
            $tab = match ($blocker['code']) {
                'calendar_not_active' => 'overview',
                'classes_not_confirmed' => 'classes',
                default => 'resources',
            };

            $url = match ($tab) {
                'resources' => TermResources::getUrl(array_filter([
                    'termId' => $term->id,
                    'resourceView' => in_array($blocker['code'], ['rooms_unavailable', 'rooms_unsuitable'], true) ? 'rooms' : null,
                ], fn (mixed $value): bool => $value !== null)),
                default => TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $tab]),
            };

            $blockers[] = [
                'code' => $blocker['code'],
                'reason' => match ($blocker['code']) {
                    'calendar_not_active' => 'Activate the term calendar before generating a timetable.',
                    'classes_not_confirmed' => 'Add and confirm classes for this term before generating a timetable.',
                    default => $blocker['reason'],
                },
                'label' => match ($tab) {
                    'overview' => 'Term calendar',
                    'classes' => 'Classes & sections',
                    default => 'Faculty & rooms',
                },
                'url' => $url,
                '_tab' => $tab,
            ];
        }

        $demands = SchedulingDemand::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id));
        $resourceFindingKeys = [
            'missing_active_faculty_qualification',
            'missing_suitable_room',
            'missing_default_faculty_load',
            'fixed_faculty_not_qualified',
            'fixed_room_not_suitable',
        ];

        $hasClassesNotConfirmed = collect($blockers)->contains(
            fn (array $blocker): bool => $blocker['code'] === 'classes_not_confirmed',
        );

        if (! (clone $demands)->exists()) {
            if (! $hasClassesNotConfirmed) {
                $blockers[] = [
                    'code' => 'scheduling_demands_missing',
                    'reason' => $isAcademicHead
                        ? 'No weekly meeting requirements are recorded for this term’s classes.'
                        : 'Add and confirm classes to prepare weekly meeting requirements for this term.',
                    'label' => 'Classes & sections',
                    'url' => TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'classes']),
                    '_tab' => 'classes',
                ];
            }
        } elseif ((clone $demands)->where('validation_state', '!=', SchedulingDemand::ValidationReadyForReview)->exists()) {
            $unvalidatedDemands = (clone $demands)
                ->where('validation_state', '!=', SchedulingDemand::ValidationReadyForReview)
                ->get();
            $findingKeys = $unvalidatedDemands
                ->flatMap(fn (SchedulingDemand $demand): array => array_column($demand->readinessFindings(), 'key'))
                ->unique()
                ->values();
            $onlyResourceFindings = $findingKeys->isNotEmpty()
                && $findingKeys->every(fn (string $key): bool => in_array($key, $resourceFindingKeys, true));
            $alreadyCoveredByResourceBlocker = $onlyResourceFindings
                && collect($blockers)->contains(fn (array $blocker): bool => in_array($blocker['code'], ['faculty_qualifications_missing', 'rooms_unavailable', 'rooms_unsuitable'], true));

            if (! $alreadyCoveredByResourceBlocker) {
                $targetTab = $onlyResourceFindings ? 'resources' : 'classes';
                $isRoomOnly = $findingKeys->isNotEmpty()
                    && $findingKeys->every(fn (string $key): bool => in_array($key, ['missing_suitable_room', 'fixed_room_not_suitable'], true));
                $blockers[] = [
                    'code' => 'scheduling_demands_unvalidated',
                    'reason' => $isAcademicHead
                        ? 'Recorded weekly meeting requirements have unresolved validation.'
                        : ($onlyResourceFindings
                            ? 'Update faculty and rooms before generating a timetable.'
                            : 'Resolve the recorded weekly meeting requirements before generating.'),
                    'label' => $onlyResourceFindings ? 'Faculty & rooms' : 'Classes & sections',
                    'url' => $onlyResourceFindings
                        ? TermResources::getUrl(array_filter(['termId' => $term->id, 'resourceView' => $isRoomOnly ? 'rooms' : null], fn (mixed $value): bool => $value !== null))
                        : TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $targetTab]),
                    '_tab' => $targetTab,
                ];
            }
        }

        if (ScheduleGenerationRun::query()->where('term_id', $term->id)->whereIn('status', [ScheduleGenerationRun::StatusQueued, ScheduleGenerationRun::StatusDispatching])->exists()) {
            $blockers[] = [
                'code' => 'generation_in_progress',
                'reason' => 'A timetable is already being generated for this term.',
                'label' => 'Timetable',
                'url' => TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'generate']),
                '_tab' => 'generate',
            ];
        }

        if ($blockers === []) {
            return [
                'ready' => true,
                'reason' => null,
                'blockers' => [],
            ];
        }

        return [
            'ready' => false,
            'reason' => $blockers[0]['reason'],
            'tab' => $blockers[0]['_tab'],
            'blockers' => array_map(
                static fn (array $blocker): array => [
                    'code' => $blocker['code'],
                    'reason' => $blocker['reason'],
                    'label' => $blocker['label'],
                    'url' => $blocker['url'],
                ],
                $blockers,
            ),
        ];
    }

    public function generateTimetableAction(): Action
    {
        return Action::make('generateTimetable')
            ->label('Generate timetable')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Generate timetable')

            ->modalWidth('5xl')
            ->stickyModalHeader()
            ->stickyModalFooter()

            ->modalDescription(function (): string {

                $term = $this->termId ? Term::query()->with('academicYear')->find($this->termId) : null;

                $label = $term
                    ? trim($term->typeLabel().($term->academicYear ? ' · '.$term->academicYear->label : ''))
                    : 'the selected term';

                return "Create a draft for all recorded classes in {$label}. Review and publication follow separately.";

            })

            ->modalContent(fn () => view('filament.pages.partials.term-generation-scope', ['coverage' => $this->termId ? app(TermCoverageProjection::class)->forTerm(Term::findOrFail($this->termId)) : null]))

            ->disabled(fn (): bool => ! $this->generationReadiness()['ready'])

            ->tooltip(fn (): ?string => $this->generationReadiness()['reason'])

            ->modalSubmitActionLabel('Generate draft timetable')

            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->termId !== null
                && ! $this->isTermClosed()
                && ! ($this->hasPublishedTimetable() && $this->hasOfficialStudentEnrollments())
                && $this->retainedCandidateNextStep() === null
                && ! $this->canRecoverActiveRun())

            ->action(function (): void {

                $actor = auth()->user();

                abort_unless($actor instanceof User, 403);

                try {

                    $term = Term::query()->findOrFail($this->termId);

                    if ($this->isTermClosed($term)) {
                        throw ValidationException::withMessages([
                            'term' => 'This term is closed and cannot generate a new timetable.',
                        ]);
                    }

                    if ($this->hasPublishedTimetable($term) && $this->hasOfficialStudentEnrollments($term)) {
                        throw ValidationException::withMessages([
                            'term' => 'Full timetable regeneration is blocked because active official student enrollments exist. Use Revise official schedule instead.',
                        ]);
                    }

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

                        ->body("Attempt #{$run->id} captured the current ready requirements. Its status refreshes automatically.")

                        ->success()

                        ->send();

                } catch (ValidationException $exception) {

                    $message = collect($exception->errors())->flatten()->first();
                    $plain = TimetableFailureLanguage::forBlockedGeneration(is_string($message) ? $message : '');

                    $this->generationFailure = [
                        'title' => $plain['title'],
                        'explanation' => $plain['explanation'],
                        'nextStep' => $plain['nextStep'],
                        'technical' => is_string($message) ? $message : 'Review the Schedule Requirement findings and try again.',
                    ];
                    $this->dispatch('open-modal', id: 'timetable-generation-failure');

                } catch (Throwable $exception) {

                    report($exception);
                    $plain = TimetableFailureLanguage::fallback();

                    $this->generationFailure = [
                        'title' => $plain['title'],
                        'explanation' => $plain['explanation'],
                        'nextStep' => $plain['nextStep'],
                        'technical' => $exception->getMessage(),
                    ];
                    $this->dispatch('open-modal', id: 'timetable-generation-failure');

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
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()

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
                    ->title('Draft timetable accepted')
                    ->body('Ready to publish when you are. Students and faculty cannot see it yet.')
                    ->success()
                    ->send();

            });

    }

    public function rejectCandidateAction(): Action
    {
        return Action::make('rejectCandidate')
            ->label('Discard draft')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Discard this draft timetable?')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Discard draft')
            ->modalIcon(Heroicon::OutlinedXMark)
            ->modalDescription(fn (): string => $this->selectedTerm()->label.' · Attempt #'.$this->activeScheduleRun()?->id.'. Discarding this draft timetable unlocks generating a fresh timetable attempt. This attempt stays in history and cannot be published.')
            ->schema([
                Textarea::make('candidate_review_reason')
                    ->label('Reason for discarding')
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
                    ->title('Draft timetable discarded')
                    ->body("Attempt #{$run->id} was discarded. You can now adjust inputs or generate a fresh timetable attempt.")
                    ->info()
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
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()

            ->modalDescription(fn (): string => $this->selectedTerm()->label.' · Attempt #'.$this->activeScheduleRun()?->id.'. '.($this->canRecoverActiveRun()
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

            ->color('primary')

            ->requiresConfirmation()

            ->modalHeading('Publish official timetable')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()

            ->modalDescription(fn (): string => $this->selectedTerm()->label.' · Attempt #'.$this->activeScheduleRun()?->id.'. '.$this->publicationModalDescription())

            ->modalSubmitActionLabel('Publish official timetable')

            ->schema([
                Textarea::make('publication_note')
                    ->label('Publication note')
                    ->maxLength(2000)
                    ->required(fn (): bool => $this->publicationReasonRequirement() !== null)
                    ->helperText(fn (): string => $this->publicationReasonRequirement()
                        ?? 'Optional for optimal candidate without quality warnings. TALA records the publishing Registrar and time automatically.'),
            ])

            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->canPublishActiveRun())

            ->action(function (array $data): void {

                $actor = auth()->user();

                abort_unless($actor instanceof User, 403);

                $run = $this->activeScheduleRun();

                abort_unless($run instanceof ScheduleGenerationRun, 404);

                try {

                    $pinCommitments = SchedulingCommitment::query()
                        ->where('term_id', $run->term_id)
                        ->where(function ($query): void {
                            $query->where('authority_reference', 'REGISTRAR-PIN')
                                ->orWhere('authority_reference', 'like', 'REGISTRAR-PIN:%');
                        })
                        ->get()
                        ->map(fn (SchedulingCommitment $c): array => $c->only([
                            'term_id', 'section_id', 'faculty_user_id', 'room_id', 'day_of_week',
                            'starts_at', 'ends_at', 'authority_reference', 'reason', 'recorded_by',
                        ]))
                        ->all();

                    if ($pinCommitments !== []) {
                        SchedulingCommitment::query()
                            ->where('term_id', $run->term_id)
                            ->where(function ($query): void {
                                $query->where('authority_reference', 'REGISTRAR-PIN')
                                    ->orWhere('authority_reference', 'like', 'REGISTRAR-PIN:%');
                            })
                            ->delete();
                    }

                    try {
                        if ($run->contract_version === ScheduleGenerationRun::ContractVersion
                            && $run->candidate_state !== 'Accepted') {
                            $acceptReason = filled($data['publication_note'] ?? null)
                                ? (string) $data['publication_note']
                                : 'Approved for official timetable publication.';
                            $run = app(ReviewTimetableCandidate::class)->accept($run, $actor, $acceptReason);
                        }

                        app(SchedulePublishService::class)->publish(
                            $run,
                            $actor,
                            $data['publication_note'] ?? null,
                            authorityReference: null,
                        );
                    } finally {
                        foreach ($pinCommitments as $commitmentPayload) {
                            SchedulingCommitment::query()->create($commitmentPayload);
                        }
                    }

                    $this->viewTab = 'published';

                    $this->selectedVersionId = null;

                    if ($this instanceof TermTimetable) {
                        $this->timetableState = 'official';
                    }

                    Notification::make()
                        ->title('Official timetable published')
                        ->body("The official timetable is now active for {$this->selectedTerm()->label}.")
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
            && ! $this->isTermClosed()
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
            default => 'Review or discard this retained draft before starting another attempt.',
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
        if ($this->isTermClosed() || $this->hasOfficialStudentEnrollments()) {
            return false;
        }

        $actor = auth()->user();

        $run = $this->activeScheduleRun();

        if (! $actor instanceof User
            || ! $run instanceof ScheduleGenerationRun
            || ! Gate::forUser($actor)->allows('publish', $run)
            || ! in_array($run->status, ScheduleGenerationRun::publishableStatuses(), true)
            || in_array($run->candidate_state, ['Rejected', 'Stale', 'Superseded'], true)) {
            return false;
        }

        if ($run->canBePublished()) {
            return true;
        }

        $summary = $run->publicationSummary();

        return $summary['assignments'] > 0 && $summary['conflicts'] === 0;

    }

    private function publicationModalDescription(): string
    {

        $run = $this->activeScheduleRun();

        if (! $run instanceof ScheduleGenerationRun) {

            return '';

        }

        $summary = $run->publicationSummary();

        $impact = app(SchedulePublicationImpactService::class)->preview($run);

        $summaryParts = array_filter([
            sprintf('%d %s', $summary['assignments'], Str::plural('class meeting', $summary['assignments'])),
            $summary['warnings'] > 0 ? sprintf('%d %s', $summary['warnings'], Str::plural('warning', $summary['warnings'])) : null,
            $summary['conflicts'] > 0 ? sprintf('%d %s', $summary['conflicts'], Str::plural('conflict', $summary['conflicts'])) : '0 conflicts',
        ]);

        $description = sprintf(
            '%s. Impact: %d new · %d changed · %d removed · %d affected faculty. Publishing makes this the official timetable.',
            implode(' · ', $summaryParts),
            $impact->newAssignments(),
            $impact->changedAssignments(),
            $impact->removedAssignments(),
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

    public function selectTerm(int|string|null $termId): void
    {
        if (! filled($termId)) {
            return;
        }

        $resolvedId = (int) $termId;
        abort_unless(Term::query()->whereKey($resolvedId)->exists(), 404);

        $this->termId = $resolvedId;
        $this->termAutoSelected = false;
        $this->termAutoSelectBasis = null;
        $this->viewTab = static::$planningTab;
        $this->selectedRunId = null;
        $this->selectedVersionId = null;
        $this->resetCandidateFilters();

        if (static::class !== self::class) {
            $this->redirect(static::getUrl(['termId' => $resolvedId]));
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

            return ['type' => $type, 'label' => $label, 'window' => $window, 'status' => ! $bounds ? 'Not set' : ($at->lt($bounds['opens_at']) ? 'Upcoming' : ($at->gt($bounds['closes_at']) ? 'Closed' : 'Open'))];

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

        $isLiveTerm = $term instanceof Term && $this->hasPublishedTimetable($term);
        $isClosedTerm = $term instanceof Term && $this->isTermClosed($term);
        $confirmedOfferings = $isLiveTerm
            ? $offerings->filter(fn (TermOffering $offering): bool => $offering->sections
                ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])
                ->whereNotNull('confirmed_at')
                ->isNotEmpty())
            : $offerings;
        $qualificationScopeOfferings = $isLiveTerm && $confirmedOfferings->isNotEmpty()
            ? $confirmedOfferings
            : $offerings;

        $courseIds = $qualificationScopeOfferings->map(fn (TermOffering $offering) => $offering->course()?->id)->filter()->unique();

        $courseTitles = $offerings->groupBy(fn (TermOffering $offering): ?int => $offering->course()?->id)
            ->map(fn (Collection $courseOfferings): string => $courseOfferings->pluck('curriculumEntry.courseSpecification.title')->filter()->unique()->implode(' / '));

        $qualifications = FacultyQualification::query()->active()->whereIn('course_id', $courseIds)->with(['faculty', 'course'])->get();

        $availabilityProjection = $term instanceof Term ? app(FacultyAvailabilityStatusProjection::class)->forTerm($term) : null;

        $facultyCourseCodes = $qualifications->groupBy('faculty_user_id')
            ->map(fn (Collection $items): array => $items->pluck('course.code')->filter()->unique()->values()->all());

        $facultyRecords = $availabilityProjection
            ? collect($availabilityProjection['faculty_rows'])->map(fn (array $row): array => [
                'faculty' => $row['faculty'],
                'courses' => $facultyCourseCodes->get($row['faculty_user_id'], []),
                'course_labels' => $row['course_labels'],
                'declaration' => $row['declaration'],
            ])->values()
            : collect();

        $rooms = Room::query()->where('is_active', true)->orderBy('code')->get();

        $needsAttention = [];
        $missingQualifications = $courseIds->diff($qualifications->pluck('course_id')->unique())->count();
        $missingDeclarations = $availabilityProjection['no_response_count'] ?? 0;

        if (! $isLiveTerm && ! $isClosedTerm) {
            if (! $activePackage || $draftPackages->isNotEmpty()) {
                $needsAttention[] = ['tab' => 'overview', 'title' => $draftPackages->isNotEmpty() ? 'Review the pending calendar' : 'Set the term calendar', 'detail' => 'Instruction dates and teaching hours establish the source for class planning.'];
            }

            if ($offerings->isEmpty() || $sourceClasses->isEmpty() || $contextClasses->whereNotNull('confirmed_at')->count() < $sourceClasses->count()) {
                $needsAttention[] = ['tab' => 'classes', 'title' => $sourceClasses->isEmpty() ? 'Choose subjects and add classes' : 'Complete class preparation', 'detail' => 'Each class needs its approved subject details and confirmed students.'];
            }

            if ($missingQualifications > 0 || ($sourceClasses->contains(fn (Section $class): bool => $class->termOffering->modality === TermOffering::ModalityFaceToFace) && $rooms->isEmpty())) {
                $needsAttention[] = ['tab' => 'resources', 'title' => 'Review faculty and rooms', 'detail' => $missingQualifications.' subject(s) without an eligible faculty record.'];
            }

            if ($demands->isEmpty() && $sourceClasses->isNotEmpty()) {
                $needsAttention[] = ['tab' => 'classes', 'title' => 'Prepare the meeting requirements', 'detail' => 'Requirements connect each class to the meetings that generation must place.'];
            }
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

            'availabilityProjection' => $availabilityProjection,

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

            'facultyDeadline' => $facultyDeadlineSource?->due_at,

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
