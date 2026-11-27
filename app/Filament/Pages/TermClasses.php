<?php

namespace App\Filament\Pages;

use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\PrepareTermClassProposal;
use App\Actions\Scheduling\RecordTermCohort;
use App\Actions\Scheduling\TermCapacityHeadroomProjection;
use App\Actions\Scheduling\TermCoverageProjection;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\Sections\Schemas\SectionInfolist;
use App\Filament\Resources\Sections\SectionResource;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\EnrollmentSeatReservation;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\SafeUserNotification;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\GridDirection;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

class TermClasses extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable;

    public static function canAccess(): bool
    {
        return parent::canAccess() && (auth()->user()?->hasRole(User::StaffRoleAcademicHead) || SectionResource::canAccess());
    }

    #[Url]
    public ?int $programId = null;

    #[Url]
    public ?string $yearLevel = null;

    #[Url]
    public string $classesView = 'cohorts';

    #[Url]
    public string $sectionTriage = 'all';

    #[Url]
    public string $classTriage = 'all';

    /** @var array<int|string, string|null> */
    #[Locked]
    public array $classConfirmationSources = [];

    public function chooseClassesView(string $view): void
    {
        abort_unless(in_array($view, ['classes', 'cohorts'], true), 404);
        $this->classesView = $view;
        $this->sectionTriage = 'all';
        $this->classTriage = 'all';
        $this->resetTableSearch();
        $this->resetTableColumnSearches();
        $this->resetTableFiltersForm();
        $this->resetTable();
    }

    public function setSectionTriage(string $triage): void
    {
        if (! in_array($triage, ['all', 'no_classes', 'no_subjects'], true)) {
            $triage = 'all';
        }

        $this->sectionTriage = $triage;
        $this->resetPage();
    }

    public function setClassTriage(string $triage): void
    {
        if (! in_array($triage, ['all', 'needs_preparation', 'no_teacher', 'cancelled'], true)) {
            $triage = 'all';
        }

        $this->classTriage = $triage;

        if ($triage === 'cancelled') {
            $this->tableFilters['lifecycle_status']['value'] = 'cancelled';
        } elseif (($this->tableFilters['lifecycle_status']['value'] ?? 'active') === 'cancelled') {
            $this->tableFilters['lifecycle_status']['value'] = 'active';
        }

        $this->resetPage();
    }

    private ?array $classViewData = null;

    private ?array $coverageScope = null;

    /** @var array<string, mixed>|null */
    private ?array $curriculumPreview = null;

    /** @var array<string, array<int, int>> */
    private array $qualifiedFacultyCountsCache = [];

    /** @var array{by_year: array<string, int>, by_curriculum: array<int, int>}|null */
    private ?array $confirmableCountsByCurriculumAndYear = null;

    /** @var list<array{curriculum_id: int, label: string, configured: ?string, url: string, is_locked: bool}>|null */
    private ?array $curriculumReadinessGapsCache = null;

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'Sections & classes';

    protected static ?string $title = 'Sections & classes';

    protected static ?string $slug = 'classes';

    protected static ?int $navigationSort = 2;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = true;

    protected static string $planningTab = 'classes';

    protected string $view = 'filament.pages.term-classes';

    protected function getViewData(): array
    {
        if ($this->classViewData === null) {
            $this->classViewData = parent::getViewData();
            $this->classViewData['cohorts']->loadMissing(['program', 'curriculumVersion.academicYears', 'classOfferings.courseRevision', 'classOfferings.termOffering.curriculumEntry.courseSpecification']);
            $term = $this->classViewData['term'] ?? null;
            $this->classViewData['capacityHeadroom'] = $term instanceof Term
                ? app(TermCapacityHeadroomProjection::class)->forTerm($term)
                : null;
            $readOnly = (bool) ($this->classViewData['readOnly'] ?? false);
            $isLiveOrClosed = $term instanceof Term && ($this->hasPublishedTimetable($term) || $this->isTermClosed($term));
            $this->classViewData['curriculumReadinessGaps'] = ($term instanceof Term
                && ! $readOnly
                && ! $isLiveOrClosed
                && ($this->classViewData['sourceClasses']?->isEmpty() ?? true))
                ? $this->curriculumReadinessGapsForTerm($term, $this->classViewData['cohorts'])
                : [];
            $this->classViewData['allCohortsHaveZeroCurriculumSubjects'] = $term instanceof Term
                && $this->classViewData['cohorts']->isNotEmpty()
                && $this->classViewData['cohorts']->every(fn (TermCohort $cohort): bool => $this->confirmableSubjectCountForCohort($cohort) === 0);
            $this->classViewData['hasOfficialEnrollments'] = $term instanceof Term && $this->hasOfficialStudentEnrollments($term);
            $this->classViewData['sectionTriageCounts'] = $this->computeSectionTriageCounts($this->classViewData['cohorts']);
            $this->classViewData['classTriageCounts'] = $this->computeClassTriageCounts(
                $term,
                $this->classViewData['sourceClasses'],
                $this->classViewData['contextClasses'],
                $this->classViewData['facultyRecords'],
            );
        }

        return $this->classViewData;
    }

    public function mount(): void
    {
        parent::mount();
        abort_unless(in_array($this->classesView, ['classes', 'cohorts'], true), 404);
        if (! in_array($this->sectionTriage, ['all', 'no_classes', 'no_subjects'], true)) {
            $this->sectionTriage = 'all';
        }
        if (! in_array($this->classTriage, ['all', 'needs_preparation', 'no_teacher', 'cancelled'], true)) {
            $this->classTriage = 'all';
        }
        if ($this->tableFilters === null && ($this->programId || $this->yearLevel)) {
            $this->tableFilters = ['program' => ['value' => $this->programId], 'year' => ['value' => $this->yearLevel]];
        }
        if ($this->classTriage === 'cancelled') {
            $this->tableFilters['lifecycle_status']['value'] = 'cancelled';
        }
        $this->autoSyncSchedulingDemands();
    }

    public function table(Table $table): Table
    {
        if ($this->classesView === 'cohorts') {
            $cohortsQuery = TermCohort::query()
                ->where('term_id', $this->termId)
                ->with([
                    'program',
                    'curriculumVersion.academicYears',
                    'classOfferings' => fn (BelongsToMany $query): BelongsToMany => $query
                        ->where('sections.state', '!=', Section::StateCancelled)
                        ->with(['courseRevision', 'termOffering.curriculumEntry.courseSpecification']),
                ])
                ->withCount([
                    'classOfferings as active_class_offerings_count' => fn (Builder $query): Builder => $query
                        ->where('sections.state', '!=', Section::StateCancelled),
                    'enrollments',
                ]);

            if ($this->sectionTriage === 'no_classes') {
                $cohortsQuery->whereDoesntHave(
                    'classOfferings',
                    fn (Builder $query): Builder => $query->where('sections.state', '!=', Section::StateCancelled),
                );
            } elseif ($this->sectionTriage === 'no_subjects') {
                $zeroSubjectCohortIds = $this->getViewData()['cohorts']
                    ->filter(fn (TermCohort $cohort): bool => $this->confirmableSubjectCountForCohort($cohort) === 0)
                    ->pluck('id')
                    ->all();

                if ($zeroSubjectCohortIds === []) {
                    $cohortsQuery->whereRaw('1 = 0');
                } else {
                    $cohortsQuery->whereIn('term_cohorts.id', $zeroSubjectCohortIds);
                }
            }

            return $table->query($cohortsQuery)
                ->columns([
                    TextColumn::make('reference')->label('Student section')->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $identity): Builder => $identity
                        ->where('reference', 'like', '%'.$search.'%')
                        ->orWhereRaw("REPLACE(UPPER(reference), ' ', '') LIKE ?", ['%'.strtoupper(str_replace(' ', '', $search)).'%'])
                        ->orWhereHas('program', fn (Builder $program): Builder => $program->where('name', 'like', '%'.$search.'%'))))->sortable()->weight('semibold')
                        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<span class="inline-block whitespace-nowrap">'.e($this->formatSectionReference($state)).'</span>'))
                        ->description(fn (TermCohort $record): string => collect([$record->program->name, $record->curriculumVersion?->batchLabel() ?? 'Curriculum not associated', $record->year_level ? 'Year '.$record->year_level : null])->filter()->implode(' · ')),
                    TextColumn::make('expected_learners')->label('Students')->state(fn (TermCohort $record): string => match (true) {
                        $record->confirmed_count !== null => $record->confirmed_count.' confirmed',
                        $record->forecast_count !== null => $record->forecast_count.' forecast',
                        default => 'No count recorded',
                    })
                        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<span class="inline-block whitespace-nowrap">'.e($state).'</span>'))
                        ->extraCellAttributes(['class' => 'tabular-nums whitespace-nowrap']),
                    TextColumn::make('classOfferings.code')->label('Linked classes')->listWithLineBreaks()
                        ->placeholder(function (TermCohort $record): string {
                            if ($this->confirmableSubjectCountForCohort($record) === 0) {
                                return '0 '.$this->selectedTerm()->typeLabel().' subjects in curriculum';
                            }

                            return 'No classes linked';
                        })
                        ->extraCellAttributes(['class' => 'tabular-nums'])
                        ->description(function (TermCohort $record): string {
                            $activeCount = $record->relationLoaded('classOfferings')
                                ? $record->classOfferings->where('state', '!=', Section::StateCancelled)->count()
                                : (int) ($record->active_class_offerings_count ?? $record->classOfferings()->where('sections.state', '!=', Section::StateCancelled)->count());

                            if ($activeCount === 0) {
                                if ($this->hasPublishedTimetable() || $this->isTermClosed() || ($this->getViewData()['readOnly'] ?? false)) {
                                    return 'No classes linked';
                                }

                                if ($this->confirmableSubjectCountForCohort($record) === 0) {
                                    $termTypeLabel = $this->selectedTerm()->typeLabel();
                                    if ($record->curriculumVersion instanceof CurriculumVersion && ! $record->curriculumVersion->isEditableDraft()) {
                                        return "Published curricula are locked; copy the curriculum to add {$termTypeLabel} subjects, or use + Add class.";
                                    }

                                    return "Open curriculum to add {$termTypeLabel} subjects, or use + Add class.";
                                }

                                return 'Use Load subjects to add classes for this student section.';
                            }

                            return $activeCount.' '.Str::plural('linked class', $activeCount);
                        }),
                ])->defaultSort('reference')
                ->filters([
                    SelectFilter::make('program_id')
                        ->label('Program')
                        ->searchable()
                        ->options(fn (): array => Program::query()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Program $program): array => [$program->id => $program->name.' ('.$program->code.')'])
                            ->all()),
                ])
                ->filtersTriggerAction(fn (Action $action): Action => $action->label('Filter sections')->button()->color('gray')->size('sm'))
                ->columnManager(false)
                ->recordActionsColumnLabel('Actions')
                ->recordActions([
                    Action::make('loadCohortCurriculum')
                        ->label('Load subjects')
                        ->icon(Heroicon::OutlinedSquaresPlus)
                        ->button()
                        ->color('gray')
                        ->size('sm')
                        ->extraAttributes(['class' => 'whitespace-nowrap'])
                        ->visible(fn (TermCohort $record): bool => ! ($this->getViewData()['readOnly'] ?? false)
                            && ! $this->hasPublishedTimetable()
                            && ! $this->isTermClosed()
                            && $record->confirmed_at !== null
                            && $this->getViewData()['activePackage'] !== null
                            && (bool) auth()->user()?->can('create', TermOffering::class)
                            && $this->confirmableSubjectCountForCohort($record) > 0)
                        ->action(fn (TermCohort $record) => $this->replaceMountedAction('loadCurriculumSubjects', ['cohortId' => $record->id])),
                    Action::make('openCohortCurriculum')
                        ->label(fn (TermCohort $record): string => $record->curriculumVersion instanceof CurriculumVersion && ! $record->curriculumVersion->isEditableDraft()
                            ? 'Open curriculum (copy to edit)'
                            : 'Open curriculum')
                        ->icon(Heroicon::OutlinedBookOpen)
                        ->button()
                        ->color('gray')
                        ->size('sm')
                        ->extraAttributes(['class' => 'whitespace-nowrap'])
                        ->visible(fn (TermCohort $record): bool => ! ($this->getViewData()['readOnly'] ?? false)
                            && ! $this->hasPublishedTimetable()
                            && ! $this->isTermClosed()
                            && $record->curriculum_version_id !== null
                            && $this->confirmableSubjectCountForCohort($record) === 0)
                        ->url(fn (TermCohort $record): string => CurriculumVersionResource::getUrl('review', ['record' => $record->curriculum_version_id])),
                    ActionGroup::make([
                        Action::make('addCohortClass')
                            ->label('Add class')
                            ->icon(Heroicon::OutlinedPlus)
                            ->visible(fn (TermCohort $record): bool => ! ($this->getViewData()['readOnly'] ?? false)
                                && ! $this->hasPublishedTimetable()
                                && ! $this->isTermClosed()
                                && $record->confirmed_at !== null
                                && $this->getViewData()['activePackage'] !== null
                                && (bool) auth()->user()?->can('create', TermOffering::class)
                                && $this->confirmableSubjectCountForCohort($record) === 0)
                            ->action(fn (TermCohort $record) => $this->replaceMountedAction('addClass', ['cohortId' => $record->id])),
                        $this->editCohortAction(),
                        $this->deleteCohortAction(),
                    ])
                        ->icon(Heroicon::OutlinedEllipsisVertical)
                        ->iconButton()
                        ->size('sm')
                        ->color('gray')
                        ->tooltip('More actions'),
                    Action::make('linkedClasses')
                        ->label('View classes')
                        ->icon(Heroicon::OutlinedEye)
                        ->button()
                        ->color('gray')
                        ->size('sm')
                        ->extraAttributes(['class' => 'whitespace-nowrap'])
                        ->unsavedChangesAlert(false)
                        ->visible(fn (TermCohort $record): bool => $record->classOfferings->isNotEmpty())
                        ->modalHeading(fn (TermCohort $record): string => $this->formatSectionReference($record->reference).' · linked classes')
                        ->modalWidth(Width::TwoExtraLarge)
                        ->stickyModalHeader()
                        ->stickyModalFooter()
                        ->schema(fn (TermCohort $record): array => [RepeatableEntry::make('classes')->state($record->classOfferings)->schema([
                            TextEntry::make('code')->label('Class'),
                            TextEntry::make('title')->label('Subject')->state(fn (Section $record): string => $record->courseRevision?->title ?? $record->termOffering?->curriculumEntry?->courseSpecification?->title ?? $record->code),
                        ])->columns(2)])
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),
                ])
                ->toolbarActions([
                    $this->recordCohortAction()->size('sm')->visible(fn (): bool => ! ($this->getViewData()['readOnly'] ?? false)
                        && ! $this->hasPublishedTimetable()
                        && ! $this->isTermClosed()),
                    $this->suggestSectionsForTermAction()->size('sm')->visible(fn (): bool => ! ($this->getViewData()['readOnly'] ?? false)
                        && ! $this->hasPublishedTimetable()
                        && ! $this->isTermClosed()),
                ])
                ->emptyState(view('filament.partials.collection-empty-state'))
                ->emptyStateHeading(fn (): string => filled($this->getTableSearch()) || $this->getTable()->getActiveFiltersCount() || $this->sectionTriage !== 'all' ? 'No matching student sections' : 'No student sections added for this term')
                ->emptyStateDescription(fn (): string => filled($this->getTableSearch()) || $this->getTable()->getActiveFiltersCount() || $this->sectionTriage !== 'all'
                    ? 'Try another name or clear the search and filters.'
                    : (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'The Registrar adds student sections before linking classes.' : 'Add a student section before linking classes.'))->stackedOnMobile();
        }

        $classesQuery = $this->classesQuery();
        if ($this->classTriage === 'needs_preparation') {
            $classesQuery
                ->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])
                ->where(fn (Builder $query) => $query->whereNull('confirmed_at')
                    ->orWhereNotIn('id', $this->getViewData()['contextClasses']->pluck('id'))
                    ->orWhereDoesntHave('cohorts')->orWhereDoesntHave('deliveryGroups.schedulingDemands')
                    ->orWhereHas('deliveryGroups.schedulingDemands', fn (Builder $demands) => $demands->where('validation_state', SchedulingDemand::ValidationActionRequired)));
        } elseif ($this->classTriage === 'no_teacher') {
            $noTeacherIds = $this->classIdsWithoutQualifiedTeacher(
                $this->getViewData()['sourceClasses'],
                $this->getViewData()['facultyRecords'],
            );

            if ($noTeacherIds === []) {
                $classesQuery->whereRaw('1 = 0');
            } else {
                $classesQuery->whereIn('sections.id', $noTeacherIds);
            }
        }

        return $table->query($classesQuery)
            ->columns([
                TextColumn::make('code')->label('Subject')->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $identity): Builder => $identity
                    ->where('code', 'like', '%'.$search.'%')
                    ->orWhereHas('courseRevision', fn (Builder $course): Builder => $course->where('title', 'like', '%'.$search.'%'))
                    ->orWhereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $course): Builder => $course->where('title', 'like', '%'.$search.'%'))))->sortable()->weight('bold')
                    ->formatStateUsing(fn (string $state, Section $record): string => ($record->courseRevision?->title ?? $record->termOffering?->curriculumEntry?->courseSpecification?->title) ?: $state)
                    ->description(fn (Section $record): string => $this->roomRequirementSummary($record))
                    ->wrap(),
                TextColumn::make('source_code')->label('Class code')->state(fn (Section $record): string => $record->code)->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('termOffering.curriculumEntry.curriculumVersion.program.code')
                    ->label('Program')
                    ->state(function (Section $record): ?string {
                        $codes = $this->linkedProgramsForClass($record)
                            ->pluck('code')
                            ->filter(fn (mixed $code): bool => filled($code))
                            ->values();

                        return $codes->isNotEmpty() ? $codes->implode(' · ') : null;
                    })
                    ->placeholder('Not linked')
                    ->description(function (Section $record): ?HtmlString {
                        $programs = $this->linkedProgramsForClass($record);
                        if ($programs->count() > 1) {
                            return new HtmlString('<span class="inline-block whitespace-nowrap">'.e('Merged · '.$programs->count().' programs').'</span>');
                        }

                        $batchLabel = $record->termOffering?->curriculumEntry?->curriculumVersion?->batchLabel();

                        return filled($batchLabel)
                            ? new HtmlString('<span class="inline-block whitespace-nowrap">'.e($batchLabel).'</span>')
                            : null;
                    })
                    ->tooltip(function (Section $record): ?string {
                        $programs = $this->linkedProgramsForClass($record);
                        if ($programs->count() <= 1) {
                            return null;
                        }

                        return $programs
                            ->map(fn (Program $program): string => collect([$program->code, $program->name])->filter(fn (mixed $value): bool => filled($value))->implode(' — '))
                            ->implode(' | ');
                    })
                    ->extraCellAttributes(['class' => 'whitespace-nowrap'])
                    ->sortable(),
                TextColumn::make('cohorts.reference')->label('Student sections')->listWithLineBreaks()->placeholder('No sections linked')
                    ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<span class="inline-block whitespace-nowrap">'.e($this->formatSectionReference($state)).'</span>'))
                    ->description(function (Section $record): ?HtmlString {
                        $sectionCount = $record->cohorts->count();
                        if ($sectionCount === 0) {
                            return null;
                        }

                        $totalStudents = $record->cohorts->sum(fn ($cohort): int => (int) $cohort->pivot->expected_count);
                        $studentCopy = $totalStudents.' '.Str::plural('student', $totalStudents);
                        $summary = $sectionCount > 1
                            ? $sectionCount.' sections · '.$studentCopy
                            : $studentCopy;

                        return new HtmlString('<span class="inline-block whitespace-nowrap tabular-nums">'.e($summary).'</span>');
                    })
                    ->extraCellAttributes(['class' => 'whitespace-nowrap tabular-nums']),
                TextColumn::make('weekly_meetings')->label('Weekly meetings')
                    ->state(fn (Section $record): int => (int) $this->demands($record)->sum('meeting_count'))
                    ->description(fn (Section $record): string => $this->demands($record)->map(fn (SchedulingDemand $demand): string => $demand->meeting_count.' × '.$demand->required_duration_minutes.' min')->unique()->implode(' · '))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('teacher_options')->label('Qualified faculty')
                    ->state(function (Section $record): string {
                        $course = $record->courseRevision?->course?->code ?? $record->termOffering?->curriculumEntry?->courseSpecification?->course?->code;
                        if (! $course) {
                            return 'Subject not recorded';
                        }
                        $count = $this->getViewData()['facultyRecords']->filter(fn (array $faculty): bool => in_array($course, $faculty['courses'], true))->count();

                        return $count ? $count.' qualified' : 'No qualified teacher';
                    })
                    ->formatStateUsing(function (string $state): HtmlString {
                        if ($state === 'No qualified teacher') {
                            return new HtmlString('<span class="tala-qualified-pill is-warning">'.e($state).'</span>');
                        }
                        if ($state === 'Subject not recorded') {
                            return new HtmlString('<span class="tala-qualified-pill is-muted">'.e($state).'</span>');
                        }

                        return new HtmlString('<span class="tala-qualified-pill">'.e($state).'</span>');
                    })
                    ->extraCellAttributes(['class' => 'whitespace-nowrap tabular-nums']),
                TextColumn::make('state')->label('Class lifecycle')->badge()
                    ->formatStateUsing(fn (string $state): string => Section::stateOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Section::StateOpen => 'success',
                        Section::StatePlanned => 'info',
                        Section::StateCancelled => 'danger',
                        Section::StateClosed => 'gray',
                        default => 'gray',
                    })->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('preparation')->label('Status')->state(fn (Section $record): string => $this->preparation($record))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'Needs active calendar source' => 'Needs preparation',
                        'Confirmed; requirements recorded' => 'Confirmed',
                        default => $state,
                    })->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Confirmed; requirements recorded' => 'success',
                        'Review recorded findings', 'Needs active subject revision' => 'danger',
                        'Outside generation' => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (Section $record): ?string => $this->inlineFindingsDescription($record))
                    ->extraCellAttributes(fn (Section $record): array => $this->inlineFindingsDescription($record) === null ? ['class' => 'whitespace-nowrap'] : [])
                    ->wrap(fn (Section $record): bool => $this->inlineFindingsDescription($record) !== null),
            ])
            ->filters([
                SelectFilter::make('program')->label('Program')->searchable()->options(fn (): array => Program::query()->orderBy('name')->get()->mapWithKeys(fn (Program $program): array => [$program->id => $program->name.' ('.$program->code.')'])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, mixed $value) => $query->whereIn('id', $this->coverageClassIds((int) $value, $this->getTableFilterState('year')['value'] ?? null)))),
                SelectFilter::make('year')->label('Curriculum year')->options(fn (): array => collect($this->coverageRows())->pluck('year_level')->filter()->unique()->mapWithKeys(fn (string $year): array => [$year => $year])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, mixed $value) => $query->whereIn('id', $this->coverageClassIds(isset($this->getTableFilterState('program')['value']) ? (int) $this->getTableFilterState('program')['value'] : null, (string) $value)))),
                Filter::make('needs_attention')->label('Needs preparation')->query(fn (Builder $query) => $query
                    ->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])
                    ->where(fn (Builder $query) => $query->whereNull('confirmed_at')
                        ->orWhereNotIn('id', $this->getViewData()['contextClasses']->pluck('id'))
                        ->orWhereDoesntHave('cohorts')->orWhereDoesntHave('deliveryGroups.schedulingDemands')
                        ->orWhereHas('deliveryGroups.schedulingDemands', fn (Builder $demands) => $demands->where('validation_state', SchedulingDemand::ValidationActionRequired)))),
                SelectFilter::make('lifecycle_status')
                    ->label('Class status')
                    ->options([
                        'active' => 'Active classes',
                        'cancelled' => 'Cancelled classes',
                        'all' => 'All classes',
                    ])
                    ->default('active')
                    ->selectablePlaceholder(false)
                    ->query(function (Builder $query, array $data): Builder {
                        $effective = $this->classTriage === 'cancelled'
                            ? 'cancelled'
                            : ($data['value'] ?? 'active');

                        return match ($effective) {
                            'cancelled' => $query->where('state', Section::StateCancelled),
                            'all' => $query,
                            default => $query->where('state', '!=', Section::StateCancelled),
                        };
                    }),
            ])
            ->columnManager(false)
            ->filtersTriggerAction(fn (Action $action): Action => $action->label('Filter classes')->button()->color('gray')->size('sm'))
            ->toolbarActions([
                $this->loadCurriculumSubjectsAction()->size('sm'),
                $this->addClassAction()->size('sm'),
                BulkAction::make('confirmSelectedClasses')
                    ->size('sm')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Confirm selected classes')
                    ->label('Confirm selected classes')
                    ->visible(fn (): bool => $this->termId !== null
                        && (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                        && ! $this->hasPublishedTimetable()
                        && ! $this->isTermClosed())
                    ->modalDescription('Review the selected classes and student counts. Regular classes use their recorded subject and the approved teaching calendar. If any class needs attention, all remain unchanged.')
                    ->fillForm(function (Collection $records): array {
                        $this->classConfirmationSources = $records->mapWithKeys(fn (Section $record): array => [$record->id => $this->classConfirmationSource($record)])->all();
                        foreach (TermCohort::query()->where('term_id', $this->termId)->get() as $cohort) {
                            $this->classConfirmationSources['cohort:'.$cohort->id] = $this->cohortConfirmationSource($cohort);
                        }
                        $this->classConfirmationSources['calendar'] = TermCalendarPackage::query()->where('term_id', $this->termId)
                            ->where('state', TermCalendarPackage::StateActive)->first()?->concurrencyToken();

                        return ['classes' => $records->map(fn (Section $record): array => [
                            'section_id' => $record->id,
                            'source' => $record->source,
                            'cohorts' => $record->cohorts->map(fn ($cohort): array => ['term_cohort_id' => $cohort->id, 'expected_count' => $cohort->pivot->expected_count])->all(),
                            'authority_reference' => $record->authority_reference,
                        ])->all()];
                    })
                    ->schema([
                        Repeater::make('classes')->label('Selected classes')->addable(false)->deletable(false)->reorderable(false)->schema([
                            Select::make('section_id')->label('Class')->options(fn (): array => $this->classesQuery()->pluck('code', 'id')->all())->disabled()->dehydrated()->required(),
                            Hidden::make('source')->dehydrated(false),
                            Repeater::make('cohorts')
                                ->label('Student sections')
                                ->minItems(1)
                                ->required()
                                ->reorderable(false)
                                ->addActionLabel('Link a student section')
                                ->columns(2)
                                ->extraAttributes(['class' => 'tala-cohort-repeater'])
                                ->schema([
                                    Select::make('term_cohort_id')
                                        ->label('Student section')
                                        ->options(fn (): array => $this->cohortSelectOptions())
                                        ->searchable()
                                        ->required()
                                        ->distinct()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                                            $count = $this->cohortHeadcountsById()[(int) $state] ?? null;
                                            if ($count !== null) {
                                                $set('expected_count', $count);
                                            }
                                        }),
                                    TextInput::make('expected_count')->label('Students in this class')->numeric()->integer()->minValue(1)->required(),
                                ]),
                            TextInput::make('authority_reference')
                                ->label('Additional class approval reference')
                                ->helperText('Required for an externally approved Additional class.')
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => $get('source') === Section::SourceAdditional)
                                ->required(fn (Get $get): bool => $get('source') === Section::SourceAdditional),
                        ]),
                    ])
                    ->action(function (Collection $records, array $data, Schema $schema): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        if ($this->hasPublishedTimetable() || $this->isTermClosed()) {
                            SafeUserNotification::sendValidationError();
                            throw ValidationException::withMessages([$schema->getStatePath().'.classes' => ['Classes cannot be confirmed after the official timetable is published or when the term is closed.']]);
                        }
                        try {
                            DB::transaction(function () use ($actor, $records, $data): void {
                                $term = Term::query()->whereKey($this->termId)->lockForUpdate()->firstOrFail();
                                $calendar = TermCalendarPackage::query()->where('term_id', $term->id)
                                    ->where('state', TermCalendarPackage::StateActive)->lockForUpdate()->first();
                                if (($this->classConfirmationSources['calendar'] ?? null) !== $calendar?->concurrencyToken()) {
                                    throw ValidationException::withMessages(['classes' => 'The approved calendar has changed. Close this review and reopen it with the current calendar.']);
                                }
                                $cohortIds = collect($data['classes'])->flatMap(fn (array $row): array => array_column($row['cohorts'], 'term_cohort_id'))->unique();
                                foreach (TermCohort::query()->whereKey($cohortIds)->lockForUpdate()->get() as $cohort) {
                                    if (($this->classConfirmationSources['cohort:'.$cohort->id] ?? null) !== $this->cohortConfirmationSource($cohort)) {
                                        throw ValidationException::withMessages(['classes' => 'A selected student section has changed. Close this review and reopen it with the latest student section details.']);
                                    }
                                }
                                $items = collect($data['classes'])->map(function (array $row) use ($records, $actor, $term, $calendar): array {
                                    abort_unless($records->contains('id', (int) $row['section_id']), 403);
                                    $section = Section::query()->whereKey((int) $row['section_id'])->lockForUpdate()->firstOrFail();
                                    $section->load(['calendarPackage', 'cohorts', 'termOffering.curriculumEntry']);
                                    if (($this->classConfirmationSources[$section->id] ?? null) !== $this->classConfirmationSource($section)) {
                                        throw ValidationException::withMessages(['classes' => 'A selected class has changed. Close this review and reopen it with the latest class details.']);
                                    }
                                    $section = $this->ensureSectionBoundToActiveCalendar(
                                        $term,
                                        $section,
                                        $calendar,
                                        $actor,
                                        $row['authority_reference'] ?? null,
                                    );

                                    return ['section' => $section, 'cohort_counts' => collect($row['cohorts'])->mapWithKeys(fn (array $cohort): array => [(int) $cohort['term_cohort_id'] => (int) $cohort['expected_count']])->all(), 'authority_reference' => $row['authority_reference'] ?? null];
                                })->all();
                                abort_unless(count($items) === $records->count(), 403);
                                app(ConfirmClassOffering::class)->batch($actor, $items, $this->termId);
                            }, attempts: 5);
                        } catch (ValidationException $exception) {
                            $messages = collect($exception->errors())->flatten()->all();
                            SafeUserNotification::sendValidationError();
                            throw ValidationException::withMessages([$schema->getStatePath().'.classes' => $messages]);
                        }
                        $this->autoSyncSchedulingDemands(force: true);
                        $this->resetClassCaches();
                        Notification::make()->title('Selected classes confirmed')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->recordActions([
                $this->prepareClassOfferingAction()->label('Prepare')->icon(Heroicon::OutlinedPencilSquare)->button()->size('sm')->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->visible(fn (Section $record): bool => $this->canPrepareClass($record) && $this->preparation($record) === 'Needs active calendar source'),
                $this->confirmClassAction()->label('Confirm students')->icon(Heroicon::OutlinedCheck)->button()->size('sm')->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->visible(fn (Section $record): bool => $this->canPrepareClass($record) && $this->preparation($record) !== 'Needs active calendar source'),
                $this->editClassAction()->size('sm')->extraAttributes(['class' => 'whitespace-nowrap']),
                $this->deleteDraftClassAction()->size('sm')->extraAttributes(['class' => 'whitespace-nowrap']),
                $this->cancelClassAction()->size('sm')->extraAttributes(['class' => 'whitespace-nowrap']),
                $this->classDetailsAction('viewClass')->label('Details')->icon(Heroicon::OutlinedEye)->button()->color('gray')->size('sm')->extraAttributes(['class' => 'whitespace-nowrap']),
            ])->recordActionsColumnLabel('Actions')->defaultSort('code')
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (): string => $this->hasActiveClassesFilterOrSearch() ? 'No matching classes' : 'No classes added for this term')
            ->emptyStateDescription(fn (): string => $this->hasActiveClassesFilterOrSearch()
                ? 'Try another subject or clear the search and filters.'
                : (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'The Registrar adds student sections and loads classes for this term.' : 'Add student sections first, then load subjects from curriculum or add a class.'))->stackedOnMobile();
    }

    public function loadCurriculumSubjectsAction(): Action
    {
        return Action::make('loadCurriculumSubjects')
            ->label('Load from curriculum')
            ->icon(Heroicon::OutlinedSquaresPlus)
            ->color('primary')
            ->modalHeading('Load classes from curriculum')
            ->modalDescription('Pick a student section, review its curriculum subjects for this term, and create and confirm those classes in one step.')
            ->modalSubmitActionLabel('Create & confirm classes')
            ->modalSubmitAction(fn (Action $action): Action => $action->disabled(fn (): bool => $this->isLoadCurriculumSubmitDisabled()))
            ->modalCancelActionLabel('Cancel')
            ->modalWidth(Width::ThreeExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => $this->termId !== null
                && ! ($this->getViewData()['readOnly'] ?? false)
                && ! $this->hasPublishedTimetable()
                && ! $this->isTermClosed()
                && (bool) auth()->user()?->can('create', TermOffering::class))
            ->extraAttributes(fn (): array => $this->hasClassCreationPrerequisites()
                ? []
                : [
                    'aria-disabled' => 'true',
                    'aria-describedby' => 'term-classes-prereq-notice',
                    'class' => 'tala-action-disabled',
                ])
            ->modal(fn (): bool => $this->hasClassCreationPrerequisites())
            ->fillForm(function (array $arguments): array {
                $preview = $this->currentCurriculumPreview();
                $cohorts = $preview['cohorts']->whereNotNull('confirmed_at');
                $cohortId = isset($arguments['cohortId']) ? (int) $arguments['cohortId'] : ($cohorts->count() === 1 ? (int) $cohorts->first()->id : null);
                $cohort = $cohortId !== null ? $cohorts->firstWhere('id', $cohortId) : null;

                if (! $cohort instanceof TermCohort) {
                    return [
                        'term_cohort_id' => null,
                        'year_level' => null,
                        'default_capacity' => null,
                        'default_modality' => null,
                        'entry_ids' => [],
                    ];
                }

                $yearLevel = $this->defaultYearLevelForCohort($cohort, $preview);
                $entryIds = $yearLevel !== null
                    ? $this->defaultSelectedEntryIdsForCohort($cohort, $yearLevel, $preview)
                    : [];

                return [
                    'term_cohort_id' => $cohort->id,
                    'year_level' => $yearLevel,
                    'default_capacity' => (int) $cohort->confirmed_count,
                    'default_modality' => null,
                    'entry_ids' => $entryIds,
                ];
            })
            ->schema(function (): array {
                $preview = $this->currentCurriculumPreview();
                $cohorts = $preview['cohorts']->whereNotNull('confirmed_at');
                $cohorts->loadMissing(['program', 'curriculumVersion.academicYears']);
                $termType = $this->selectedTerm()->type;
                $maxRoomSeats = (int) (Room::query()->where('is_active', true)->max('capacity') ?? 0);

                $cohortOptions = $cohorts->mapWithKeys(function (TermCohort $cohort): array {
                    $program = $cohort->program?->code ?: ($cohort->program?->name ?? 'Program');
                    $curriculum = $cohort->curriculumVersion?->batchLabel() ?? 'Curriculum';
                    $reference = $this->formatSectionReference($cohort->reference);

                    return [
                        $cohort->id => "{$reference} · {$program} · {$curriculum} ({$cohort->confirmed_count} students)",
                    ];
                })->all();

                $availableYearLevels = fn (Get $get): array => $this->availableYearLevelsForCohortId((int) ($get('term_cohort_id') ?? 0));

                return [
                    Select::make('term_cohort_id')
                        ->label('Student section')
                        ->options($cohortOptions)
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state) use ($cohorts, $preview): void {
                            $cohort = $cohorts->firstWhere('id', (int) $state);
                            if (! $cohort instanceof TermCohort) {
                                $set('year_level', null);
                                $set('default_capacity', null);
                                $set('entry_ids', []);

                                return;
                            }

                            $resolvedYear = $this->defaultYearLevelForCohort($cohort, $preview);
                            $set('year_level', $resolvedYear);
                            $set('default_capacity', (int) $cohort->confirmed_count);
                            $set('entry_ids', $resolvedYear !== null
                                ? $this->defaultSelectedEntryIdsForCohort($cohort, $resolvedYear, $preview)
                                : []);
                        }),
                    Placeholder::make('no_curriculum_subjects_notice')
                        ->hiddenLabel()
                        ->visible(fn (Get $get): bool => filled($get('term_cohort_id')) && $availableYearLevels($get) === [])
                        ->content(function (Get $get) use ($cohorts, $preview, $termType): HtmlString {
                            $cohort = $cohorts->firstWhere('id', (int) $get('term_cohort_id'));
                            if (! $cohort instanceof TermCohort) {
                                return new HtmlString('<div class="tala-inline-prereq"><div class="tala-inline-prereq__body"><p class="tala-inline-prereq__text">No curriculum subjects are available for this student section.</p></div></div>');
                            }

                            $termTypeLabel = e($this->selectedTerm()->typeLabel());
                            $programCode = e($cohort->program?->code ?: ($cohort->program?->name ?? 'Program'));
                            $batchLabel = e($cohort->curriculumVersion?->batchLabel() ?? 'Selected curriculum');
                            $curriculumId = (int) ($cohort->curriculum_version_id ?? 0);
                            $curriculumUrl = $curriculumId > 0
                                ? e(CurriculumVersionResource::getUrl('review', ['record' => $curriculumId]))
                                : null;

                            $matchingCatalogCount = collect($preview['catalog'])
                                ->where('curriculum_id', $cohort->curriculum_version_id)
                                ->where('term_type', $termType)
                                ->count();

                            if ($matchingCatalogCount > 0) {
                                $openLink = $curriculumUrl !== null
                                    ? " or <a href=\"{$curriculumUrl}\" class=\"fi-link font-medium\">Open curriculum</a>"
                                    : '';

                                return new HtmlString(
                                    '<div class="tala-inline-prereq">'
                                    .'<div class="tala-inline-prereq__body">'
                                    ."<p class=\"tala-inline-prereq__title\">All {$termTypeLabel} subjects for {$programCode} · {$batchLabel} are already loaded or locked.</p>"
                                    ."<p class=\"tala-inline-prereq__text\">Close this dialog and use <strong>Add class</strong> for an additional or off-curriculum class{$openLink}.</p>"
                                    .'</div>'
                                    .'</div>'
                                );
                            }

                            $periods = $curriculumId > 0
                                ? ($this->configuredSubjectPeriodsByCurriculum([$curriculumId])[$curriculumId] ?? [])
                                : [];
                            $configuredSummary = $this->formatConfiguredPeriods(
                                $periods,
                                style: 'paren',
                                skipTermType: $termType,
                            );

                            $existingSentence = $configuredSummary !== null
                                ? 'Subjects currently exist for: '.e($configuredSummary).'.'
                                : 'No subjects are configured in this curriculum yet.';

                            $actionSentence = $curriculumUrl !== null
                                ? "<a href=\"{$curriculumUrl}\" class=\"fi-link font-medium\">Open curriculum</a> to add {$termTypeLabel} subjects, or close this dialog and use <strong>Add class</strong> for an additional or off-curriculum class."
                                : 'Close this dialog and use <strong>Add class</strong> for an additional or off-curriculum class.';

                            return new HtmlString(
                                '<div class="tala-inline-prereq">'
                                .'<div class="tala-inline-prereq__body">'
                                ."<p class=\"tala-inline-prereq__title\">{$programCode} · {$batchLabel} has 0 subjects for {$termTypeLabel}.</p>"
                                ."<p class=\"tala-inline-prereq__text\">{$existingSentence} {$actionSentence}</p>"
                                .'</div>'
                                .'</div>'
                            );
                        }),
                    Grid::make(['default' => 1, 'md' => 2])
                        ->visible(fn (Get $get): bool => blank($get('term_cohort_id')) || $availableYearLevels($get) !== [])
                        ->schema([
                            Select::make('year_level')
                                ->label('Curriculum year level')
                                ->required()
                                ->live()
                                ->disabled(fn (Get $get): bool => blank($get('term_cohort_id')))
                                ->options($availableYearLevels)
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($cohorts, $preview): void {
                                    $cohort = $cohorts->firstWhere('id', (int) $get('term_cohort_id'));
                                    if (! $cohort instanceof TermCohort || blank($state)) {
                                        $set('entry_ids', []);

                                        return;
                                    }

                                    $set('entry_ids', $this->defaultSelectedEntryIdsForCohort($cohort, (string) $state, $preview));
                                })
                                ->helperText('Only subjects scheduled for this term period in the section’s active curriculum are listed.'),
                            TextInput::make('default_capacity')
                                ->label('Approved capacity per class')
                                ->integer()
                                ->minValue(1)
                                ->required()
                                ->helperText($maxRoomSeats > 0
                                    ? "Defaults to the section’s expected students. Largest active room holds {$maxRoomSeats} seats."
                                    : 'Defaults to the section’s expected students.'),
                        ]),
                    Select::make('default_modality')
                        ->label('Preferred delivery mode (when multiple are allowed)')
                        ->options(TermOffering::modalityOptions())
                        ->visible(fn (Get $get): bool => blank($get('term_cohort_id')) || $availableYearLevels($get) !== [])
                        ->placeholder('Use each subject’s first approved mode'),
                    CheckboxList::make('entry_ids')
                        ->label('Curriculum subjects to create & confirm')
                        ->bulkToggleable()
                        ->searchable()
                        ->searchPrompt('Search by subject code or title...')
                        ->columns(['default' => 1, 'md' => 2])
                        ->gridDirection(GridDirection::Row)
                        ->extraAttributes(['class' => 'tala-card-picker'])
                        ->required()
                        ->visible(fn (Get $get): bool => filled($get('term_cohort_id')) && filled($get('year_level')))
                        ->options(function (Get $get) use ($cohorts, $preview, $termType): array {
                            $cohort = $cohorts->firstWhere('id', (int) $get('term_cohort_id'));
                            $yearLevel = (string) ($get('year_level') ?? '');
                            if (! $cohort instanceof TermCohort || $yearLevel === '') {
                                return [];
                            }

                            $linkedEntryIds = $this->existingCohortEntryIds($cohort->id);
                            $lockedEntryIds = $this->lockedCurriculumEntryIdsForTerm();

                            return collect($preview['catalog'])
                                ->where('curriculum_id', $cohort->curriculum_version_id)
                                ->where('term_type', $termType)
                                ->where('year_level', $yearLevel)
                                ->reject(fn (array $course): bool => isset($lockedEntryIds[(int) $course['id']]))
                                ->mapWithKeys(fn (array $course): array => [
                                    (string) $course['id'] => $course['course_code'].' · '.$course['course_title']
                                        .(isset($linkedEntryIds[(int) $course['id']]) ? ' (already linked)' : ''),
                                ])
                                ->all();
                        })
                        ->descriptions(function (Get $get) use ($cohorts, $preview, $termType): array {
                            $cohort = $cohorts->firstWhere('id', (int) $get('term_cohort_id'));
                            $yearLevel = (string) ($get('year_level') ?? '');
                            if (! $cohort instanceof TermCohort || $yearLevel === '') {
                                return [];
                            }

                            $lockedEntryIds = $this->lockedCurriculumEntryIdsForTerm();
                            $displayedCourses = collect($preview['catalog'])
                                ->where('curriculum_id', $cohort->curriculum_version_id)
                                ->where('term_type', $termType)
                                ->where('year_level', $yearLevel)
                                ->reject(fn (array $course): bool => isset($lockedEntryIds[(int) $course['id']]));

                            $qualifiedCounts = $this->qualifiedFacultyCountsByCourse(
                                $displayedCourses->pluck('course_id')->all(),
                            );

                            return $displayedCourses
                                ->mapWithKeys(function (array $course) use ($qualifiedCounts): array {
                                    $meetings = $course['meetings'] ?: 'Meeting pattern needs attention';
                                    $count = $qualifiedCounts[(int) ($course['course_id'] ?? 0)] ?? 0;
                                    $teacherHint = $count >= 1
                                        ? "{$count} qualified ".Str::plural('teacher', $count)
                                        : 'No qualified teacher yet (assign in 3. Faculty & rooms)';

                                    return [
                                        (string) $course['id'] => $meetings.' · '.$teacherHint,
                                    ];
                                })
                                ->all();
                        })
                        ->helperText('Uncheck any subject you do not want to offer for this student section right now.'),
                    Placeholder::make('curriculum_prompt')
                        ->hiddenLabel()
                        ->visible(fn (Get $get): bool => blank($get('term_cohort_id')) || ($availableYearLevels($get) !== [] && blank($get('year_level'))))
                        ->content('Choose a student section and curriculum year level above to select subjects.'),
                ];
            })
            ->action(function (array $data, ?Schema $schema = null): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $statePath = $schema instanceof Schema ? $schema->getStatePath().'.' : '';
                if ($this->hasPublishedTimetable() || $this->isTermClosed()) {
                    throw ValidationException::withMessages([$statePath.'entry_ids' => 'Classes cannot be loaded after the official timetable is published or when the term is closed.']);
                }
                $prerequisiteWarning = $this->classCreationPrerequisiteWarning();
                if ($prerequisiteWarning !== null) {
                    Notification::make()
                        ->title($prerequisiteWarning['title'])
                        ->body($prerequisiteWarning['body'])
                        ->warning()
                        ->send();

                    return;
                }
                $term = $this->selectedTerm();
                $preview = app(PrepareTermClassProposal::class)->preview($term);
                $cohort = $preview['cohorts']->firstWhere('id', (int) ($data['term_cohort_id'] ?? 0));

                if (! $cohort instanceof TermCohort || $cohort->confirmed_at === null) {
                    throw ValidationException::withMessages([$statePath.'term_cohort_id' => 'Choose a confirmed student section for this term.']);
                }

                $selectedIds = collect($data['entry_ids'] ?? [])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values();

                if ($selectedIds->isEmpty()) {
                    throw ValidationException::withMessages([$statePath.'entry_ids' => 'Select at least one curriculum subject to create.']);
                }

                $catalog = collect($preview['catalog'])->keyBy('id');
                $preferredModality = filled($data['default_modality'] ?? null) ? (string) $data['default_modality'] : null;
                $capacity = max((int) ($data['default_capacity'] ?? $cohort->confirmed_count), 1);
                $rows = [];

                foreach ($selectedIds as $entryId) {
                    $course = $catalog->get($entryId);
                    if (! is_array($course)) {
                        continue;
                    }

                    $modes = is_array($course['modes'] ?? null) ? $course['modes'] : [];
                    $resolvedModality = match (true) {
                        count($modes) === 1 => $modes[0],
                        $preferredModality !== null && in_array($preferredModality, $modes, true) => $preferredModality,
                        $modes !== [] => $modes[0],
                        default => TermOffering::ModalityFaceToFace,
                    };

                    $rows[] = [
                        'entry_id' => $entryId,
                        'section_id' => null,
                        'action' => 'confirm',
                        'code' => '',
                        'capacity' => $capacity,
                        'source' => ($course['term_type'] ?? null) === $term->type ? Section::SourceRegular : Section::SourceAdditional,
                        'modality' => $resolvedModality,
                        'authority_reference' => null,
                        'cohorts' => [
                            ['id' => (int) $cohort->id, 'count' => (int) $cohort->confirmed_count],
                        ],
                    ];
                }

                try {
                    $result = app(PrepareTermClassProposal::class)->confirm($term, $actor, $rows, $preview['token']);
                } catch (ValidationException $exception) {
                    $firstMessage = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Cannot load classes from curriculum')
                        ->body(is_string($firstMessage) ? $firstMessage : 'Review the selected subjects and student section, then try again.')
                        ->danger()
                        ->persistent()
                        ->send();

                    throw ValidationException::withMessages(
                        collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$statePath.'entry_ids' => $messages])->all(),
                    );
                }

                $this->autoSyncSchedulingDemands(force: true);
                $this->resetClassCaches();

                Notification::make()
                    ->title($result['confirmed'].' '.Str::plural('class', $result['confirmed']).' added and confirmed')
                    ->body('Linked to '.$this->formatSectionReference($cohort->reference)." ({$cohort->confirmed_count} students) and ready for timetable generation.")
                    ->success()
                    ->send();
            });
    }

    public function addClassAction(): Action
    {
        return Action::make('addClass')
            ->label('Add class')
            ->icon(Heroicon::OutlinedPlus)
            ->color('gray')
            ->modalHeading('Add class')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Create and confirm an individual or split class for this term in one step.')
            ->modalSubmitActionLabel('Add & confirm class')
            ->modalCancelActionLabel('Cancel')
            ->visible(fn (): bool => $this->termId !== null
                && ! ($this->getViewData()['readOnly'] ?? false)
                && ! $this->hasPublishedTimetable()
                && ! $this->isTermClosed()
                && (bool) auth()->user()?->can('create', TermOffering::class))
            ->extraAttributes(fn (): array => $this->hasClassCreationPrerequisites()
                ? []
                : [
                    'aria-disabled' => 'true',
                    'aria-describedby' => 'term-classes-prereq-notice',
                    'class' => 'tala-action-disabled',
                ])
            ->modal(fn (): bool => $this->hasClassCreationPrerequisites())
            ->fillForm(function (array $arguments): array {
                $cohortId = isset($arguments['cohortId']) ? (int) $arguments['cohortId'] : null;
                if ($cohortId === null || $cohortId <= 0) {
                    return [
                        'cohorts' => [
                            ['id' => null, 'count' => null],
                        ],
                    ];
                }

                $count = $this->cohortHeadcountsById()[$cohortId] ?? null;

                return [
                    'capacity' => $count,
                    'cohorts' => [
                        ['id' => $cohortId, 'count' => $count],
                    ],
                ];
            })
            ->schema(function (): array {
                $preview = $this->currentCurriculumPreview();
                $termType = $this->selectedTerm()->type;
                $lockedEntryIds = $this->lockedCurriculumEntryIdsForTerm();
                $availableCatalog = collect($preview['catalog'])
                    ->reject(fn (array $course): bool => isset($lockedEntryIds[(int) $course['id']]));
                $catalogById = $availableCatalog->keyBy('id');
                $groupedSubjects = $availableCatalog
                    ->groupBy(fn (array $course): string => $course['program'].' · '.$course['curriculum_version'].' · '.$course['year_level'])
                    ->map(fn ($courses): array => $courses->mapWithKeys(fn (array $course): array => [
                        $course['id'] => $course['course_title'].' ('.$course['course_code'].')'.($course['term_type'] !== $termType ? ' · Additional approval required' : ''),
                    ])->all())
                    ->all();

                $confirmedCohorts = $preview['cohorts']->whereNotNull('confirmed_at');
                $cohortOptions = $confirmedCohorts
                    ->mapWithKeys(fn (TermCohort $cohort): array => [$cohort->id => $this->formatSectionReference($cohort->reference).' · '.$cohort->confirmed_count.' students'])
                    ->all();
                $cohortCountsById = $confirmedCohorts->pluck('confirmed_count', 'id')->all();

                return [
                    Select::make('entry_id')
                        ->label('Subject')
                        ->options($groupedSubjects)
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state) use ($catalogById, $termType): void {
                            $course = $catalogById->get((int) $state);
                            if (! is_array($course)) {
                                return;
                            }
                            $set('source', ($course['term_type'] ?? null) === $termType ? Section::SourceRegular : Section::SourceAdditional);
                            $modes = is_array($course['modes'] ?? null) ? $course['modes'] : [];
                            $set('modality', count($modes) === 1 ? $modes[0] : ($modes[0] ?? TermOffering::ModalityFaceToFace));
                        }),
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Select::make('source')
                            ->label('Class source')
                            ->options(Section::confirmableSourceOptions())
                            ->default(Section::SourceRegular)
                            ->required()
                            ->live(),
                        Select::make('modality')
                            ->label('Delivery mode')
                            ->options(function (Get $get) use ($catalogById): array {
                                $course = $catalogById->get((int) $get('entry_id'));
                                $allOptions = TermOffering::modalityOptions();
                                $modes = is_array($course['modes'] ?? null) ? $course['modes'] : [];

                                if ($modes === []) {
                                    return $allOptions;
                                }

                                return array_intersect_key($allOptions, array_flip($modes)) ?: $allOptions;
                            })
                            ->required(),
                    ]),
                    TextInput::make('authority_reference')
                        ->label('Additional class approval reference')
                        ->helperText('Required when offering a subject outside this term’s regular curriculum period or as an additional class.')
                        ->maxLength(255)
                        ->required(fn (Get $get): bool => $get('source') === Section::SourceAdditional)
                        ->visible(fn (Get $get): bool => $get('source') === Section::SourceAdditional),
                    TextInput::make('capacity')
                        ->label('Approved capacity')
                        ->integer()
                        ->minValue(1)
                        ->required(),
                    Repeater::make('cohorts')
                        ->label('Student sections')
                        ->minItems(1)
                        ->defaultItems(1)
                        ->required()
                        ->reorderable(false)
                        ->extraAttributes(['class' => 'tala-cohort-repeater'])
                        ->addActionLabel('Link a student section')
                        ->columns(2)
                        ->schema([
                            Select::make('id')
                                ->label('Student section')
                                ->options($cohortOptions)
                                ->searchable()
                                ->position('top')
                                ->required()
                                ->distinct()
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($cohortCountsById): void {
                                    $defaultCount = $cohortCountsById[(int) $state] ?? null;
                                    if ($defaultCount === null) {
                                        return;
                                    }
                                    $set('count', (int) $defaultCount);
                                    $this->syncCapacityFromCohorts($get, $set, 'count');
                                }),
                            TextInput::make('count')
                                ->label('Students in this class')
                                ->integer()
                                ->minValue(1)
                                ->required(),
                        ]),
                ];
            })
            ->action(function (array $data, ?Schema $schema = null): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $statePath = $schema instanceof Schema ? $schema->getStatePath().'.' : '';
                if ($this->hasPublishedTimetable() || $this->isTermClosed()) {
                    throw ValidationException::withMessages([$statePath.'entry_id' => 'Classes cannot be added after the official timetable is published or when the term is closed.']);
                }
                $prerequisiteWarning = $this->classCreationPrerequisiteWarning();
                if ($prerequisiteWarning !== null) {
                    Notification::make()
                        ->title($prerequisiteWarning['title'])
                        ->body($prerequisiteWarning['body'])
                        ->warning()
                        ->send();

                    return;
                }
                $term = $this->selectedTerm();
                $preview = app(PrepareTermClassProposal::class)->preview($term);

                $row = [
                    'entry_id' => (int) ($data['entry_id'] ?? 0),
                    'section_id' => null,
                    'action' => 'confirm',
                    'code' => '',
                    'capacity' => (int) ($data['capacity'] ?? 0),
                    'source' => (string) ($data['source'] ?? Section::SourceRegular),
                    'modality' => (string) ($data['modality'] ?? TermOffering::ModalityFaceToFace),
                    'authority_reference' => $data['authority_reference'] ?? null,
                    'cohorts' => array_values(array_map(fn (array $cohort): array => [
                        'id' => (int) ($cohort['id'] ?? 0),
                        'count' => (int) ($cohort['count'] ?? 0),
                    ], is_array($data['cohorts'] ?? null) ? $data['cohorts'] : [])),
                ];

                try {
                    app(PrepareTermClassProposal::class)->confirm($term, $actor, [$row], $preview['token']);
                } catch (ValidationException $exception) {
                    $firstMessage = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Cannot add class')
                        ->body(is_string($firstMessage) ? $firstMessage : 'Review the class details and student section counts, then try again.')
                        ->danger()
                        ->persistent()
                        ->send();

                    throw ValidationException::withMessages(
                        collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$statePath.($field === 'classes' ? 'entry_id' : $field) => $messages])->all(),
                    );
                }

                $this->autoSyncSchedulingDemands(force: true);
                $this->resetClassCaches();

                Notification::make()
                    ->title('Class added and confirmed')
                    ->body('Meeting requirements have been synced automatically.')
                    ->success()
                    ->send();
            });
    }

    public function inspectLinkedClassAction(): Action
    {
        return $this->classDetailsAction('inspectLinkedClass')
            ->record(fn (array $arguments): Section => $this->classesQuery()->findOrFail((int) ($arguments['classId'] ?? 0)));
    }

    private function classDetailsAction(string $name): Action
    {
        return Action::make($name)->label('Class details')
            ->modalWidth(Width::ThreeExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription(fn (): string => collect([$this->selectedTerm()->typeLabel(), $this->selectedTerm()->academicYear?->label])->filter()->unique()->implode(' · '))
            ->modalHeading(fn (Section $record): string => $record->courseRevision?->title ?? $record->termOffering?->curriculumEntry?->courseSpecification?->title ?? $record->code)
            ->schema(fn (Schema $schema): Schema => SectionInfolist::configure($schema, showCourseTitle: false))
            ->unsavedChangesAlert(false)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public function confirmClassAction(): Action
    {
        return parent::confirmClassAction()
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
                && $this->termId !== null
                && ! $this->hasPublishedTimetable()
                && ! $this->isTermClosed())
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                if ($this->hasPublishedTimetable() || $this->isTermClosed()) {
                    throw ValidationException::withMessages([$schema->getStatePath().'.cohorts' => 'Classes cannot be confirmed after the official timetable is published or when the term is closed.']);
                }
                $counts = collect($data['cohorts'])->mapWithKeys(fn (array $row): array => [(int) $row['term_cohort_id'] => (int) $row['expected_count']])->all();

                try {
                    DB::transaction(function () use ($actor, $data, $counts): void {
                        $term = Term::query()->whereKey($this->termId)->lockForUpdate()->firstOrFail();
                        $calendar = TermCalendarPackage::query()
                            ->where('term_id', $term->id)
                            ->where('state', TermCalendarPackage::StateActive)
                            ->lockForUpdate()
                            ->first();
                        $section = Section::query()
                            ->where(fn (Builder $query) => $query
                                ->whereHas('calendarPackage', fn (Builder $package) => $package->where('term_id', $term->id))
                                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('term_calendar_package_id')->whereHas('termOffering', fn (Builder $offering) => $offering->where('term_id', $term->id))))
                            ->lockForUpdate()
                            ->findOrFail((int) $data['section_id']);

                        $section = $this->ensureSectionBoundToActiveCalendar(
                            $term,
                            $section,
                            $calendar,
                            $actor,
                            $data['additional_authority_reference'] ?? null,
                        );

                        app(ConfirmClassOffering::class)->execute(
                            $section,
                            $actor,
                            $counts,
                            $data['additional_authority_reference'] ?? null,
                        );
                    }, attempts: 5);
                } catch (ValidationException $exception) {
                    $firstMessage = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Cannot confirm class')
                        ->body(is_string($firstMessage) ? $firstMessage : 'Review the student counts and class setup, then try again.')
                        ->danger()
                        ->persistent()
                        ->send();

                    throw ValidationException::withMessages(
                        collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.'.$field => $messages])->all(),
                    );
                }

                $this->autoSyncSchedulingDemands(force: true);
                $this->resetClassCaches();
                Notification::make()->title('Class confirmed')->success()->send();
            });
    }

    public function editClassAction(): Action
    {
        return Action::make('editClass')
            ->label('Edit')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->button()
            ->color('gray')
            ->modalHeading('Edit class & room requirements')
            ->modalDescription('Update capacity, delivery mode, linked student sections, or room type overrides before the timetable is published. Saving automatically refreshes scheduling requirements.')
            ->modalSubmitActionLabel('Save changes')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth(Width::ThreeExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (Section $record): bool => $this->canEditClass($record))
            ->fillForm(function (Section $record): array {
                $record->loadMissing([
                    'termOffering.curriculumEntry.curriculumVersion',
                    'termOffering.curriculumEntry.courseSpecification.components',
                    'courseRevision.components',
                    'cohorts',
                    'deliveryGroups',
                ]);

                $offering = $record->termOffering;
                $spec = $record->courseRevision ?? $offering?->courseSpecification();
                $components = $spec?->components ?? collect();
                $lectureComponent = $components->firstWhere('component_type', CourseComponent::TypeLecture);
                $labComponent = $components->firstWhere('component_type', CourseComponent::TypeLaboratory);

                return [
                    'capacity' => (int) $record->capacity,
                    'delivery_mode' => $record->delivery_mode ?: $offering?->modality ?: TermOffering::ModalityFaceToFace,
                    'lecture_room_type' => $lectureComponent instanceof CourseComponent
                        ? GenerateSchedulingDemand::resolveRoomType($offering?->room_type_override, $lectureComponent)
                        : null,
                    'laboratory_room_type' => $labComponent instanceof CourseComponent
                        ? GenerateSchedulingDemand::resolveRoomType($offering?->room_type_override, $labComponent)
                        : null,
                    'room_type_override' => $offering?->room_type_override,
                    'sync_latest_curriculum' => $this->hasNewerActiveCurriculumOrSubject($record),
                    'cohorts' => $record->cohorts->map(fn (TermCohort $cohort): array => [
                        'term_cohort_id' => $cohort->id,
                        'expected_count' => (int) $cohort->pivot->expected_count,
                    ])->values()->all(),
                ];
            })
            ->schema(fn (Section $record): array => [
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('capacity')
                        ->label('Approved capacity')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required(),
                    Select::make('delivery_mode')
                        ->label('Delivery mode')
                        ->options(TermOffering::modalityOptions())
                        ->required()
                        ->live(),
                ]),
                Grid::make(['default' => 1, 'md' => 2])
                    ->visible(fn (Get $get): bool => $get('delivery_mode') === TermOffering::ModalityFaceToFace)
                    ->schema([
                        Select::make('lecture_room_type')
                            ->label('Lecture room requirement')
                            ->options(CourseComponent::roomTypeOptions())
                            ->placeholder('Use subject default')
                            ->visible(fn (): bool => $this->hasComponentType($record, CourseComponent::TypeLecture)),
                        Select::make('laboratory_room_type')
                            ->label('Laboratory room requirement')
                            ->options(CourseComponent::roomTypeOptions())
                            ->placeholder('Use subject default')
                            ->visible(fn (): bool => $this->hasComponentType($record, CourseComponent::TypeLaboratory)),
                        Select::make('room_type_override')
                            ->label('Room type requirement')
                            ->options(CourseComponent::roomTypeOptions())
                            ->placeholder('Use subject default')
                            ->visible(fn (): bool => ! $this->hasComponentType($record, CourseComponent::TypeLecture) && ! $this->hasComponentType($record, CourseComponent::TypeLaboratory)),
                    ]),
                Hidden::make('room_type_override')
                    ->dehydrated()
                    ->visible(fn (Get $get): bool => $get('delivery_mode') !== TermOffering::ModalityFaceToFace
                        || $this->hasComponentType($record, CourseComponent::TypeLecture)
                        || $this->hasComponentType($record, CourseComponent::TypeLaboratory)),
                Checkbox::make('sync_latest_curriculum')
                    ->label('Sync latest active subject, curriculum & calendar')
                    ->helperText('Updates this class to use the current active term calendar, curriculum version, and subject revision before refreshing schedule requirements.'),
                Repeater::make('cohorts')
                    ->label('Linked student sections')
                    ->schema([
                        Select::make('term_cohort_id')
                            ->label('Student section')
                            ->options(fn (): array => $this->cohortSelectOptions())
                            ->searchable()
                            ->position('top')
                            ->required()
                            ->distinct()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                $count = $this->cohortHeadcountsById()[(int) $state] ?? null;
                                if ($count === null) {
                                    return;
                                }
                                $set('expected_count', $count);
                                $this->syncCapacityFromCohorts($get, $set, 'expected_count');
                            }),
                        TextInput::make('expected_count')
                            ->label('Students in this class')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required(),
                    ])
                    ->reorderable(false)
                    ->minItems(fn (): int => $record->confirmed_at !== null ? 1 : 0)
                    ->required(fn (): bool => $record->confirmed_at !== null)
                    ->extraAttributes(['class' => 'tala-cohort-repeater'])
                    ->columns(2)
                    ->addActionLabel('Link a student section')
                    ->defaultItems(0),
            ])
            ->action(function (Section $record, array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                Gate::forUser($actor)->authorize('update', $record);

                try {
                    $summary = DB::transaction(function () use ($actor, $record, $data): array {
                        $term = Term::query()->whereKey($this->termId)->lockForUpdate()->firstOrFail();

                        if ($term->state === Term::StateClosed) {
                            throw ValidationException::withMessages(['capacity' => 'Closed Terms cannot be edited.']);
                        }

                        if ($this->hasPublishedTimetable($term)) {
                            throw ValidationException::withMessages(['capacity' => 'Classes cannot be edited here once the official timetable is published. Use Timetable → Revise official schedule.']);
                        }

                        $section = Section::query()
                            ->with([
                                'termOffering.curriculumEntry.curriculumVersion',
                                'termOffering.curriculumEntry.courseSpecification.components',
                                'termOffering.curriculumEntry.courseSpecification.course',
                                'courseRevision.components',
                                'courseRevision.course',
                                'cohorts',
                                'deliveryGroups',
                            ])
                            ->lockForUpdate()
                            ->findOrFail($record->id);

                        if (in_array($section->state, [Section::StateClosed, Section::StateCancelled], true)
                            || PublishedTimetableMeeting::query()->where('section_id', $section->id)->exists()) {
                            throw ValidationException::withMessages(['capacity' => 'Only unpublished, active classes can be edited here. Published classes require a controlled timetable revision.']);
                        }

                        $offering = TermOffering::query()
                            ->with([
                                'curriculumEntry.curriculumVersion',
                                'curriculumEntry.courseSpecification.components',
                                'curriculumEntry.courseSpecification.course',
                            ])
                            ->lockForUpdate()
                            ->findOrFail($section->term_offering_id);

                        $capacity = (int) ($data['capacity'] ?? 0);
                        $deliveryMode = (string) ($data['delivery_mode'] ?? TermOffering::ModalityFaceToFace);

                        if ($capacity < 1) {
                            throw ValidationException::withMessages(['capacity' => 'Approved capacity must be at least 1.']);
                        }

                        if (! array_key_exists($deliveryMode, TermOffering::modalityOptions())) {
                            throw ValidationException::withMessages(['delivery_mode' => 'Choose a valid delivery mode.']);
                        }

                        $cohortRows = is_array($data['cohorts'] ?? null) ? array_values($data['cohorts']) : [];
                        $cohortCounts = [];

                        foreach ($cohortRows as $cohortRow) {
                            $cohortId = (int) ($cohortRow['term_cohort_id'] ?? 0);
                            $count = (int) ($cohortRow['expected_count'] ?? 0);

                            if ($cohortId < 1 || $count < 1) {
                                throw ValidationException::withMessages(['cohorts' => 'Each linked student section requires at least 1 student.']);
                            }

                            $cohortCounts[$cohortId] = $count;
                        }

                        if ($cohortCounts !== []) {
                            $cohorts = TermCohort::query()
                                ->with(['curriculumVersion.entries.courseSpecification'])
                                ->where('term_id', $term->id)
                                ->whereKey(array_keys($cohortCounts))
                                ->lockForUpdate()
                                ->get()
                                ->keyBy('id');

                            if ($cohorts->count() !== count($cohortCounts)) {
                                throw ValidationException::withMessages(['cohorts' => 'Every linked student section must belong to this exact term.']);
                            }

                            $courseRevision = $section->courseRevision ?? $offering->curriculumEntry?->courseSpecification;
                            if (! $courseRevision instanceof CourseSpecification) {
                                $classLabel = $section->class_reference ?: $section->code;
                                throw ValidationException::withMessages(['cohorts' => "Cannot link student sections because {$classLabel} has no recorded subject revision."]);
                            }

                            $equivalentCourseIds = $courseRevision->course_id !== null
                                ? app(RegistrationAcademicEligibilityQuery::class)->expandWithEquivalentCourseIds(collect([(int) $courseRevision->course_id]))
                                : collect();
                            $courseCode = $courseRevision->course?->code
                                ?? $offering->curriculumEntry?->courseSpecification?->course?->code
                                ?? ($section->class_reference ?: $section->code);

                            foreach ($cohortCounts as $cohortId => $count) {
                                $cohort = $cohorts->get($cohortId);

                                if ($cohort instanceof TermCohort && $cohort->confirmed_count !== null && $count > (int) $cohort->confirmed_count) {
                                    $cohortLabel = $this->formatSectionReference($cohort->reference);
                                    throw ValidationException::withMessages(['cohorts' => "Student count for {$cohortLabel} ({$count}) exceeds its confirmed count ({$cohort->confirmed_count})."]);
                                }

                                if ($cohort instanceof TermCohort) {
                                    $cohortLabel = $this->formatSectionReference($cohort->reference);
                                    $curriculum = $cohort->curriculumVersion;
                                    $hasConsistentProgram = $curriculum instanceof CurriculumVersion
                                        && (int) $curriculum->program_id === (int) $cohort->program_id;
                                    $isPublishedCurriculum = $curriculum instanceof CurriculumVersion
                                        && in_array($curriculum->state, [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded], true);
                                    $containsCourse = $curriculum instanceof CurriculumVersion
                                        && $curriculum->entries->contains(function (CurriculumEntry $entry) use ($courseRevision, $equivalentCourseIds): bool {
                                            $entrySpecification = $entry->courseSpecification;

                                            return (int) $entry->course_specification_id === (int) $courseRevision->id
                                                || ($entrySpecification instanceof CourseSpecification
                                                    && $courseRevision->course_id !== null
                                                    && ((int) $entrySpecification->course_id === (int) $courseRevision->course_id
                                                        || $equivalentCourseIds->contains((int) $entrySpecification->course_id)));
                                        });

                                    if (! $hasConsistentProgram || ! $isPublishedCurriculum || ! $containsCourse) {
                                        throw ValidationException::withMessages([
                                            'cohorts' => "{$cohortLabel} cannot be linked because its curriculum does not contain {$courseCode} (or an approved equivalent).",
                                        ]);
                                    }
                                }
                            }

                            if (array_sum($cohortCounts) > $capacity) {
                                throw ValidationException::withMessages(['capacity' => 'Total linked student count ('.array_sum($cohortCounts).') exceeds approved class capacity ('.$capacity.').']);
                            }
                        } elseif ($section->confirmed_at !== null) {
                            throw ValidationException::withMessages(['cohorts' => 'A confirmed class must keep at least one linked student section.']);
                        }

                        if ((bool) ($data['sync_latest_curriculum'] ?? false)) {
                            $offering = $this->syncOfferingAndSectionToLatestActive($term, $offering, $section);
                        }

                        $spec = $section->courseRevision()->with('components')->first()
                            ?? $offering->fresh(['curriculumEntry.courseSpecification.components'])?->courseSpecification();
                        $components = $spec?->components ?? collect();
                        $hasLecture = $components->contains(fn (CourseComponent $c): bool => $c->component_type === CourseComponent::TypeLecture);
                        $hasLab = $components->contains(fn (CourseComponent $c): bool => $c->component_type === CourseComponent::TypeLaboratory);

                        $lectureRoom = filled($data['lecture_room_type'] ?? null) ? trim((string) $data['lecture_room_type']) : null;
                        $labRoom = filled($data['laboratory_room_type'] ?? null) ? trim((string) $data['laboratory_room_type']) : null;
                        $rawOverride = filled($data['room_type_override'] ?? null) ? trim((string) $data['room_type_override']) : null;

                        if ($deliveryMode !== TermOffering::ModalityFaceToFace) {
                            $roomTypeOverride = null;
                        } elseif ($hasLecture && $hasLab) {
                            $overrideMap = array_filter([
                                CourseComponent::TypeLecture => $lectureRoom,
                                CourseComponent::TypeLaboratory => $labRoom,
                            ], fn (?string $value): bool => $value !== null);

                            if ($overrideMap !== []) {
                                $roomTypeOverride = json_encode($overrideMap, JSON_THROW_ON_ERROR);
                            } else {
                                $roomTypeOverride = $rawOverride;
                            }
                        } elseif ($hasLecture) {
                            $roomTypeOverride = $lectureRoom ?? $rawOverride;
                        } elseif ($hasLab) {
                            $roomTypeOverride = $labRoom ?? $rawOverride;
                        } else {
                            $roomTypeOverride = $lectureRoom ?? $labRoom ?? $rawOverride;
                        }

                        $expectedTotal = $cohortCounts !== []
                            ? array_sum($cohortCounts)
                            : max(1, (int) ($section->deliveryGroups->sum('expected_count') ?: $offering->expected_count ?: $capacity));

                        $section->forceFill([
                            'term_offering_id' => $offering->id,
                            'capacity' => $capacity,
                            'delivery_mode' => $deliveryMode,
                        ])->save();

                        $offering->forceFill([
                            'modality' => $deliveryMode,
                            'room_type_override' => $roomTypeOverride,
                            'expected_count' => max((int) $offering->expected_count, $expectedTotal),
                        ])->save();

                        if ($cohortCounts !== []) {
                            $section->cohorts()->sync(collect($cohortCounts)->mapWithKeys(
                                fn (int $count, int $cohortId): array => [$cohortId => ['expected_count' => $count]],
                            )->all());
                        }

                        $groups = $section->deliveryGroups()->whereNotIn('state', [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled])->get();

                        if ($groups->isEmpty() && $section->confirmed_at !== null) {
                            $section->deliveryGroups()->create([
                                'name' => $section->class_reference ?: $section->code,
                                'expected_count' => $expectedTotal,
                                'modality' => $deliveryMode,
                                'state' => SectionDeliveryGroup::StateReady,
                            ]);
                        } elseif ($groups->count() === 1) {
                            $group = $groups->first();
                            $group->forceFill([
                                'expected_count' => $expectedTotal,
                                'modality' => $deliveryMode,
                                'state' => $section->confirmed_at !== null ? SectionDeliveryGroup::StateReady : $group->state,
                            ])->save();
                        } else {
                            foreach ($groups as $group) {
                                $group->forceFill([
                                    'modality' => $deliveryMode,
                                    'expected_count' => min((int) $group->expected_count, $capacity),
                                ])->save();
                            }
                        }

                        ScheduleGenerationRun::query()
                            ->where('term_id', $term->id)
                            ->whereIn('status', [
                                ScheduleGenerationRun::StatusQueued,
                                ScheduleGenerationRun::StatusDispatching,
                                ScheduleGenerationRun::StatusUnderReview,
                            ])
                            ->update(['candidate_state' => 'Stale', 'updated_at' => now()]);

                        return app(GenerateSchedulingDemand::class)->forTerm($actor, $term);
                    }, attempts: 5);
                } catch (ValidationException $exception) {
                    $firstMessage = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Cannot save class changes')
                        ->body(is_string($firstMessage) ? $firstMessage : 'Review the class details and try again.')
                        ->danger()
                        ->persistent()
                        ->send();

                    throw ValidationException::withMessages(
                        collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.'.$field => $messages])->all(),
                    );
                }

                $this->resetClassCaches();
                Notification::make()
                    ->title('Class and room requirements updated')
                    ->body("Schedule requirements refreshed ({$summary['ready']} ready · {$summary['action_required']} needing attention).")
                    ->success()
                    ->send();
            });
    }

    public function deleteDraftClassAction(): Action
    {
        return Action::make('deleteDraftClass')
            ->label('Delete draft')
            ->icon(Heroicon::OutlinedTrash)
            ->button()
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (Section $record): string => 'Delete unconfirmed draft class · '.($record->class_reference ?: $record->code))
            ->modalDescription('Permanently removes this unconfirmed draft class and its unreferenced scheduling requirements. This cannot be undone.')
            ->modalSubmitActionLabel('Delete draft class')
            ->modalCancelActionLabel('Keep draft')
            ->visible(fn (Section $record): bool => $this->canDeleteDraftClass($record))
            ->action(function (Section $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                Gate::forUser($actor)->authorize('update', $record);

                try {
                    DB::transaction(function () use ($record, $actor): void {
                        $term = Term::query()->whereKey($this->termId)->lockForUpdate()->firstOrFail();

                        if ($term->state === Term::StateClosed || $this->hasPublishedTimetable($term)) {
                            throw ValidationException::withMessages(['class' => 'Draft classes cannot be deleted once the timetable is published or the term is closed.']);
                        }

                        $section = Section::query()
                            ->with(['deliveryGroups', 'termOffering'])
                            ->lockForUpdate()
                            ->findOrFail($record->id);

                        if (! $this->isUntouchedDraftSection($section)) {
                            throw ValidationException::withMessages(['class' => 'Only unconfirmed draft classes with no meetings, candidate assignments, or enrollments can be deleted. Cancel the class instead.']);
                        }

                        $offeringId = $section->term_offering_id;
                        $sectionCode = $section->code;
                        $sectionId = $section->id;
                        $groupIds = $section->deliveryGroups->modelKeys();

                        if ($groupIds !== []) {
                            SchedulingDemand::query()
                                ->whereIn('section_delivery_group_id', $groupIds)
                                ->delete();

                            SectionDeliveryGroup::query()
                                ->whereIn('id', $groupIds)
                                ->delete();
                        }

                        $section->cohorts()->detach();
                        $section->delete();

                        if ($offeringId !== null
                            && ! Section::query()->where('term_offering_id', $offeringId)->exists()
                            && ! SchedulingDemand::query()->where('term_offering_id', $offeringId)->exists()) {
                            TermOffering::query()
                                ->whereKey($offeringId)
                                ->delete();
                        }

                        ScheduleGenerationRun::query()
                            ->where('term_id', $term->id)
                            ->whereIn('status', [
                                ScheduleGenerationRun::StatusQueued,
                                ScheduleGenerationRun::StatusDispatching,
                                ScheduleGenerationRun::StatusUnderReview,
                            ])
                            ->update(['candidate_state' => 'Stale', 'updated_at' => now()]);

                        activity()
                            ->causedBy($actor)
                            ->event('draft_class_deleted')
                            ->withProperties(['term_id' => $term->id, 'section_id' => $sectionId, 'code' => $sectionCode])
                            ->log('Deleted unconfirmed draft class');
                    }, attempts: 5);
                } catch (ValidationException $exception) {
                    $firstMessage = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Cannot delete draft class')
                        ->body(is_string($firstMessage) ? $firstMessage : 'This class cannot be deleted.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                } catch (QueryException) {
                    Notification::make()
                        ->title('Cannot delete draft class')
                        ->body('This class is still referenced by linked records. Cancel it instead.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $this->resetClassCaches();
                Notification::make()
                    ->title('Draft class deleted')
                    ->success()
                    ->send();
            });
    }

    private function canDeleteDraftClass(Section $class): bool
    {
        return $this->canEditClass($class) && $this->isUntouchedDraftSection($class);
    }

    private function isUntouchedDraftSection(Section $section): bool
    {
        if ($section->confirmed_at !== null
            || in_array($section->state, [Section::StateCancelled, Section::StateClosed], true)) {
            return false;
        }

        if (PublishedTimetableMeeting::query()->where('section_id', $section->id)->exists()
            || CourseEnrollment::query()->where('section_id', $section->id)->exists()
            || EnrollmentSeatReservation::query()->where('section_id', $section->id)->exists()) {
            return false;
        }

        $groupIds = $section->relationLoaded('deliveryGroups')
            ? $section->deliveryGroups->modelKeys()
            : $section->deliveryGroups()->pluck('id')->all();

        if ($groupIds === []) {
            return true;
        }

        return ! SchedulingDemand::query()
            ->whereIn('section_delivery_group_id', $groupIds)
            ->where(function (Builder $query): void {
                $query->whereHas('sectionMeetings')
                    ->orWhereHas('candidateRows');
            })
            ->exists();
    }

    public function cancelClassAction(): Action
    {
        return Action::make('cancelClass')
            ->label('Cancel class')
            ->icon(Heroicon::OutlinedXCircle)
            ->button()
            ->color('danger')
            ->modalHeading(fn (Section $record): string => 'Cancel class · '.($record->class_reference ?: $record->code))
            ->modalDescription('Cancels this unpublished class for the selected term. Once cancelled, it is removed from timetable generation.')
            ->modalSubmitActionLabel('Cancel class')
            ->modalCancelActionLabel('Keep class')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (Section $record): bool => $this->canEditClass($record))
            ->schema([
                Textarea::make('cancellation_reason')
                    ->label('Reason for cancelling')
                    ->placeholder('e.g., Combined with another section or insufficient enrollment')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (Section $record, array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                Gate::forUser($actor)->authorize('update', $record);

                $reason = trim((string) ($data['cancellation_reason'] ?? ''));
                if ($reason === '') {
                    throw ValidationException::withMessages([$schema->getStatePath().'.cancellation_reason' => 'A cancellation reason is required.']);
                }

                try {
                    DB::transaction(function () use ($record, $actor, $reason): void {
                        $term = Term::query()->whereKey($this->termId)->lockForUpdate()->firstOrFail();

                        if ($term->state === Term::StateClosed) {
                            throw ValidationException::withMessages(['cancellation_reason' => 'Closed terms cannot be modified.']);
                        }

                        if (PublishedTimetableVersion::query()
                            ->where('term_id', $term->id)
                            ->where('state', PublishedTimetableVersion::StatePublished)
                            ->exists()) {
                            throw ValidationException::withMessages(['cancellation_reason' => 'Published classes must be cancelled via Timetable → Revise official schedule.']);
                        }

                        $section = Section::query()
                            ->with(['deliveryGroups'])
                            ->lockForUpdate()
                            ->findOrFail($record->id);

                        if (in_array($section->state, [Section::StateCancelled, Section::StateClosed], true)) {
                            throw ValidationException::withMessages(['cancellation_reason' => 'This class is already cancelled or closed.']);
                        }

                        if (PublishedTimetableMeeting::query()->where('section_id', $section->id)->exists()
                            || CourseEnrollment::query()->where('section_id', $section->id)->where('is_current', true)->exists()
                            || EnrollmentSeatReservation::query()->where('section_id', $section->id)->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->exists()) {
                            throw ValidationException::withMessages(['cancellation_reason' => 'This class has published meetings or active student enrollments and cannot be cancelled here.']);
                        }

                        $section->forceFill(['state' => Section::StateCancelled])->save();

                        $groupIds = $section->deliveryGroups->modelKeys();
                        if ($groupIds !== []) {
                            SectionDeliveryGroup::query()
                                ->whereIn('id', $groupIds)
                                ->whereNotIn('state', [SectionDeliveryGroup::StateCancelled, SectionDeliveryGroup::StateClosed])
                                ->update(['state' => SectionDeliveryGroup::StateCancelled, 'updated_at' => now()]);

                            SchedulingDemand::query()
                                ->whereIn('section_delivery_group_id', $groupIds)
                                ->whereDoesntHave('sectionMeetings')
                                ->delete();
                        }

                        ScheduleGenerationRun::query()
                            ->where('term_id', $term->id)
                            ->whereIn('status', [
                                ScheduleGenerationRun::StatusQueued,
                                ScheduleGenerationRun::StatusDispatching,
                                ScheduleGenerationRun::StatusUnderReview,
                            ])
                            ->update(['candidate_state' => 'Stale', 'updated_at' => now()]);

                        activity()
                            ->causedBy($actor)
                            ->performedOn($section)
                            ->event('class_cancelled')
                            ->withProperties(['term_id' => $term->id, 'reason' => $reason])
                            ->log('class_cancelled');
                    }, attempts: 5);
                } catch (ValidationException $exception) {
                    $firstMessage = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Cannot cancel class')
                        ->body(is_string($firstMessage) ? $firstMessage : 'This class cannot be cancelled.')
                        ->danger()
                        ->persistent()
                        ->send();

                    throw ValidationException::withMessages(
                        collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.cancellation_reason' => $messages])->all(),
                    );
                }

                $this->autoSyncSchedulingDemands(force: true);
                $this->resetClassCaches();

                Notification::make()
                    ->title('Class cancelled')
                    ->body(($record->class_reference ?: $record->code).' has been removed from timetable generation.')
                    ->success()
                    ->send();
            });
    }

    private function canModifyUnlinkedCohort(TermCohort $cohort): bool
    {
        if (! (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
            || ($this->getViewData()['readOnly'] ?? false)
            || $this->isTermClosed()
            || $this->hasPublishedTimetable()) {
            return false;
        }

        $hasClasses = $cohort->relationLoaded('classOfferings')
            ? $cohort->classOfferings->where('state', '!=', Section::StateCancelled)->isNotEmpty()
            : $cohort->classOfferings()->where('sections.state', '!=', Section::StateCancelled)->exists();

        $hasEnrollments = isset($cohort->enrollments_count)
            ? (int) $cohort->enrollments_count > 0
            : ($cohort->relationLoaded('enrollments')
                ? $cohort->enrollments->isNotEmpty()
                : $cohort->enrollments()->exists());

        return ! $hasClasses && ! $hasEnrollments;
    }

    private function editCohortAction(): Action
    {
        return Action::make('editCohort')
            ->label('Edit')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->button()
            ->color('gray')
            ->size('sm')
            ->extraAttributes(['class' => 'whitespace-nowrap'])
            ->modalHeading(fn (TermCohort $record): string => 'Edit student section · '.$this->formatSectionReference($record->reference))
            ->modalDescription('Update the curriculum, year level, section name, or expected student headcount while no classes are linked yet.')
            ->modalSubmitActionLabel('Save changes')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth(Width::TwoExtraLarge)
            ->visible(fn (TermCohort $record): bool => $this->canModifyUnlinkedCohort($record))
            ->fillForm(fn (TermCohort $record): array => [
                'curriculum_version_id' => $record->curriculum_version_id,
                'year_level' => $record->year_level ?? $record->resolvedYearLevel() ?? 1,
                'reference' => $this->formatSectionReference($record->reference),
                'confirmed_count' => $record->confirmed_count ?? $record->forecast_count,
            ])
            ->schema(function (TermCohort $record): array {
                $curricula = CurriculumVersion::query()
                    ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                    ->with(['program', 'academicYears'])
                    ->get()
                    ->groupBy('program_id')
                    ->sortBy(fn (Collection $group): string => (string) ($group->first()?->program?->code ?? ''))
                    ->flatMap(fn (Collection $group): Collection => CurriculumVersion::sortByLatestBatch($group, groupByState: true))
                    ->keyBy('id');

                return [
                    Select::make('curriculum_version_id')
                        ->label('Program & curriculum')
                        ->options(fn (): array => $curricula
                            ->mapWithKeys(fn (CurriculumVersion $curriculum): array => [
                                $curriculum->id => $curriculum->program?->name.' · '.$curriculum->batchLabel().($curriculum->isRetired() ? ' (Retired)' : ''),
                            ])
                            ->all())
                        ->required()
                        ->searchable()
                        ->live(),
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Select::make('year_level')
                            ->label('Year level')
                            ->options(function (Get $get) use ($curricula): array {
                                $curriculum = $curricula->get((int) $get('curriculum_version_id'));
                                $duration = (int) ($curriculum?->program?->duration_years ?? 4);
                                $maxYear = max(1, min(8, $duration > 0 ? $duration : 4));
                                $options = [];
                                for ($year = 1; $year <= $maxYear; $year++) {
                                    $options[$year] = "Year {$year}";
                                }

                                return $options;
                            })
                            ->required(),
                        TextInput::make('reference')
                            ->label('Section name')
                            ->placeholder('e.g. BSIT 1-1')
                            ->required()
                            ->maxLength(64)
                            ->rule(Rule::unique('term_cohorts', 'reference')->where('term_id', $this->termId)->ignore($record->id)),
                    ]),
                    TextInput::make('confirmed_count')
                        ->label('Expected students')
                        ->integer()
                        ->minValue(1)
                        ->required(),
                ];
            })
            ->action(function (TermCohort $record, array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                Gate::forUser($actor)->authorize('create', Section::class);

                try {
                    DB::transaction(function () use ($record, $data, $actor): void {
                        $term = Term::query()->whereKey($this->termId)->lockForUpdate()->firstOrFail();

                        if ($term->state === Term::StateClosed || $this->hasPublishedTimetable($term)) {
                            throw ValidationException::withMessages(['reference' => 'Student sections cannot be edited once the timetable is published or the term is closed.']);
                        }

                        $locked = TermCohort::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

                        if ($locked->classOfferings()->where('sections.state', '!=', Section::StateCancelled)->exists() || $locked->enrollments()->exists()) {
                            throw ValidationException::withMessages(['reference' => 'This student section already has linked classes or enrollments and cannot be edited here.']);
                        }

                        $curriculum = CurriculumVersion::query()->lockForUpdate()->findOrFail((int) $data['curriculum_version_id']);
                        if (! in_array($curriculum->state, [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded], true)) {
                            throw ValidationException::withMessages(['curriculum_version_id' => 'Choose a published (Active or Retired) curriculum.']);
                        }

                        $reference = RecordTermCohort::normalizeReference((string) ($data['reference'] ?? ''));
                        $duplicate = TermCohort::query()
                            ->where('term_id', $term->id)
                            ->where('reference', $reference)
                            ->whereKeyNot($locked->id)
                            ->exists();

                        if ($duplicate) {
                            throw ValidationException::withMessages(['reference' => 'That student section name is already used in this term.']);
                        }

                        $locked->update([
                            'program_id' => $curriculum->program_id,
                            'curriculum_version_id' => $curriculum->id,
                            'year_level' => (int) $data['year_level'],
                            'reference' => $reference,
                            'confirmed_count' => (int) $data['confirmed_count'],
                        ]);

                        activity()
                            ->causedBy($actor)
                            ->performedOn($locked)
                            ->event('term_cohort_updated')
                            ->withProperties(['term_id' => $term->id, 'reference' => $reference])
                            ->log('Updated unlinked student section');
                    }, attempts: 5);
                } catch (ValidationException $exception) {
                    $firstMessage = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Cannot update student section')
                        ->body(is_string($firstMessage) ? $firstMessage : 'Review the student section details and try again.')
                        ->danger()
                        ->persistent()
                        ->send();

                    throw ValidationException::withMessages(
                        collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.'.$field => $messages])->all(),
                    );
                }

                $this->resetClassCaches();

                Notification::make()
                    ->title('Student section updated')
                    ->success()
                    ->send();
            });
    }

    private function deleteCohortAction(): Action
    {
        return Action::make('deleteCohort')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->button()
            ->color('danger')
            ->size('sm')
            ->extraAttributes(['class' => 'whitespace-nowrap'])
            ->requiresConfirmation()
            ->modalHeading(fn (TermCohort $record): string => 'Delete student section '.$this->formatSectionReference($record->reference).'?')
            ->modalDescription('This unlinked student section has no linked classes or enrollments and will be permanently removed from this term.')
            ->modalSubmitActionLabel('Delete section')
            ->modalCancelActionLabel('Cancel')
            ->visible(fn (TermCohort $record): bool => $this->canModifyUnlinkedCohort($record))
            ->action(function (TermCohort $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                Gate::forUser($actor)->authorize('create', Section::class);

                try {
                    DB::transaction(function () use ($record, $actor): void {
                        $term = Term::query()->whereKey($this->termId)->lockForUpdate()->firstOrFail();

                        if ($term->state === Term::StateClosed || $this->hasPublishedTimetable($term)) {
                            throw ValidationException::withMessages(['cohort' => 'Student sections cannot be deleted once the timetable is published or the term is closed.']);
                        }

                        $locked = TermCohort::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

                        if ($locked->classOfferings()->where('sections.state', '!=', Section::StateCancelled)->exists() || $locked->enrollments()->exists()) {
                            throw ValidationException::withMessages(['cohort' => 'This student section already has linked classes or enrollments and cannot be deleted.']);
                        }

                        $cancelledSectionIds = $locked->classOfferings()
                            ->where('sections.state', Section::StateCancelled)
                            ->pluck('sections.id')
                            ->all();
                        if ($cancelledSectionIds !== []) {
                            $locked->classOfferings()->detach($cancelledSectionIds);
                        }

                        $reference = $locked->reference;
                        $id = $locked->id;
                        $locked->delete();

                        activity()
                            ->causedBy($actor)
                            ->event('term_cohort_deleted')
                            ->withProperties(['term_id' => $term->id, 'term_cohort_id' => $id, 'reference' => $reference])
                            ->log('Deleted unlinked student section');
                    }, attempts: 5);
                } catch (ValidationException $exception) {
                    $firstMessage = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Cannot delete student section')
                        ->body(is_string($firstMessage) ? $firstMessage : 'This student section is still in use.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                } catch (QueryException) {
                    Notification::make()
                        ->title('Cannot delete student section')
                        ->body('This student section is still referenced by linked records.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $this->resetClassCaches();

                Notification::make()
                    ->title('Student section deleted')
                    ->success()
                    ->send();
            });
    }

    private function classesQuery(): Builder
    {
        return Section::query()
            ->select('sections.*')
            ->addSelect([
                'is_published' => PublishedTimetableMeeting::query()
                    ->selectRaw('1')
                    ->whereColumn('section_id', 'sections.id')
                    ->limit(1),
            ])
            ->where(fn (Builder $query) => $query->whereHas('calendarPackage', fn (Builder $package) => $package->where('term_id', $this->termId ?? 0))
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('term_calendar_package_id')->whereHas('termOffering', fn (Builder $offering) => $offering->where('term_id', $this->termId ?? 0))))
            ->with([
                'termOffering.curriculumEntry.curriculumVersion.program',
                'termOffering.curriculumEntry.curriculumVersion.academicYears',
                'termOffering.curriculumEntry.courseSpecification.course',
                'termOffering.curriculumEntry.courseSpecification.components',
                'courseRevision.course',
                'courseRevision.components',
                'cohorts.program',
                'cohorts.curriculumVersion.academicYears',
                'deliveryGroups.schedulingDemands.courseComponent',
                'calendarPackage.teachingGridRows',
                'calendarPackage.datedExceptions',
            ]);
    }

    /** @return Collection<int, Program> */
    private function linkedProgramsForClass(Section $record): Collection
    {
        $hostProgram = $record->termOffering?->curriculumEntry?->curriculumVersion?->program;
        $cohortPrograms = $record->cohorts
            ->pluck('program')
            ->filter(fn (mixed $program): bool => $program instanceof Program)
            ->sortBy(fn (Program $program): string => strtoupper((string) ($program->code ?: $program->name)))
            ->values();

        return collect([$hostProgram])
            ->filter(fn (mixed $program): bool => $program instanceof Program)
            ->merge($cohortPrograms)
            ->unique('id')
            ->values();
    }

    private function canEditClass(Section $class): bool
    {
        if (! (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
            || ! (bool) auth()->user()?->can('update', $class)) {
            return false;
        }

        if ($this->isTermClosed()
            || $this->hasPublishedTimetable()
            || in_array($class->state, [Section::StateClosed, Section::StateCancelled], true)) {
            return false;
        }

        if ($class->getAttribute('is_published')) {
            return false;
        }

        return ! PublishedTimetableMeeting::query()->where('section_id', $class->id)->exists();
    }

    private function hasComponentType(Section $class, string $componentType): bool
    {
        $spec = $class->courseRevision ?? $class->termOffering?->courseSpecification();
        $components = $spec?->components ?? collect();

        return $components->contains(fn (CourseComponent $component): bool => $component->component_type === $componentType);
    }

    private function hasNewerActiveCurriculumOrSubject(Section $class): bool
    {
        if ($this->termId !== null) {
            $activePackage = TermCalendarPackage::query()
                ->where('term_id', $this->termId)
                ->where('state', TermCalendarPackage::StateActive)
                ->with(['teachingGridRows', 'datedExceptions'])
                ->first();

            if ($activePackage instanceof TermCalendarPackage) {
                $classPackage = $class->calendarPackage;
                if (! $classPackage instanceof TermCalendarPackage
                    || ! $classPackage->isActivatedCoreSource()
                    || $classPackage->coreSignature() !== $activePackage->coreSignature()) {
                    return true;
                }
            }
        }

        $offering = $class->termOffering;
        $currentEntry = $offering?->curriculumEntry;
        $currentSpec = $class->courseRevision ?? $currentEntry?->courseSpecification;
        $courseId = $currentSpec?->course_id;
        $targetCurriculumVersionId = $class->cohorts->first()?->curriculum_version_id ?? $currentEntry?->curriculum_version_id;

        if (! $courseId) {
            return false;
        }

        if ($currentSpec && $currentSpec->state !== CourseSpecification::StateActive) {
            return CourseSpecification::query()
                ->where('course_id', $courseId)
                ->where('state', CourseSpecification::StateActive)
                ->exists();
        }

        $latestActiveSpecId = CourseSpecification::query()
            ->where('course_id', $courseId)
            ->where('state', CourseSpecification::StateActive)
            ->max('id');

        if ($latestActiveSpecId && (int) $latestActiveSpecId !== (int) $currentSpec?->id) {
            return true;
        }

        if ($targetCurriculumVersionId && (int) $currentEntry?->curriculum_version_id !== (int) $targetCurriculumVersionId) {
            return CurriculumEntry::query()
                ->where('curriculum_version_id', $targetCurriculumVersionId)
                ->whereHas('courseSpecification', fn (Builder $q) => $q->where('course_id', $courseId))
                ->exists();
        }

        return false;
    }

    private function syncOfferingAndSectionToLatestActive(Term $term, TermOffering $offering, Section $section): TermOffering
    {
        $currentEntry = $offering->curriculumEntry;
        $currentSpec = $section->courseRevision ?? $currentEntry?->courseSpecification;
        $courseId = $currentSpec?->course_id;
        $targetCurriculumVersionId = $section->cohorts->first()?->curriculum_version_id
            ?? $currentEntry?->curriculum_version_id;

        if (! $courseId) {
            return $offering;
        }

        $latestActiveSpec = CourseSpecification::query()
            ->where('course_id', $courseId)
            ->where('state', CourseSpecification::StateActive)
            ->with('components')
            ->latest('id')
            ->first();

        // Scope strictly to the cohort's own curriculum_version_id; never overwrite cohort curriculum versions across batches.
        $scopedEntry = $targetCurriculumVersionId
            ? CurriculumEntry::query()
                ->where('curriculum_version_id', $targetCurriculumVersionId)
                ->whereHas('courseSpecification', fn (Builder $q) => $q->where('course_id', $courseId))
                ->with(['curriculumVersion', 'courseSpecification.components'])
                ->orderByRaw('CASE WHEN term_type = ? THEN 0 ELSE 1 END', [$term->type])
                ->latest('id')
                ->first()
            : null;

        if ($scopedEntry instanceof CurriculumEntry && (int) $offering->curriculum_entry_id !== (int) $scopedEntry->id) {
            $existingOffering = TermOffering::query()
                ->where('term_id', $term->id)
                ->where('curriculum_entry_id', $scopedEntry->id)
                ->where('delivery_variant', $offering->delivery_variant)
                ->whereKeyNot($offering->id)
                ->lockForUpdate()
                ->first();

            if ($existingOffering instanceof TermOffering) {
                $section->term_offering_id = $existingOffering->id;
                $offering = $existingOffering;
            } else {
                $offering->update(['curriculum_entry_id' => $scopedEntry->id]);
            }
        }

        $targetSpecId = $latestActiveSpec?->id ?? $scopedEntry?->course_specification_id;

        if ($targetSpecId !== null) {
            $section->course_specification_id = $targetSpecId;
        }

        $activePackage = TermCalendarPackage::query()
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->first();

        if ($activePackage instanceof TermCalendarPackage && $section->term_calendar_package_id !== $activePackage->id) {
            $section->term_calendar_package_id = $activePackage->id;
        }

        $section->save();

        ScheduleGenerationRun::query()
            ->where('term_id', $term->id)
            ->whereIn('status', [
                ScheduleGenerationRun::StatusQueued,
                ScheduleGenerationRun::StatusDispatching,
                ScheduleGenerationRun::StatusUnderReview,
            ])
            ->update(['candidate_state' => 'Stale', 'updated_at' => now()]);

        $activeComponentIds = $latestActiveSpec?->components?->modelKeys()
            ?? $scopedEntry?->courseSpecification?->components?->modelKeys()
            ?? [];
        $sectionGroupIds = $section->deliveryGroups->modelKeys();

        if ($activeComponentIds !== [] && $sectionGroupIds !== []) {
            SchedulingDemand::query()
                ->whereIn('section_delivery_group_id', $sectionGroupIds)
                ->whereNotIn('course_component_id', $activeComponentIds)
                ->whereDoesntHave('sectionMeetings')
                ->delete();
        }

        return $offering->fresh(['curriculumEntry.curriculumVersion', 'curriculumEntry.courseSpecification.components']);
    }

    private function roomRequirementSummary(Section $class): string
    {
        $modality = $class->delivery_mode ?: $class->termOffering?->modality;

        if ($modality === TermOffering::ModalityOnline) {
            return 'Online';
        }

        $offering = $class->termOffering;
        $spec = $class->courseRevision ?? $offering?->courseSpecification();
        $components = $spec?->components ?? collect();

        if ($components->isEmpty()) {
            $fallback = $offering?->room_type_override;

            return filled($fallback) ? $this->roomTypeHumanLabel((string) $fallback) : 'Room type needed';
        }

        $labels = $components
            ->sortBy(fn (CourseComponent $component): string => sprintf('%06d|%010d', (int) $component->sequence, (int) $component->id))
            ->map(function (CourseComponent $component) use ($offering): string {
                $resolved = GenerateSchedulingDemand::resolveRoomType($offering?->room_type_override, $component);

                if (blank($resolved)) {
                    return 'Room type needed';
                }

                return $this->roomTypeHumanLabel($resolved);
            })
            ->unique()
            ->values()
            ->all();

        return implode(' + ', $labels);
    }

    private function roomTypeHumanLabel(string $roomType): string
    {
        return match (strtoupper(trim($roomType))) {
            CourseComponent::RoomTypeLectureRoom, 'LECTURE' => 'Lecture room',
            CourseComponent::RoomTypeComputerLaboratory => 'Computer lab',
            CourseComponent::RoomTypeLaboratory => 'Laboratory',
            Room::TypeSpecialRoom => 'Special room',
            Room::TypeOnlineNoPhysicalRoom => 'Online',
            default => Room::typeOptions()[$roomType] ?? CourseComponent::roomTypeOptions()[$roomType] ?? Str::headline(strtolower($roomType)),
        };
    }

    private function inlineFindingsDescription(Section $class): ?string
    {
        $messages = $this->demands($class)
            ->flatMap(fn (SchedulingDemand $demand): array => $demand->readinessFindings())
            ->pluck('message')
            ->filter()
            ->unique()
            ->values();

        if ($messages->isEmpty()) {
            $state = $this->preparation($class);

            return match ($state) {
                'Needs active calendar source' => 'Needs active calendar source',
                'Needs active subject revision' => 'Subject revision is inactive. Click Edit and sync the latest active subject.',
                'Needs student sections' => 'Link at least one confirmed student section.',
                'Needs meeting requirements' => 'Weekly meeting pattern is missing or incomplete. Click Edit to update or review the subject setup.',
                default => null,
            };
        }

        return $messages->implode(' · ');
    }

    private function classConfirmationSource(Section $section): string
    {
        $facts = [];
        foreach (['id', 'code', 'term_offering_id', 'term_calendar_package_id', 'course_specification_id', 'class_reference', 'source', 'delivery_mode', 'capacity', 'state', 'authority_reference', 'confirmed_at', 'confirmed_by'] as $field) {
            $facts[$field] = $section->getRawOriginal($field);
        }
        $facts['cohorts'] = $section->cohorts->sortBy('id')->map(fn ($cohort): array => [(int) $cohort->id, (int) $cohort->pivot->expected_count])->values()->all();
        $facts['offering'] = $section->termOffering?->only(['curriculum_entry_id', 'category', 'modality', 'expected_count', 'state']);
        $facts['curriculum_entry'] = $section->termOffering?->curriculumEntry?->only(['curriculum_version_id', 'course_specification_id']);

        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
    }

    private function cohortConfirmationSource(TermCohort $cohort): string
    {
        return hash('sha256', json_encode($cohort->only([
            'id', 'term_id', 'program_id', 'curriculum_version_id', 'reference', 'source',
            'forecast_count', 'confirmed_count', 'state', 'confirmed_by', 'confirmed_at',
        ]), JSON_THROW_ON_ERROR));
    }

    /** @return list<array<string, mixed>> */
    private function coverageRows(): array
    {
        if ($this->coverageScope === null) {
            $term = $this->termId ? Term::find($this->termId) : null;
            $this->coverageScope = $term ? app(TermCoverageProjection::class)->forTerm($term) : ['rows' => [], 'additional' => []];
        }

        return array_merge($this->coverageScope['rows'], $this->coverageScope['additional']);
    }

    /** @return list<int> */
    private function coverageClassIds(?int $programId, ?string $year): array
    {
        return collect($this->coverageRows())
            ->filter(fn (array $row): bool => (! $programId || (int) $row['program_id'] === $programId) && (! $year || $row['year_level'] === $year))
            ->flatMap(fn (array $row): array => array_column($row['classes'], 'id'))->unique()->values()->all();
    }

    private function demands(Section $class): Collection
    {
        return $class->deliveryGroups->whereNotIn('state', [SectionDeliveryGroup::StateClosed, SectionDeliveryGroup::StateCancelled])
            ->flatMap(fn (SectionDeliveryGroup $group) => $group->schedulingDemands);
    }

    private function canPrepareClass(Section $class): bool
    {
        if ($this->hasPublishedTimetable() || $this->isTermClosed()) {
            return false;
        }

        return $this->getViewData()['activePackage'] !== null
            && $class->confirmed_at === null
            && ! in_array($class->state, [Section::StateClosed, Section::StateCancelled], true)
            && (bool) auth()->user()?->can('update', $class);
    }

    private function preparation(Section $class): string
    {
        if (in_array($class->state, [Section::StateClosed, Section::StateCancelled], true)) {
            return 'Outside generation';
        }
        $activePackage = $this->getViewData()['activePackage'];
        if (! $activePackage || ! $class->calendarPackage?->isActivatedCoreSource()
            || $class->calendarPackage->coreSignature() !== $activePackage->coreSignature()) {
            return 'Needs active calendar source';
        }
        if ($class->confirmed_at === null) {
            return 'Needs confirmation';
        }
        if ($class->courseRevision?->state !== CourseSpecification::StateActive) {
            return 'Needs active subject revision';
        }
        if ($class->cohorts->isEmpty()) {
            return 'Needs student sections';
        }
        $demands = $this->demands($class);
        if ($demands->isEmpty()) {
            return 'Needs meeting requirements';
        }
        if ($demands->contains(fn (SchedulingDemand $demand): bool => $demand->validation_state === SchedulingDemand::ValidationActionRequired || $demand->hasReadinessFindings())) {
            return 'Review recorded findings';
        }

        return 'Confirmed; requirements recorded';
    }

    public function recordCohortAction(): Action
    {
        return parent::recordCohortAction()->after(function (): void {
            $this->autoSyncSchedulingDemands();
            $this->resetClassCaches();
        });
    }

    public function prepareClassOfferingAction(): Action
    {
        return parent::prepareClassOfferingAction()->after(function (): void {
            $this->autoSyncSchedulingDemands(force: true);
            $this->resetClassCaches();
        });
    }

    private function resetClassCaches(): void
    {
        $this->classViewData = null;
        $this->coverageScope = null;
        $this->curriculumPreview = null;
        $this->qualifiedFacultyCountsCache = [];
        $this->confirmableCountsByCurriculumAndYear = null;
        $this->curriculumReadinessGapsCache = null;
    }

    private function autoSyncSchedulingDemands(bool $force = false): void
    {
        $actor = auth()->user();
        if ($this->termId === null || ! $actor instanceof User || ! $actor->can('create', SchedulingDemand::class)) {
            return;
        }

        $term = Term::query()->find($this->termId);
        if (! $term instanceof Term || $term->state === Term::StateClosed) {
            return;
        }

        $hasConfirmedClasses = Section::query()
            ->whereHas('calendarPackage', fn (Builder $query): Builder => $query->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive))
            ->whereNotNull('confirmed_at')
            ->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])
            ->whereHas('termOffering', fn (Builder $query): Builder => $query->where('term_id', $term->id)->where('state', TermOffering::StatePendingScheduling))
            ->whereHas('deliveryGroups')
            ->exists();

        if (! $hasConfirmedClasses) {
            return;
        }

        if (! $force) {
            $demands = SchedulingDemand::query()->whereHas('termOffering', fn (Builder $query): Builder => $query->where('term_id', $term->id));
            $missingDemandForConfirmed = Section::query()
                ->whereHas('calendarPackage', fn (Builder $query): Builder => $query->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive))
                ->whereNotNull('confirmed_at')
                ->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])
                ->whereHas('termOffering', fn (Builder $query): Builder => $query->where('term_id', $term->id)->where('state', TermOffering::StatePendingScheduling))
                ->whereDoesntHave('deliveryGroups.schedulingDemands')
                ->exists();

            if (! $missingDemandForConfirmed
                && (clone $demands)->exists()
                && ! (clone $demands)->where('validation_state', '!=', SchedulingDemand::ValidationReadyForReview)->exists()) {
                return;
            }
        }

        app(GenerateSchedulingDemand::class)->forTerm($actor, $term);
    }

    /**
     * @return array{title: string, body: string}|null
     */
    private function classCreationPrerequisiteWarning(): ?array
    {
        if ($this->getViewData()['activePackage'] === null) {
            return [
                'title' => 'Activate teaching calendar first',
                'body' => 'Activate the teaching calendar in 1. Calendar before loading or adding classes.',
            ];
        }

        if ($this->currentCurriculumPreview()['cohorts']->whereNotNull('confirmed_at')->isEmpty()) {
            return [
                'title' => 'Add a student section first',
                'body' => 'Add at least one student section in the Student sections tab before loading or adding classes.',
            ];
        }

        return null;
    }

    private function hasClassCreationPrerequisites(): bool
    {
        return $this->classCreationPrerequisiteWarning() === null;
    }

    /** @return array<string, mixed> */
    private function currentCurriculumPreview(): array
    {
        return $this->curriculumPreview ??= app(PrepareTermClassProposal::class)->preview($this->selectedTerm());
    }

    /** @param array<string, mixed> $preview */
    private function defaultYearLevelForCohort(TermCohort $cohort, array $preview): ?string
    {
        $termType = $this->selectedTerm()->type;
        $lockedEntryIds = $this->lockedCurriculumEntryIdsForTerm();
        $matchingYears = collect($preview['catalog'])
            ->where('curriculum_id', $cohort->curriculum_version_id)
            ->where('term_type', $termType)
            ->reject(fn (array $course): bool => isset($lockedEntryIds[(int) $course['id']]))
            ->pluck('year_level')
            ->unique()
            ->values();

        $preferredNumeric = $cohort->year_level !== null ? (string) $cohort->year_level : null;
        $preferredLabel = $this->yearLevelLabel($cohort->year_level);

        return match (true) {
            $preferredNumeric !== null && $matchingYears->contains($preferredNumeric) => $preferredNumeric,
            $preferredLabel !== null && $matchingYears->contains($preferredLabel) => $preferredLabel,
            $cohort->year_level !== null && $matchingYears->first(fn (string $year): bool => TermCohort::parseYearLevelValue($year) === (int) $cohort->year_level) !== null => (string) $matchingYears->first(fn (string $year): bool => TermCohort::parseYearLevelValue($year) === (int) $cohort->year_level),
            default => $matchingYears->first(),
        };
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<string>
     */
    private function defaultSelectedEntryIdsForCohort(TermCohort $cohort, string $yearLevel, array $preview): array
    {
        $termType = $this->selectedTerm()->type;
        $linkedEntryIds = $this->existingCohortEntryIds($cohort->id);
        $lockedEntryIds = $this->lockedCurriculumEntryIdsForTerm();

        return collect($preview['catalog'])
            ->where('curriculum_id', $cohort->curriculum_version_id)
            ->where('term_type', $termType)
            ->where('year_level', $yearLevel)
            ->reject(fn (array $course): bool => isset($linkedEntryIds[(int) $course['id']]) || isset($lockedEntryIds[(int) $course['id']]))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /** @return array<int, true> */
    private function lockedCurriculumEntryIdsForTerm(): array
    {
        if ($this->termId === null) {
            return [];
        }

        return TermOffering::query()
            ->where('term_id', $this->termId)
            ->where('delivery_variant', TermOffering::ArrangementNormalClass)
            ->whereIn('state', [TermOffering::StateScheduled, TermOffering::StateCancelled])
            ->pluck('curriculum_entry_id')
            ->filter()
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true])
            ->all();
    }

    /** @return array<int, string> */
    private function cohortSelectOptions(): array
    {
        if ($this->termId === null) {
            return [];
        }

        return TermCohort::query()
            ->where('term_id', $this->termId)
            ->orderBy('reference')
            ->get()
            ->mapWithKeys(function (TermCohort $cohort): array {
                $count = $cohort->confirmed_count ?? $cohort->forecast_count;
                $reference = $this->formatSectionReference($cohort->reference);

                return [
                    $cohort->id => $count !== null ? "{$reference} · {$count} students" : $reference,
                ];
            })
            ->all();
    }

    private function formatSectionReference(string $reference): string
    {
        return RecordTermCohort::normalizeReference($reference);
    }

    private function ensureSectionBoundToActiveCalendar(
        Term $term,
        Section $section,
        ?TermCalendarPackage $calendar,
        User $actor,
        ?string $authorityReference = null,
    ): Section {
        if (! $calendar instanceof TermCalendarPackage || ! $calendar->isActivatedCoreSource()) {
            return $section;
        }

        if ($section->confirmed_at === null && ($section->term_calendar_package_id === null || $section->course_specification_id === null)) {
            $source = $section->source;
            if (blank($source) && $section->termOffering?->category === TermOffering::CategoryRegular) {
                $source = Section::SourceRegular;
            }

            return app(PrepareClassOffering::class)->execute($term, $section, $actor, [
                'class_reference' => $section->class_reference ?: $section->code,
                'source' => $source,
                'delivery_mode' => $section->delivery_mode ?: $section->termOffering?->modality,
                'authority_reference' => $authorityReference ?? $section->authority_reference,
            ]);
        }

        $section->loadMissing(['calendarPackage', 'termOffering']);
        $belongsToTerm = ($section->termOffering === null || (int) $section->termOffering->term_id === (int) $term->id)
            && ($section->calendarPackage === null || (int) $section->calendarPackage->term_id === (int) $term->id)
            && ($section->termOffering !== null || $section->calendarPackage !== null);

        if ($belongsToTerm
            && $term->state !== Term::StateClosed
            && ! in_array($section->state, [Section::StateClosed, Section::StateCancelled], true)
            && (int) $section->term_calendar_package_id !== (int) $calendar->id) {
            $section->term_calendar_package_id = $calendar->id;
            $section->save();
            $section->refresh();
            $section->load(['calendarPackage', 'cohorts', 'termOffering.curriculumEntry']);
        }

        return $section;
    }

    /** @return array<int, int> */
    private function cohortHeadcountsById(): array
    {
        if ($this->termId === null) {
            return [];
        }

        return TermCohort::query()
            ->where('term_id', $this->termId)
            ->get()
            ->mapWithKeys(fn (TermCohort $cohort): array => [
                $cohort->id => (int) ($cohort->confirmed_count ?? $cohort->forecast_count ?? 0),
            ])
            ->filter(fn (int $count): bool => $count > 0)
            ->all();
    }

    private function syncCapacityFromCohorts(Get $get, Set $set, string $countField): void
    {
        $cohorts = $get('../../cohorts');
        if (! is_array($cohorts)) {
            return;
        }

        $total = collect($cohorts)->sum(fn (mixed $row): int => is_array($row) ? (int) ($row[$countField] ?? 0) : 0);
        if ($total < 1) {
            return;
        }

        $currentCapacity = (int) ($get('../../capacity') ?? 0);
        if (count($cohorts) <= 1 || $currentCapacity < $total) {
            $set('../../capacity', $total);
        }
    }

    /**
     * @param  list<mixed>  $courseIds
     * @return array<int, int>
     */
    private function qualifiedFacultyCountsByCourse(array $courseIds): array
    {
        $normalizedIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $courseIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($normalizedIds === []) {
            return [];
        }

        sort($normalizedIds);
        $cacheKey = implode(',', $normalizedIds);

        if (isset($this->qualifiedFacultyCountsCache[$cacheKey])) {
            return $this->qualifiedFacultyCountsCache[$cacheKey];
        }

        return $this->qualifiedFacultyCountsCache[$cacheKey] = FacultyQualification::query()
            ->active()
            ->whereIn('course_id', $normalizedIds)
            ->whereHas('faculty', fn (Builder $query): Builder => $query
                ->where('status', User::StatusActive)
                ->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', User::StaffRoleFaculty)))
            ->selectRaw('course_id, COUNT(DISTINCT faculty_user_id) as qualified_count')
            ->groupBy('course_id')
            ->pluck('qualified_count', 'course_id')
            ->mapWithKeys(static fn (mixed $count, mixed $courseId): array => [(int) $courseId => (int) $count])
            ->all();
    }

    /** @return array<int, true> */
    private function existingCohortEntryIds(int $cohortId): array
    {
        return Section::query()
            ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])
            ->whereHas('termOffering', fn (Builder $query): Builder => $query->where('term_id', $this->termId))
            ->whereHas('cohorts', fn (Builder $query): Builder => $query->where('term_cohorts.id', $cohortId))
            ->with('termOffering:id,curriculum_entry_id')
            ->get()
            ->map(fn (Section $section): ?int => $section->termOffering?->curriculum_entry_id)
            ->filter()
            ->mapWithKeys(fn (int $entryId): array => [$entryId => true])
            ->all();
    }

    private function hasActiveClassesFilterOrSearch(): bool
    {
        if (filled($this->getTableSearch()) || $this->classTriage !== 'all') {
            return true;
        }

        $lifecycleStatus = $this->getTableFilterState('lifecycle_status')['value'] ?? 'active';
        $activeFilters = $this->getTable()->getActiveFiltersCount();

        if ($lifecycleStatus === 'active' && $this->getTable()->getFilter('lifecycle_status')?->getActiveCount() === 1) {
            $activeFilters = max(0, $activeFilters - 1);
        }

        return $activeFilters > 0;
    }

    /**
     * @param  Collection<int, TermCohort>  $cohorts
     * @return array{all: int, no_classes: int, no_subjects: int}
     */
    private function computeSectionTriageCounts(Collection $cohorts): array
    {
        return [
            'all' => $cohorts->count(),
            'no_classes' => $cohorts
                ->filter(fn (TermCohort $cohort): bool => $cohort->classOfferings->where('state', '!=', Section::StateCancelled)->isEmpty())
                ->count(),
            'no_subjects' => $cohorts
                ->filter(fn (TermCohort $cohort): bool => $this->confirmableSubjectCountForCohort($cohort) === 0)
                ->count(),
        ];
    }

    /**
     * @param  Collection<int, Section>  $sourceClasses
     * @param  Collection<int, Section>  $contextClasses
     * @param  Collection<int, array<string, mixed>>  $facultyRecords
     * @return array{all: int, needs_preparation: int, no_teacher: int, cancelled: int}
     */
    private function computeClassTriageCounts(?Term $term, Collection $sourceClasses, Collection $contextClasses, Collection $facultyRecords): array
    {
        $contextIds = $contextClasses->pluck('id')->flip()->all();

        $needsPreparationCount = $sourceClasses
            ->filter(function (Section $section) use ($contextIds): bool {
                if ($section->confirmed_at === null || ! isset($contextIds[$section->id]) || $section->cohorts->isEmpty()) {
                    return true;
                }

                $demands = $section->deliveryGroups->flatMap->schedulingDemands;

                return $demands->isEmpty()
                    || $demands->contains('validation_state', SchedulingDemand::ValidationActionRequired);
            })
            ->count();

        $noTeacherCount = count($this->classIdsWithoutQualifiedTeacher($sourceClasses, $facultyRecords));

        $cancelledCount = $term instanceof Term
            ? Section::query()
                ->whereHas('termOffering', fn (Builder $query): Builder => $query->where('term_id', $term->id))
                ->where('state', Section::StateCancelled)
                ->count()
            : 0;

        return [
            'all' => $sourceClasses->count(),
            'needs_preparation' => $needsPreparationCount,
            'no_teacher' => $noTeacherCount,
            'cancelled' => $cancelledCount,
        ];
    }

    /**
     * @param  Collection<int, Section>  $sourceClasses
     * @param  Collection<int, array<string, mixed>>  $facultyRecords
     * @return list<int>
     */
    private function classIdsWithoutQualifiedTeacher(Collection $sourceClasses, Collection $facultyRecords): array
    {
        $qualifiedCourses = $facultyRecords
            ->flatMap(fn (array $faculty): array => is_array($faculty['courses'] ?? null) ? $faculty['courses'] : [])
            ->filter()
            ->unique()
            ->flip()
            ->all();

        return $sourceClasses
            ->filter(function (Section $section) use ($qualifiedCourses): bool {
                $courseCode = $section->courseRevision?->course?->code
                    ?? $section->termOffering?->curriculumEntry?->courseSpecification?->course?->code;

                return $courseCode !== null && ! isset($qualifiedCourses[$courseCode]);
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function confirmableSubjectCountForCohort(TermCohort $cohort): int
    {
        $curriculumId = (int) ($cohort->curriculum_version_id ?? 0);
        if ($curriculumId <= 0) {
            return 0;
        }

        $map = $this->confirmableCountsMapForTerm();
        if ($cohort->year_level !== null && (int) $cohort->year_level > 0) {
            return $map['by_year'][$curriculumId.':'.(int) $cohort->year_level] ?? 0;
        }

        $resolvedYear = $cohort->resolvedYearLevel();
        if ($resolvedYear !== null && isset($map['by_year'][$curriculumId.':'.$resolvedYear])) {
            return $map['by_year'][$curriculumId.':'.$resolvedYear];
        }

        return $map['by_curriculum'][$curriculumId] ?? 0;
    }

    /** @return array{by_year: array<string, int>, by_curriculum: array<int, int>} */
    private function confirmableCountsMapForTerm(): array
    {
        if ($this->confirmableCountsByCurriculumAndYear !== null) {
            return $this->confirmableCountsByCurriculumAndYear;
        }

        if ($this->termId === null) {
            return $this->confirmableCountsByCurriculumAndYear = ['by_year' => [], 'by_curriculum' => []];
        }

        $term = $this->selectedTerm();
        $cohorts = TermCohort::query()
            ->where('term_id', $term->id)
            ->get(['id', 'curriculum_version_id']);

        $curriculumIds = $cohorts
            ->pluck('curriculum_version_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($curriculumIds === []) {
            return $this->confirmableCountsByCurriculumAndYear = ['by_year' => [], 'by_curriculum' => []];
        }

        $lockedEntryIds = $this->lockedCurriculumEntryIdsForTerm();
        $entries = CurriculumEntry::query()
            ->whereIn('curriculum_version_id', $curriculumIds)
            ->where('term_type', $term->type)
            ->with(['courseSpecification.components'])
            ->orderBy('sequence')
            ->get();

        $seenCoursesByBucket = [];
        $seenCoursesByCurriculum = [];
        foreach ($entries as $entry) {
            if (! $this->isConfirmableCurriculumEntry($entry, $lockedEntryIds)) {
                continue;
            }

            $yearLevel = TermCohort::parseYearLevelValue($entry->year_level);
            if ($yearLevel === null) {
                continue;
            }

            $curriculumId = (int) $entry->curriculum_version_id;
            $bucket = $curriculumId.':'.$yearLevel;
            $courseKey = (int) ($entry->courseSpecification?->course_id ?? $entry->id);
            $seenCoursesByBucket[$bucket][$courseKey] = true;
            $seenCoursesByCurriculum[$curriculumId][$courseKey] = true;
        }

        return $this->confirmableCountsByCurriculumAndYear = [
            'by_year' => collect($seenCoursesByBucket)
                ->map(static fn (array $courses): int => count($courses))
                ->all(),
            'by_curriculum' => collect($seenCoursesByCurriculum)
                ->map(static fn (array $courses): int => count($courses))
                ->all(),
        ];
    }

    /** @return array<string, string> */
    private function availableYearLevelsForCohortId(int $cohortId): array
    {
        if ($cohortId <= 0) {
            return [];
        }

        $preview = $this->currentCurriculumPreview();
        $cohort = $preview['cohorts']->whereNotNull('confirmed_at')->firstWhere('id', $cohortId);
        if (! $cohort instanceof TermCohort) {
            return [];
        }

        $termType = $this->selectedTerm()->type;
        $lockedEntryIds = $this->lockedCurriculumEntryIdsForTerm();

        return collect($preview['catalog'])
            ->where('curriculum_id', $cohort->curriculum_version_id)
            ->where('term_type', $termType)
            ->reject(fn (array $course): bool => isset($lockedEntryIds[(int) $course['id']]))
            ->groupBy('year_level')
            ->mapWithKeys(fn ($items, string $year): array => [
                $year => (is_numeric($year) ? 'Year '.$year : $year).' ('.$items->count().' '.Str::plural('subject', $items->count()).')',
            ])
            ->all();
    }

    private function isLoadCurriculumSubmitDisabled(): bool
    {
        $mounted = $this->mountedActions[0] ?? null;
        if (! is_array($mounted) || ($mounted['name'] ?? null) !== 'loadCurriculumSubjects') {
            return false;
        }

        $data = is_array($mounted['data'] ?? null) ? $mounted['data'] : [];
        $cohortId = (int) ($data['term_cohort_id'] ?? $mounted['arguments']['cohortId'] ?? 0);
        if ($cohortId <= 0) {
            return false;
        }

        return $this->availableYearLevelsForCohortId($cohortId) === [];
    }

    /**
     * @param  Collection<int, TermCohort>  $cohorts
     * @return list<array{curriculum_id: int, label: string, configured: ?string, url: string, is_locked: bool}>
     */
    private function curriculumReadinessGapsForTerm(Term $term, Collection $cohorts): array
    {
        if ($this->curriculumReadinessGapsCache !== null) {
            return $this->curriculumReadinessGapsCache;
        }

        $cohortCurriculumIds = $cohorts
            ->pluck('curriculum_version_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        // Check all running batches per active program using dynamic `program.duration_years - 1` lookback.
        $runningBatchCurriculumIds = collect();
        $term->loadMissing('academicYear');
        if ($term->academicYear !== null) {
            $activePrograms = Program::query()
                ->where('is_active', true)
                ->get();

            foreach ($activePrograms as $program) {
                foreach ($program->expectedIntakeYearsForAcademicYear($term->academicYear) as $intakeStartYear) {
                    $matched = $program->curriculumForIntakeStartYear($intakeStartYear);
                    if ($matched instanceof CurriculumVersion) {
                        $runningBatchCurriculumIds->push((int) $matched->id);
                    }
                }
            }
        }

        $targetCurriculumIds = $cohortCurriculumIds
            ->merge($runningBatchCurriculumIds)
            ->unique()
            ->values();

        $curriculaQuery = CurriculumVersion::query()
            ->with(['program', 'academicYears'])
            ->where(function (Builder $query) use ($term, $targetCurriculumIds): void {
                $hasCondition = false;
                if ($term->academic_year_id !== null) {
                    $query->where(function (Builder $activeBatch) use ($term): void {
                        $activeBatch->where('state', CurriculumVersion::StateActive)
                            ->whereHas('academicYears', fn (Builder $years) => $years->where('academic_years.id', $term->academic_year_id));
                    });
                    $hasCondition = true;
                }

                if ($targetCurriculumIds->isNotEmpty()) {
                    $method = $hasCondition ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('id', $targetCurriculumIds->all());
                } elseif (! $hasCondition) {
                    $query->whereRaw('1 = 0');
                }
            });

        $curricula = $curriculaQuery->get()
            ->groupBy('program_id')
            ->sortBy(fn (Collection $group): string => (string) ($group->first()?->program?->code ?? ''))
            ->flatMap(fn (Collection $group): Collection => CurriculumVersion::sortByLatestBatch($group, groupByState: true))
            ->values();
        if ($curricula->isEmpty()) {
            return $this->curriculumReadinessGapsCache = [];
        }

        $curriculumIds = $curricula
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $lockedEntryIds = $this->lockedCurriculumEntryIdsForTerm();
        $entries = CurriculumEntry::query()
            ->whereIn('curriculum_version_id', $curriculumIds)
            ->with(['courseSpecification.components'])
            ->get()
            ->groupBy('curriculum_version_id');

        $periodsByCurriculum = $this->configuredSubjectPeriodsByCurriculum($curriculumIds);
        $gaps = [];

        foreach ($curricula as $curriculum) {
            $hasTermEntry = ($entries->get($curriculum->id) ?? collect())
                ->where('term_type', $term->type)
                ->contains(fn (CurriculumEntry $entry): bool => $this->isConfirmableCurriculumEntry($entry, $lockedEntryIds));

            if ($hasTermEntry) {
                continue;
            }

            $programCode = (string) ($curriculum->program?->code ?: ($curriculum->program?->name ?? 'Program'));
            $batchLabel = $curriculum->batchLabel();
            $configured = $this->formatConfiguredPeriods(
                $periodsByCurriculum[$curriculum->id] ?? [],
                style: 'dot',
                skipTermType: $term->type,
            );

            $gaps[] = [
                'curriculum_id' => (int) $curriculum->id,
                'label' => "{$programCode} · {$batchLabel}",
                'configured' => $configured,
                'url' => CurriculumVersionResource::getUrl('review', ['record' => $curriculum->id]),
                'is_locked' => ! $curriculum->isEditableDraft(),
            ];
        }

        return $this->curriculumReadinessGapsCache = $gaps;
    }

    private function yearLevelLabel(?int $yearLevel): ?string
    {
        return match ($yearLevel) {
            1 => 'First Year',
            2 => 'Second Year',
            3 => 'Third Year',
            4 => 'Fourth Year',
            5 => 'Fifth Year',
            default => null,
        };
    }
}
