<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasAcademicOversightReturn;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\Programs\Tables\ProgramsTable;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

final class CatalogCurriculaWorkbench extends Page implements HasTable
{
    use HasAcademicOversightReturn;
    use InteractsWithTable {
        handleTableFilterUpdates as protected baseHandleTableFilterUpdates;
    }

    #[Url]
    public ?int $programId = null;

    #[Url(as: 'tab')]
    public string $activeTab = 'draft';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'termId')]
    public ?int $selectedTermId = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Academic Planning';

    protected static ?string $navigationLabel = 'Programs & Curricula';

    protected static ?string $title = 'Programs & Curricula';

    protected string $view = 'filament.pages.catalog-curricula-workbench';

    /** @var array<string, array<string, mixed>> */
    private array $cardDataCache = [];

    /** @var array<int, list<int>>|null */
    private ?array $runningYearLevelsCache = null;

    private ?int $runningYearLevelsTermId = null;

    /** @var array<int, int>|null */
    private ?array $enrolledCountsCache = null;

    /** @var array<int, array{batches: int, students: int}>|null */
    private ?array $continuingCountsCache = null;

    private ?bool $hasProgramsCache = null;

    /** @var Collection<int, Term>|null */
    private ?Collection $termsCache = null;

    public function mount(): void
    {
        if ($this->programId !== null) {
            $this->redirect(ProgramResource::getUrl('view', ['record' => $this->programId, ...$this->oversightQueryParameters()]));

            return;
        }

        if ($this->isReadOnly() && ! \Filament\Support\original_request()->query->has('tab')) {
            $this->activeTab = 'active';
        }

        $this->activeTab = $this->currentTab();

        if (! in_array($this->statusFilter, ['all', 'active', 'has_drafts', 'inactive'], true)) {
            $this->statusFilter = 'all';
        }

        if ($this->statusFilter !== 'all' && blank($this->tableFilters['status']['value'] ?? null)) {
            $this->tableFilters['status']['value'] = $this->statusFilter;
        }

        $terms = $this->availableTerms();
        $defaultTerm = $this->resolveDefaultReferenceTerm($terms);
        $this->selectedTermId = $defaultTerm?->id;

        if ($defaultTerm instanceof Term && blank($this->tableFilters['term_id']['value'] ?? null)) {
            $this->tableFilters['term_id']['value'] = (string) $defaultTerm->id;
        }
    }

    public function switchTab(string $tab): void
    {
        if (in_array($tab, ['draft', 'active'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function setStatusFilter(string $status): void
    {
        if (in_array($status, ['all', 'active', 'has_drafts', 'inactive'], true)) {
            $this->statusFilter = $status;
            $this->tableFilters['status']['value'] = $status === 'all' ? null : $status;
            $this->resetPage();
            $this->resetCardCaches();
        }
    }

    public function resetWorkbenchFilters(): void
    {
        $this->statusFilter = 'all';
        $this->selectedTermId = $this->resolveBaselineDefaultTerm()?->id;
        $this->resetTableSearch();
        $this->resetTableFiltersForm();
        $this->resetPage();
        $this->resetCardCaches();
    }

    protected function handleTableFilterUpdates(): void
    {
        $this->baseHandleTableFilterUpdates();

        $rawTermId = $this->getTableFilterState('term_id')['value'] ?? null;

        if (filled($rawTermId)) {
            $this->selectedTermId = (int) $rawTermId;
        } else {
            $defaultTermId = $this->resolveBaselineDefaultTerm()?->id;
            $this->selectedTermId = $defaultTermId;

            if ($defaultTermId !== null) {
                $this->tableFilters['term_id']['value'] = (string) $defaultTermId;
            }
        }

        $rawStatus = $this->getTableFilterState('status')['value'] ?? null;
        $this->statusFilter = in_array($rawStatus, ['active', 'has_drafts', 'inactive'], true)
            ? (string) $rawStatus
            : 'all';

        $this->resetCardCaches();
    }

    public function getSubheading(): ?string
    {
        if ($this->isReadOnly()) {
            return 'Read-only oversight of active and draft program study plans maintained by the Registrar.';
        }

        return 'Prepare each program’s study plan and activate it for incoming students.';
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->returnToOversightAction('Academic Oversight')
                ->visible(fn (): bool => $this->isReadOnly()),
        ];
    }

    /**
     * @return array<string|int, string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        $terms = $this->availableTerms();
        $baselineTermId = $this->resolveBaselineDefaultTerm($terms)?->id;
        $baselineTermKey = $baselineTermId !== null ? (string) $baselineTermId : null;
        $termOptions = $terms
            ->mapWithKeys(fn (Term $term): array => [(string) $term->id => $this->formatTermOptionLabel($term)])
            ->all();

        $filters = [
            SelectFilter::make('status')
                ->label('Status')
                ->placeholder('All programs')
                ->options([
                    'active' => 'Active',
                    'has_drafts' => 'Has drafts',
                    'inactive' => 'Inactive',
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'active' => $query->where('is_active', true),
                        'inactive' => $query->where('is_active', false),
                        'has_drafts' => $query->whereHas(
                            'curriculumVersions',
                            fn (Builder $versions): Builder => $versions->where('state', CurriculumVersion::StateDraft),
                        ),
                        default => $query,
                    };
                }),
        ];

        if ($termOptions !== []) {
            $filters[] = SelectFilter::make('term_id')
                ->label('Term')
                ->options($termOptions)
                ->default($baselineTermKey)
                ->resetState(['value' => $baselineTermKey])
                ->selectablePlaceholder(false)
                ->query(fn (Builder $query): Builder => $query)
                ->indicateUsing(function (array $data) use ($termOptions, $baselineTermKey): ?string {
                    $termKey = filled($data['value'] ?? null) ? (string) $data['value'] : null;

                    if ($termKey === null || $termKey === $baselineTermKey || ! isset($termOptions[$termKey])) {
                        return null;
                    }

                    return 'Term: '.$termOptions[$termKey];
                });
        }

        return $table
            ->query(function (): Builder {
                return Program::query()
                    ->with(['curriculumVersions.academicYears'])
                    ->orderByDesc('is_active')
                    ->orderBy('code');
            })
            ->columns([
                Stack::make([
                    ViewColumn::make('card')
                        ->view('filament.pages.partials.program-workbench-card'),
                ]),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->paginated([6, 12, 24])
            ->defaultPaginationPageOption(12)
            ->searchable(['code', 'name'])
            ->searchPlaceholder('Search code or name')
            ->filters($filters, layout: FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->size('sm'))
            ->filtersResetAction(fn (Action $action): Action => $action->visible(fn (): bool => $this->hasStatusFilterSelected() || ($this->selectedTermId !== $this->resolveBaselineDefaultTerm()?->id)))
            ->deferFilters(false)
            ->columnManager(false)
            ->extraAttributes(fn (): array => [
                'class' => trim('tala-data-table tala-program-workbench-table'.($this->hasAnyPrograms() ? '' : ' tala-program-workbench-table--empty')),
            ])
            ->emptyState(fn (Table $table): HtmlString => $this->renderEmptyState($table))
            ->emptyStateHeading(fn (): string => $this->hasFilteredCriteria()
                ? 'No matching programs'
                : ($this->isReadOnly() ? 'No programs recorded yet' : 'Set up your first program'))
            ->emptyStateDescription(fn (): string => $this->hasFilteredCriteria()
                ? 'Try a different search keyword or switch the status filter.'
                : ($this->isReadOnly()
                    ? 'Programs and study plans appear here once the Registrar creates them.'
                    : 'Add a degree or diploma program first, then build its study plans across batches and year levels.'))
            ->emptyStateActions([
                $this->configureCreateProgramAction(Action::make('emptyCreateProgram'))
                    ->visible(fn (): bool => ! $this->hasAnyPrograms()
                        && ! $this->isReadOnly()
                        && (auth()->user()?->can('create', Program::class) ?? false)),
                Action::make('emptySubjectCatalog')
                    ->label('Subject catalog')
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->color('gray')
                    ->url(fn (): string => CourseResource::getUrl('index', $this->oversightQueryParameters()))
                    ->visible(fn (): bool => ! $this->hasAnyPrograms() && ! $this->isReadOnly()),
            ])
            ->toolbarActions([
                $this->configureCreateProgramAction(Action::make('createProgram'))
                    ->size('sm'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function cardDataFor(Program $program): array
    {
        $referenceTerm = $this->resolveReferenceTerm();
        $referenceTermId = $referenceTerm?->id;
        $cacheKey = ($referenceTermId ?? 0).':'.$program->id;

        if (isset($this->cardDataCache[$cacheKey])) {
            return $this->cardDataCache[$cacheKey];
        }

        if ($this->runningYearLevelsCache === null || $this->runningYearLevelsTermId !== $referenceTermId) {
            $pageProgramIds = $this->currentPageProgramIds($program);
            $this->runningYearLevelsTermId = $referenceTermId;
            $this->runningYearLevelsCache = $this->resolveRunningYearLevelsByProgram(
                $pageProgramIds,
                $referenceTermId,
            );
            $this->enrolledCountsCache = $this->resolveOfficialEnrolledCountsByProgram(
                $pageProgramIds,
                $referenceTermId,
            );
            $this->continuingCountsCache = ProgramsTable::continuingBatchAndStudentCountsByProgram($pageProgramIds);
        }

        $query = $this->oversightQueryParameters();
        $availability = $program->admissionsAvailability();
        $enrolledCount = $this->enrolledCountsCache[$program->id]
            ?? $program->officialEnrolledStudentsCount($referenceTermId);
        $runningYears = $this->runningYearLevelsCache[$program->id] ?? [];

        $inactiveNote = null;

        if (! $program->is_active) {
            $continuing = $this->continuingCountsCache[$program->id]
                ?? ProgramsTable::continuingBatchAndStudentCounts($program);
            $continuingStudents = max($enrolledCount, $continuing['students']);

            if ($continuingStudents > 0) {
                $batches = max(1, $continuing['batches']);
                $inactiveNote = sprintf(
                    'No new batches. %d %s still enrolled.',
                    $batches,
                    Str::plural('batch', $batches),
                );
            } else {
                $inactiveNote = 'Inactive, no students';
            }
        }

        $activeCurriculaCount = $program->curriculumVersions
            ->where('state', CurriculumVersion::StateActive)
            ->count();
        $draftCurriculaCount = $program->curriculumVersions
            ->where('state', CurriculumVersion::StateDraft)
            ->count();

        return $this->cardDataCache[$cacheKey] = [
            'id' => $program->id,
            'code' => (string) $program->code,
            'name' => (string) $program->name,
            'is_active' => (bool) $program->is_active,
            'duration_years' => (int) ($program->duration_years ?: 4),
            'running_years_label' => $this->formatRunningYearLevels($runningYears),
            'running_years_count' => count($runningYears),
            'enrolled_count' => $enrolledCount,
            'availability' => $availability,
            'inactive_note' => $inactiveNote,
            'active_curricula_count' => $activeCurriculaCount,
            'draft_curricula_count' => $draftCurriculaCount,
            'total_curricula_count' => $program->curriculumVersions->count(),
            'details_url' => ProgramResource::getUrl('view', ['record' => $program, ...$query]),
            'curricula_url' => ProgramResource::getUrl('view', ['record' => $program, 'tab' => 'curricula', ...$query]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $referenceTerm = $this->resolveReferenceTerm();

        return [
            'readOnly' => $this->isReadOnly(),
            'referenceTermLabel' => $referenceTerm instanceof Term
                ? $this->formatTermOptionLabel($referenceTerm)
                : null,
        ];
    }

    private function configureCreateProgramAction(Action $action): Action
    {
        return $action
            ->label('Add program')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->visible(fn (): bool => ! $this->isReadOnly() && (auth()->user()?->can('create', Program::class) ?? false))
            ->authorize(fn (): bool => ! $this->isReadOnly() && (auth()->user()?->can('create', Program::class) ?? false))
            ->modalHeading('Add program')
            ->modalDescription('Create a degree or diploma program, then set up its study plans and batches.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Add program')
            ->modalCancelActionLabel('Cancel')
            ->schema([
                Grid::make(['default' => 1, 'sm' => 4])
                    ->schema([
                        TextInput::make('code')
                            ->label('Program code')
                            ->placeholder('e.g., BSIT')
                            ->required()
                            ->maxLength(64)
                            ->unique(table: Program::class, column: 'code')
                            ->columnSpan(['default' => 1, 'sm' => 1])
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),
                        TextInput::make('name')
                            ->label('Program name')
                            ->placeholder('e.g., Bachelor of Science in Information Technology')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['default' => 1, 'sm' => 3]),
                        TextInput::make('duration_years')
                            ->label('Max year')
                            ->integer()
                            ->default(4)
                            ->minValue(1)
                            ->maxValue(5)
                            ->step(1)
                            ->required()
                            ->columnSpan(['default' => 1, 'sm' => 2])
                            ->helperText('Program length from 1 to 5 years.'),
                        Textarea::make('description')
                            ->label('Program description')
                            ->placeholder('e.g., Prepares students for software development, networking, and IT systems administration.')
                            ->helperText('Displayed to prospective applicants on the public school page and program overview.')
                            ->maxLength(2000)
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->can('create', Program::class) && ! $this->isReadOnly(), 403);

                $program = Program::query()->create([
                    'code' => strtoupper(trim((string) $data['code'])),
                    'name' => trim((string) $data['name']),
                    'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
                    'duration_years' => (int) $data['duration_years'],
                    'is_active' => true,
                ]);

                activity()
                    ->performedOn($program)
                    ->causedBy($actor)
                    ->event('program_created')
                    ->withProperties([
                        'program_code' => $program->code,
                        'program_name' => $program->name,
                        'duration_years' => $program->duration_years,
                    ])
                    ->log('Program created');

                Notification::make()
                    ->title("{$program->code} added")
                    ->body('You can now add its first curriculum.')
                    ->success()
                    ->send();

                $this->redirect(ProgramResource::getUrl('view', [
                    'record' => $program,
                    'tab' => 'curricula',
                    ...$this->oversightQueryParameters(),
                ]));
            });
    }

    private function renderEmptyState(Table $table): HtmlString
    {
        $filtered = $this->hasFilteredCriteria();
        $hasSearch = $this->hasTableSearch();
        $hasStatusFilter = $this->hasStatusFilterSelected();
        $heading = $table->getEmptyStateHeading();
        $description = $table->getEmptyStateDescription();
        $actions = $table->getEmptyStateActions();

        return new HtmlString(Blade::render(<<<'BLADE'
            <x-servitech-empty-state
                :heading="$heading"
                :description="$description"
                :illustrated="! $filtered"
                :icon="$filtered ? 'heroicon-o-magnifying-glass' : 'heroicon-o-document-text'"
            >
                @if ($filtered)
                    @if ($hasSearch)
                        <x-filament::button color="gray" wire:click="resetTableSearch" icon="heroicon-m-x-mark">
                            Clear search
                        </x-filament::button>
                    @endif
                    @if ($hasStatusFilter)
                        <x-filament::button color="gray" wire:click="resetWorkbenchFilters" icon="heroicon-m-arrow-path">
                            Clear filters
                        </x-filament::button>
                    @endif
                @else
                    @foreach ($actions as $action)
                        @if ($action->isVisible())
                            {{ $action }}
                        @endif
                    @endforeach
                @endif
            </x-servitech-empty-state>
        BLADE, compact('heading', 'description', 'filtered', 'hasSearch', 'hasStatusFilter', 'actions')));
    }

    private function hasAnyPrograms(): bool
    {
        return $this->hasProgramsCache ??= Program::query()->exists();
    }

    private function hasStatusFilterSelected(): bool
    {
        return filled($this->getTableFilterState('status')['value'] ?? null) || $this->statusFilter !== 'all';
    }

    private function hasFilteredCriteria(): bool
    {
        return $this->hasTableSearch() || $this->hasStatusFilterSelected();
    }

    /**
     * @return Collection<int, Term>
     */
    private function availableTerms(): Collection
    {
        if ($this->termsCache !== null) {
            return $this->termsCache;
        }

        $terms = Term::query()
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        if ($this->oversightTermId !== null && ! $terms->contains('id', $this->oversightTermId)) {
            $oversightTerm = Term::query()->with('academicYear')->find($this->oversightTermId);
            if ($oversightTerm instanceof Term) {
                $terms->push($oversightTerm);
            }
        }

        return $this->termsCache = $terms;
    }

    private function formatTermOptionLabel(Term $term): string
    {
        $label = (string) $term->label;

        if (filled($term->academicYear?->label)) {
            $label .= ' · '.$term->academicYear->label;
        }

        return $label;
    }

    /**
     * @param  Collection<int, Term>|null  $terms
     */
    private function resolveBaselineDefaultTerm(?Collection $terms = null): ?Term
    {
        $terms ??= $this->availableTerms();
        $oversightTermId = $this->resolvedOversightTermId();

        if ($oversightTermId !== null) {
            $oversightTerm = $terms->firstWhere('id', $oversightTermId);

            if ($oversightTerm instanceof Term) {
                return $oversightTerm;
            }
        }

        return $terms->firstWhere('state', Term::StateActive) ?? $terms->first();
    }

    /**
     * @param  Collection<int, Term>|null  $terms
     */
    private function resolveDefaultReferenceTerm(?Collection $terms = null): ?Term
    {
        $terms ??= $this->availableTerms();

        if ($this->selectedTermId !== null) {
            $selected = $terms->firstWhere('id', $this->selectedTermId);

            if ($selected instanceof Term) {
                return $selected;
            }
        }

        return $this->resolveBaselineDefaultTerm($terms);
    }

    private function resolveReferenceTerm(): ?Term
    {
        $terms = $this->availableTerms();
        $filterTermId = $this->getTableFilterState('term_id')['value'] ?? null;

        if (filled($filterTermId)) {
            $filtered = $terms->firstWhere('id', (int) $filterTermId);

            if ($filtered instanceof Term) {
                return $filtered;
            }
        }

        return $this->resolveDefaultReferenceTerm($terms);
    }

    /**
     * @return list<int>
     */
    private function currentPageProgramIds(Program $fallbackProgram): array
    {
        $records = $this->getTableRecords();
        $items = $records instanceof Paginator ? $records->items() : $records;

        $ids = collect($items)
            ->pluck('id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $ids !== [] ? $ids : [(int) $fallbackProgram->id];
    }

    private function resetCardCaches(): void
    {
        $this->cardDataCache = [];
        $this->runningYearLevelsCache = null;
        $this->runningYearLevelsTermId = null;
        $this->enrolledCountsCache = null;
        $this->continuingCountsCache = null;
        $this->hasProgramsCache = null;
    }

    /**
     * @param  list<int>  $programIds
     * @return array<int, int>
     */
    private function resolveOfficialEnrolledCountsByProgram(array $programIds, ?int $termId): array
    {
        if ($programIds === []) {
            return [];
        }

        $counts = array_fill_keys(array_map('intval', $programIds), 0);
        $resolvedTermId = $termId ?? $this->availableTerms()->firstWhere('state', Term::StateActive)?->id;

        $enrollments = Enrollment::query()
            ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
            ->when($resolvedTermId !== null, fn (Builder $query): Builder => $query->where('term_id', $resolvedTermId))
            ->where(function (Builder $query) use ($programIds): void {
                $query->whereHas('studentProfile', fn (Builder $profiles): Builder => $profiles->whereIn('program_id', $programIds))
                    ->orWhere(function (Builder $fallback) use ($programIds): void {
                        $fallback->whereNull('student_profile_id')
                            ->whereHas('admissionApplication', fn (Builder $apps): Builder => $apps->whereIn('program_id', $programIds));
                    });
            })
            ->with([
                'studentProfile:id,program_id',
                'admissionApplication:id,program_id',
            ])
            ->get(['id', 'student_profile_id', 'admission_application_id']);

        foreach ($enrollments as $enrollment) {
            $programId = $enrollment->student_profile_id !== null
                ? $enrollment->studentProfile?->program_id
                : $enrollment->admissionApplication?->program_id;

            if ($programId !== null && array_key_exists((int) $programId, $counts)) {
                $counts[(int) $programId]++;
            }
        }

        return $counts;
    }

    /**
     * Derive running year levels per program strictly from live TermCohort and Official Enrollment records for the term.
     *
     * @param  list<int>  $programIds
     * @return array<int, list<int>>
     */
    private function resolveRunningYearLevelsByProgram(array $programIds, ?int $termId): array
    {
        if ($programIds === [] || $termId === null) {
            return [];
        }

        /** @var array<int, array<int, true>> $levelsByProgram */
        $levelsByProgram = [];

        $cohorts = TermCohort::query()
            ->where('term_id', $termId)
            ->whereIn('program_id', $programIds)
            ->with(['classOfferings.termOffering.curriculumEntry'])
            ->get();

        foreach ($cohorts as $cohort) {
            $yearLevel = $cohort->resolvedYearLevel();

            if ($yearLevel !== null) {
                $levelsByProgram[(int) $cohort->program_id][$yearLevel] = true;
            }
        }

        // Also capture officially enrolled students in this term who have no TermCohort assignment yet.
        $unassignedEnrollments = Enrollment::query()
            ->where('term_id', $termId)
            ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
            ->whereNull('student_section_id')
            ->whereHas('studentProfile', fn ($q) => $q->whereIn('program_id', $programIds))
            ->with([
                'studentProfile:id,program_id',
                'courseEnrollments.termOffering.curriculumEntry',
            ])
            ->get();

        foreach ($unassignedEnrollments as $enrollment) {
            $programId = $enrollment->studentProfile?->program_id;

            if ($programId === null) {
                continue;
            }

            foreach ($enrollment->courseEnrollments as $courseEnrollment) {
                $parsed = TermCohort::parseYearLevelValue($courseEnrollment->termOffering?->curriculumEntry?->year_level);

                if ($parsed !== null) {
                    $levelsByProgram[(int) $programId][$parsed] = true;
                    break;
                }
            }
        }

        $result = [];

        foreach ($levelsByProgram as $programId => $levelMap) {
            $levels = array_keys($levelMap);
            sort($levels);
            $result[$programId] = $levels;
        }

        return $result;
    }

    /**
     * @param  list<int>  $yearLevels
     */
    private function formatRunningYearLevels(array $yearLevels): string
    {
        if ($yearLevels === []) {
            return 'None this term';
        }

        $ordinals = array_map(
            fn (int $year): string => match ($year) {
                1 => '1st',
                2 => '2nd',
                3 => '3rd',
                default => "{$year}th",
            },
            $yearLevels,
        );

        return implode(', ', $ordinals).' Year';
    }

    private function currentTab(): string
    {
        return in_array($this->activeTab, ['draft', 'active'], true)
            ? $this->activeTab
            : ($this->isReadOnly() ? 'active' : 'draft');
    }

    private function isReadOnly(): bool
    {
        return ! $this->isRegistrarActor();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]);
    }
}
