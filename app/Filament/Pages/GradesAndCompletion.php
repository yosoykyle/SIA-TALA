<?php

namespace App\Filament\Pages;

use App\Actions\Academics\ExaminationPeriodProjection;
use App\Filament\Resources\GradeRosters\Concerns\InteractsWithResultHistory;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\GradeRosters\Tables\GradeRostersTable;
use App\Filament\Resources\GradeRosters\Tables\ReleasedResultsTable;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Pages\Page;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\FiltersResetActionPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

class GradesAndCompletion extends Page implements HasTable
{
    use InteractsWithResultHistory;
    use InteractsWithTable;

    /** @var list<string> */
    private const Tabs = ['overview', 'grade-review', 'inc-corrections', 'academic-progress', 'lifecycle', 'completion-tor'];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Grades & Academic Records';

    protected static ?string $title = 'Grades & Academic Records';

    protected string $view = 'filament.pages.grades-and-completion';

    #[Url]
    public string $viewTab = 'overview';

    /** @var array<string, mixed>|null */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'search')]
    public $tableSearch = '';

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    public ?int $selectedIncResultId = null;

    public function selectIncResult(int $recordId): void
    {
        if ($this->viewTab === 'inc-corrections' && $this->getTableRecords()->contains('id', $recordId)) {
            $this->selectedIncResultId = $recordId;
        }
    }

    public function mount(): void
    {
        if ($this->viewTab === 'history') {
            $this->redirect(self::getUrl(['viewTab' => 'grade-review', ...self::classQueueParameters(request()->query())]));

            return;
        }

        $this->viewTab = in_array($this->viewTab, self::Tabs, true) ? $this->viewTab : 'overview';

        if (in_array($this->viewTab, ['lifecycle', 'completion-tor'], true)) {
            $this->redirect($this->activeWorkArea()['url']);
        }
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false;
    }

    public function getHeader(): ?View
    {
        return $this->viewTab === 'inc-corrections'
            ? view('filament.admin.grade-rosters.inc-header')
            : parent::getHeader();
    }

    /** @return array<string, string> */
    public static function tabs(): array
    {
        return [
            'grade-review' => 'Class grades',
            'inc-corrections' => 'INC & Corrections',
            'academic-progress' => 'Student records',
            'lifecycle' => 'Lifecycle',
            'completion-tor' => 'Completion & TOR',
        ];
    }

    public function showTab(string $tab): void
    {
        if (in_array($tab, self::Tabs, true)) {
            $this->viewTab = $tab;
        }
    }

    /** @return array{title: string, description: string, action: string, url: string, icon: string} */
    public function activeWorkArea(): array
    {
        return $this->workAreas()[$this->viewTab];
    }

    /** @return array<string, mixed> */
    public function examinationPeriod(): array
    {
        return app(ExaminationPeriodProjection::class)->latest();
    }

    public function getTitle(): string
    {
        return match ($this->viewTab) {
            'overview' => 'Grades & Completion',
            'grade-review' => 'Class grades',
            'inc-corrections' => 'INC & corrections',
            default => static::tabs()[$this->viewTab],
        };
    }

    public function getSubheading(): ?string
    {
        return match ($this->viewTab) {
            'overview' => 'Review class grades and maintain student academic records.',
            'grade-review' => 'Find a class to review results or inspect its submissions, releases and changes.',
            'inc-corrections' => 'Released course results across classes and terms.',
            'academic-progress' => 'Find a student’s released grades, averages and enrollment guidance.',
            default => $this->activeWorkArea()['description'],
        };
    }

    public function getBreadcrumbs(): array
    {
        return $this->viewTab === 'overview' ? [] : [self::getUrl() => 'Grades & Completion', $this->getTitle()];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        if ($this->viewTab !== 'overview') {
            return [];
        }

        return ['overviewCounts' => [
            'submitted' => GradeRosterResource::getEloquentQuery()->where('state', GradeRoster::StateSubmitted)->count(),
            'incomplete' => GradeRosterRow::query()
                ->where('is_current_membership', true)
                ->whereNotNull('released_at')
                ->where('current_outcome_code', 'INC')
                ->whereHas('roster', fn (Builder $rosters): Builder => $rosters->where('state', GradeRoster::StateReleased))
                ->count(),
        ]];
    }

    public function table(Table $table): Table
    {
        $table->filtersLayout(FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->color('gray'));
        if ($this->viewTab === 'academic-progress') {
            return StudentProfileResource::table($table->query(StudentProfileResource::getEloquentQuery()))
                ->heading(null)
                ->header(fn (): View => view('filament.admin.student-profiles.directory-header', [
                    'heading' => $this->studentDirectoryHeading(),
                    'scope' => $this->studentSectionScopeDescription(),
                ]))
                ->extraAttributes([
                    'class' => 'tala-scoped-directory__table',
                    'x-on:keydown.escape.window' => 'if (areFiltersOpen) { toggleFiltersDropdown(); $refs.filtersTriggerActionContainer?.firstElementChild?.focus() }',
                ])
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withOnly([
                    'program', 'curriculumVersion',
                    'enrollments' => fn (Relation $enrollments): Builder => $enrollments->getQuery()
                        ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
                        ->where('term_id', $this->tableFilters['enrollment_scope']['term_id'] ?? null)
                        ->with('studentSection'),
                ]))
                ->columns([
                    ViewColumn::make('last_name')->label('Student')->view('filament.components.student-record-label')
                        ->searchable(['last_name', 'first_name', 'student_number']),
                    TextColumn::make('program.code')->label('Current program')->weight('semibold')->wrap()
                        ->description(fn (StudentProfile $record): ?string => $record->program?->name)
                        ->tooltip(fn (StudentProfile $record): ?string => $record->program?->name)
                        ->extraCellAttributes(['class' => 'tala-directory-program'])
                        ->visible(fn (): bool => blank($this->tableFilters['program']['value'] ?? null)),
                    TextColumn::make('student_section')->label('Student section')->wrap()
                        ->state(fn (StudentProfile $record): string => $record->enrollments->first()?->studentSection?->reference ?? 'Not assigned')
                        ->visible(fn (): bool => filled($this->tableFilters['enrollment_scope']['term_id'] ?? null)),
                ])
                ->defaultGroup(null)
                ->groupingSettingsHidden()
                ->defaultSort('last_name')
                ->paginated([10, 25, 50])->defaultPaginationPageOption(10)
                ->filters(StudentProfileResource::academicBrowsingFilters())
                ->filtersLayout(FiltersLayout::BeforeContent)
                ->filtersFormWidth(Width::ExtraSmall)
                ->filtersFormColumns(1)->deferFilters(false)
                ->filtersResetAction(fn (Action $action): Action => $action->label('Clear filters')->button()->color('gray'))
                ->filtersResetActionPosition(FiltersResetActionPosition::Footer)
                ->recordUrl(fn (StudentProfile $record): string => StudentProfileResource::academicRecordUrl($record, $this))
                ->recordActions([ViewAction::make('viewAcademicRecord')->label('Open record')->button()->color('gray')
                    ->url(fn (StudentProfile $record): string => StudentProfileResource::academicRecordUrl($record, $this))])
                ->emptyState(view('filament.partials.collection-empty-state'))
                ->emptyStateHeading(fn (): string => filled($this->getTableSearch()) || $this->getTable()->isFiltered() ? 'No students match' : 'No student records yet')
                ->emptyStateDescription(fn (): string => filled($this->getTableSearch()) || $this->getTable()->isFiltered() ? 'Clear your search or adjust the program, enrollment or entry-year filters.' : 'Check Students & Enrollment for the student records that should appear here.')
                ->searchPlaceholder('Student name or number');
        }

        if ($this->viewTab === 'inc-corrections') {
            return ReleasedResultsTable::configure($table->query(GradeRosterRow::query()
                ->whereNotNull('released_at')->whereHas('roster', fn (Builder $rosters): Builder => $rosters->where('state', GradeRoster::StateReleased))), includeClass: true, recordUrl: fn (GradeRosterRow $record): string => $this->resultReviewUrl($record))
                ->heading('Course results')
                ->view('filament.admin.grade-rosters.inc-table')
                ->defaultSort('id')
                ->filters([
                    SelectFilter::make('result_scope')->label('Work')->selectablePlaceholder(false)->default('inc')
                        ->options(['inc' => 'INC follow-up', 'all' => 'Grade correction'])
                        ->query(fn (Builder $query, array $data): Builder => $query->when(($data['value'] ?? 'inc') === 'inc',
                            fn (Builder $results): Builder => $results->where('current_outcome_code', 'INC'))),
                    SelectFilter::make('term')->label('Term')->placeholder('All terms')->options(fn (): array => GradeRostersTable::termOptions())
                        ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null,
                            fn (Builder $results, mixed $termId): Builder => $results->whereHas('roster.termOffering', fn (Builder $offerings): Builder => $offerings->where('term_id', $termId)))),
                ])
                ->filtersLayout(FiltersLayout::AboveContent)
                ->filtersFormColumns(1)
                ->deferFilters(false)
                ->emptyState(view('filament.partials.collection-empty-state'))
                ->emptyStateHeading('No released course results match')
                ->emptyStateDescription('Change the work filter, search or term. Results appear here after Registrar release.')
                ->searchPlaceholder('Student, course or class');
        }

        $table = GradeRostersTable::configure($table->query(GradeRosterResource::getEloquentQuery()));
        $table->getFilter('term')->placeholder('All terms');
        $table->getFilter('state')->label('Status');

        return $table
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(['default' => 1, 'sm' => 3])
            ->filtersFormSchema(fn (array $filters): array => [
                $filters['term'],
                SchemaView::make('filament.admin.grade-rosters.class-search'),
                $filters['state'],
            ])
            ->deferFilters(false)
            ->defaultPaginationPageOption(6)
            ->paginationPageOptions([6, 12, 24])
            ->recordUrl(fn (GradeRoster $record): string => $this->classReviewUrl($record))
            ->recordActions([
                ViewAction::make('reviewRoster')
                    ->label(fn (GradeRoster $record): string => $record->state === GradeRoster::StateSubmitted ? 'Review roster' : 'View roster')
                    ->button()->color(fn (GradeRoster $record): string => $record->state === GradeRoster::StateSubmitted ? 'primary' : 'gray')
                    ->url(fn (GradeRoster $record): string => $this->classReviewUrl($record)),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (): string => $this->hasClassQueueFilters() ? 'No classes match your search or filters' : 'No class rosters yet')
            ->emptyStateDescription(fn (): string => $this->hasClassQueueFilters()
                ? 'Clear your search or change the term and status to find a class.'
                : 'Class rosters appear after the teaching assignment and official enrollment are recorded.')
            ->emptyStateActions([
                Action::make('resetClassQueue')->label('Reset search and filters')->color('gray')
                    ->visible(fn (): bool => $this->hasClassQueueFilters())
                    ->action(function (): void {
                        $this->clearClassQueue();
                    }),
            ])
            ->searchPlaceholder('Course, class or Faculty');
    }

    public function studentSectionScopeDescription(): string
    {
        $termId = $this->tableFilters['enrollment_scope']['term_id'] ?? null;
        $term = filled($termId) ? Term::query()->with('academicYear')->find($termId) : null;

        $count = $this->getTableRecords()->total();
        $scope = $term instanceof Term
            ? 'Sections shown for '.collect([$term->label, $term->academicYear?->label])->filter()->implode(' · ').'.'
            : 'Across all terms. Choose an enrolled term to see recorded student sections.';

        return $count.' '.Str::plural('student', $count).' · '.$scope;
    }

    public function studentDirectoryHeading(): string
    {
        $programId = $this->tableFilters['program']['value'] ?? null;
        if (blank($programId)) {
            return 'All programs';
        }

        $program = Program::query()->find($programId);

        return $program instanceof Program ? StudentProfileResource::academicProgramLabel($program) : 'Program unavailable';
    }

    public function clearClassQueue(): void
    {
        $this->resetTableSearch();
        $this->resetTableFiltersForm();
    }

    public function hasClassQueueFilters(): bool
    {
        return filled($this->getTableSearch())
            || filled($this->getTableFilterState('term')['value'] ?? null)
            || filled($this->getTableFilterState('state')['value'] ?? null);
    }

    private function resultReviewUrl(GradeRosterRow $record): string
    {
        return GradeRosterResource::getUrl('view', [
            'record' => $record->grade_roster_id,
            'viewTab' => 'inc-corrections',
            'result' => $record->id,
            'queue' => self::classQueueParameters([
                'filters' => $this->tableFilters,
                'search' => $this->getTableSearch(),
                'page' => $this->getPage(),
            ], 'inc-corrections'),
        ]);
    }

    private function classReviewUrl(GradeRoster $record): string
    {
        return GradeRosterResource::getUrl('view', [
            'record' => $record,
            'viewTab' => $this->viewTab,
            'queue' => self::classQueueParameters([
                'filters' => $this->tableFilters,
                'search' => $this->getTableSearch(),
                'sort' => $this->tableSort,
                'page' => $this->getPage(),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function classQueueParameters(array $context, string $viewTab = 'grade-review'): array
    {
        if ($context === []) {
            return [];
        }

        $parameters = [];

        foreach ($viewTab === 'inc-corrections' ? ['term', 'result_scope'] : ['term', 'state'] as $filter) {
            $value = $context['filters'][$filter]['value'] ?? null;

            if (is_scalar($value) || $value === null) {
                $parameters['filters'][$filter]['value'] = $value ?? '';
            }
        }

        foreach (['search', 'sort'] as $key) {
            if (isset($context[$key]) && is_string($context[$key])) {
                $parameters[$key] = $context[$key];
            }
        }

        if (isset($context['page']) && filter_var($context['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
            $parameters['page'] = (int) $context['page'];
        }

        return $parameters;
    }

    /** @return array<string, array{title: string, description: string, action: string, url: string, icon: string}> */
    public static function workAreas(): array
    {
        return [
            'grade-review' => [
                'title' => 'Grade Review',
                'description' => 'Review immutable Faculty submissions, return named rows with one consolidated explanation, or release the complete current version.',
                'action' => 'Open official grade rosters',
                'url' => GradeRosterResource::getUrl('index'),
                'icon' => 'heroicon-o-document-check',
            ],
            'inc-corrections' => [
                'title' => 'INC & Corrections',
                'description' => 'Review open or overdue INC work, deadline authority, immutable completion successors, and authorized grade corrections.',
                'action' => 'Open released result rows',
                'url' => GradeRosterResource::getUrl('index'),
                'icon' => 'heroicon-o-arrow-path-rounded-square',
            ],
            'academic-progress' => [
                'title' => 'Academic Progress',
                'description' => 'Review released-only results, source-labelled averages, curriculum context, external competency, and academic decisions.',
                'action' => 'Open Student records',
                'url' => StudentProfileResource::getUrl('index', ['viewTab' => 'academic-progress']),
                'icon' => 'heroicon-o-identification',
            ],
            'lifecycle' => [
                'title' => 'Lifecycle History',
                'description' => 'Record and review leave, full withdrawal, transfer, reactivation, and program-shift authority with Accounting review only where applicable.',
                'action' => 'Open lifecycle records',
                'url' => StudentLifecycleChangeResource::getUrl('index'),
                'icon' => 'heroicon-o-arrow-path',
            ],
            'completion-tor' => [
                'title' => 'Completion & TOR',
                'description' => 'Review source-owned completion blockers, record immutable conferral, and manage request-bound TALA Standard TOR lifecycle evidence.',
                'action' => 'Open completion workbench',
                'url' => CompletionAndTor::getUrl(),
                'icon' => 'heroicon-o-document-text',
            ],
        ];
    }
}
