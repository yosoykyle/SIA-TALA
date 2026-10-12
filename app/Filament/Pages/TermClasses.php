<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\TermCoverageProjection;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\Sections\Schemas\SectionInfolist;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Models\CourseSpecification;
use App\Models\Program;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    public string $classesView = 'classes';

    /** @var array<int|string, string|null> */
    #[Locked]
    public array $classConfirmationSources = [];

    public function chooseClassesView(string $view): void
    {
        abort_unless(in_array($view, ['classes', 'cohorts'], true), 404);
        $this->classesView = $view;
        $this->resetTableSearch();
        $this->resetTableColumnSearches();
        $this->resetTable();
    }

    private ?array $classViewData = null;

    private ?array $coverageScope = null;

    protected static ?string $cluster = TermPlanningCluster::class;

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
            $this->classViewData['cohorts']->loadMissing(['program', 'curriculumVersion', 'classOfferings.courseRevision', 'classOfferings.termOffering.curriculumEntry.courseSpecification']);
        }

        return $this->classViewData;
    }

    public function mount(): void
    {
        parent::mount();
        abort_unless(in_array($this->classesView, ['classes', 'cohorts'], true), 404);
        if ($this->tableFilters === null && ($this->programId || $this->yearLevel)) {
            $this->tableFilters = ['program' => ['value' => $this->programId], 'year' => ['value' => $this->yearLevel]];
        }
    }

    public function table(Table $table): Table
    {
        if ($this->classesView === 'cohorts') {
            return $table->query(TermCohort::query()->where('term_id', $this->termId)->with(['program', 'curriculumVersion', 'classOfferings.courseRevision', 'classOfferings.termOffering.curriculumEntry.courseSpecification']))
                ->columns([
                    TextColumn::make('reference')->label('Student section')->searchable()->sortable()->weight('bold'),
                    TextColumn::make('program.name')->label('Program')->searchable()->sortable()->description(fn (TermCohort $record): string => $record->curriculumVersion?->version_code ?? 'Curriculum not associated'),
                    TextColumn::make('expected_learners')->label('Expected learners')->state(fn (TermCohort $record): ?int => $record->confirmed_count ?? $record->forecast_count),
                    TextColumn::make('classOfferings.code')->label('Linked classes')->listWithLineBreaks()->placeholder('No classes linked'),
                ])->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)->defaultSort('reference')
                ->recordActions([Action::make('linkedClasses')->label('Review linked classes')->modalHeading(fn (TermCohort $record): string => $record->reference.' · linked classes')
                    ->schema(fn (TermCohort $record): array => [RepeatableEntry::make('classes')->state($record->classOfferings)->schema([
                        TextEntry::make('code')->label('Class')->url(fn (Section $record): string => SectionResource::getUrl('view', ['record' => $record, 'termId' => $this->termId])),
                        TextEntry::make('title')->label('Course')->state(fn (Section $record): string => $record->courseRevision?->title ?? $record->termOffering?->curriculumEntry?->courseSpecification?->title ?? $record->code),
                    ])->columns(2)])
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')])
                ->emptyStateHeading('No student sections recorded for this Term')->stackedOnMobile();
        }

        return $table->query($this->classesQuery())
            ->columns([
                TextColumn::make('code')->label('Class')->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $identity): Builder => $identity
                    ->where('code', 'like', '%'.$search.'%')
                    ->orWhereHas('courseRevision', fn (Builder $course): Builder => $course->where('title', 'like', '%'.$search.'%'))
                    ->orWhereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $course): Builder => $course->where('title', 'like', '%'.$search.'%'))))->sortable()->weight('bold')
                    ->formatStateUsing(fn (string $state, Section $record): string => ($record->courseRevision?->title ?? $record->termOffering?->curriculumEntry?->courseSpecification?->title) ?: $state)
                    ->description(fn (Section $record): string => $record->code)->wrap(),
                TextColumn::make('termOffering.curriculumEntry.curriculumVersion.program.code')->label('Curriculum origin')
                    ->description(fn (Section $record): string => (string) $record->termOffering?->curriculumEntry?->year_level)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cohorts.reference')->label('Student sections')->listWithLineBreaks()->placeholder('No sections linked')
                    ->description(fn (Section $record): string => $record->cohorts->count().' section(s) · '.$record->cohorts->sum(fn ($cohort): int => (int) $cohort->pivot->expected_count).' linked learners')->wrap(),
                TextColumn::make('weekly_meetings')->label('Weekly meetings')
                    ->state(fn (Section $record): int => (int) $this->demands($record)->sum('meeting_count'))
                    ->description(fn (Section $record): string => $this->demands($record)->map(fn (SchedulingDemand $demand): string => $demand->meeting_count.' × '.$demand->required_duration_minutes.' min')->unique()->implode(' · ')),
                TextColumn::make('teacher_options')->label('Teacher options')
                    ->state(function (Section $record): string {
                        $course = $record->courseRevision?->course?->code ?? $record->termOffering?->curriculumEntry?->courseSpecification?->course?->code;
                        if (! $course) {
                            return 'Course not recorded';
                        }
                        $count = $this->getViewData()['facultyRecords']->filter(fn (array $faculty): bool => in_array($course, $faculty['courses'], true))->count();

                        return $count ? $count.' qualified' : 'No qualified teacher';
                    })->tooltip('Generation chooses among eligible teachers using availability and load checks. Grading assignment is separate.')->wrap(),
                TextColumn::make('state')->label('Class lifecycle')->badge()->formatStateUsing(fn (string $state): string => Section::stateOptions()[$state] ?? $state)->color('gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('preparation')->label('Preparation')->state(fn (Section $record): string => $this->preparation($record))->badge()
                    ->color(fn (string $state): string => $state === 'Confirmed; requirements recorded' ? 'success' : 'warning')
                    ->description(fn (Section $record): string => $this->demands($record)->flatMap(fn (SchedulingDemand $demand): array => $demand->readinessFindings())->pluck('message')->unique()->implode(' · '))->wrap(),
            ])
            ->filters([
                SelectFilter::make('program')->label('Program')->searchable()->options(fn (): array => Program::query()->orderBy('name')->get()->mapWithKeys(fn (Program $program): array => [$program->id => $program->name.' ('.$program->code.')'])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, mixed $value) => $query->whereIn('id', $this->coverageClassIds((int) $value, $this->getTableFilterState('year')['value'] ?? null)))),
                SelectFilter::make('year')->label('Curriculum year')->options(fn (): array => collect($this->coverageRows())->pluck('year_level')->filter()->unique()->mapWithKeys(fn (string $year): array => [$year => $year])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, mixed $value) => $query->whereIn('id', $this->coverageClassIds(isset($this->getTableFilterState('program')['value']) ? (int) $this->getTableFilterState('program')['value'] : null, (string) $value)))),
                SelectFilter::make('state')->label('Class lifecycle')->options(Section::stateOptions()),
                Filter::make('needs_attention')->label('Needs preparation')->query(fn (Builder $query) => $query
                    ->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])
                    ->where(fn (Builder $query) => $query->whereNull('confirmed_at')
                        ->orWhereNotIn('id', $this->getViewData()['contextClasses']->pluck('id'))
                        ->orWhereDoesntHave('cohorts')->orWhereDoesntHave('deliveryGroups.schedulingDemands')
                        ->orWhereHas('deliveryGroups.schedulingDemands', fn (Builder $demands) => $demands->where('validation_state', SchedulingDemand::ValidationActionRequired)))),
            ])
            ->toolbarActions([
                BulkAction::make('confirmSelectedClasses')
                    ->modalSubmitActionLabel('Confirm selected classes')
                    ->label('Confirm selected classes')
                    ->visible(fn (): bool => $this->termId !== null && (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar))
                    ->modalDescription('Review the selected classes and learner counts. Regular classes use their recorded course and the approved teaching calendar. If any class needs attention, all remain unchanged.')
                    ->fillForm(function (Collection $records): array {
                        $this->classConfirmationSources = $records->mapWithKeys(fn (Section $record): array => [$record->id => $this->classConfirmationSource($record)])->all();
                        foreach (TermCohort::query()->where('term_id', $this->termId)->get() as $cohort) {
                            $this->classConfirmationSources['cohort:'.$cohort->id] = $this->cohortConfirmationSource($cohort);
                        }
                        $this->classConfirmationSources['calendar'] = TermCalendarPackage::query()->where('term_id', $this->termId)
                            ->where('state', TermCalendarPackage::StateActive)->first()?->concurrencyToken();

                        return ['classes' => $records->map(fn (Section $record): array => [
                            'section_id' => $record->id,
                            'cohorts' => $record->cohorts->map(fn ($cohort): array => ['term_cohort_id' => $cohort->id, 'expected_count' => $cohort->pivot->expected_count])->all(),
                            'authority_reference' => $record->authority_reference,
                        ])->all()];
                    })
                    ->schema([
                        Repeater::make('classes')->label('Selected classes')->addable(false)->deletable(false)->reorderable(false)->schema([
                            Select::make('section_id')->label('Class')->options(fn (): array => $this->classesQuery()->pluck('code', 'id')->all())->disabled()->dehydrated()->required(),
                            Repeater::make('cohorts')->label('Student sections')->minItems(1)->required()->schema([
                                Select::make('term_cohort_id')->label('Student section')->options(fn (): array => TermCohort::query()->where('term_id', $this->termId)->pluck('reference', 'id')->all())->required()->distinct(),
                                TextInput::make('expected_count')->label('Learners in this class')->numeric()->integer()->minValue(1)->required(),
                            ]),
                            TextInput::make('authority_reference')->label('Additional class approval, when applicable')->maxLength(255),
                        ]),
                    ])
                    ->action(function (Collection $records, array $data, Schema $schema): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
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
                                $items = collect($data['classes'])->map(function (array $row) use ($records, $actor, $term): array {
                                    abort_unless($records->contains('id', (int) $row['section_id']), 403);
                                    $section = Section::query()->whereKey((int) $row['section_id'])->lockForUpdate()->firstOrFail();
                                    $section->load(['cohorts', 'termOffering.curriculumEntry']);
                                    if (($this->classConfirmationSources[$section->id] ?? null) !== $this->classConfirmationSource($section)) {
                                        throw ValidationException::withMessages(['classes' => 'A selected class has changed. Close this review and reopen it with the latest class details.']);
                                    }
                                    if ($section->confirmed_at === null && ($section->term_calendar_package_id === null || $section->course_specification_id === null)) {
                                        $source = $section->source;
                                        if (blank($source) && $section->termOffering?->category === TermOffering::CategoryRegular) {
                                            $source = Section::SourceRegular;
                                        }
                                        $section = app(PrepareClassOffering::class)->execute($term, $section, $actor, [
                                            'class_reference' => $section->class_reference ?: $section->code,
                                            'source' => $source,
                                            'delivery_mode' => $section->delivery_mode ?: $section->termOffering?->modality,
                                            'authority_reference' => $row['authority_reference'] ?? $section->authority_reference,
                                        ]);
                                    }

                                    return ['section' => $section, 'cohort_counts' => collect($row['cohorts'])->mapWithKeys(fn (array $cohort): array => [(int) $cohort['term_cohort_id'] => (int) $cohort['expected_count']])->all(), 'authority_reference' => $row['authority_reference'] ?? null];
                                })->all();
                                abort_unless(count($items) === $records->count(), 403);
                                app(ConfirmClassOffering::class)->batch($actor, $items, $this->termId);
                            }, attempts: 5);
                        } catch (ValidationException $exception) {
                            $messages = collect($exception->errors())->flatten()->all();
                            Notification::make()->title('Cannot confirm selected classes')->body($messages[0] ?? 'Review the selected classes before continuing.')->danger()->persistent()->send();
                            throw ValidationException::withMessages([$schema->getStatePath().'.classes' => $messages]);
                        }
                        $this->classViewData = null;
                        Notification::make()->title('Selected classes confirmed')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->headerActions([
                Action::make('setUpYear')->label('Prepare classes')->url(fn (): string => TermOfferingResource::getUrl('index', ['termId' => $this->termId, 'programId' => $this->getTableFilterState('program')['value'] ?? null, 'yearLevel' => $this->getTableFilterState('year')['value'] ?? null, 'action' => 'buildRegularOfferings']))->visible(fn (): bool => $this->termId !== null && (bool) auth()->user()?->can('create', TermOffering::class)),
                Action::make('addClass')->label('Add a class')->color('gray')->url(fn (): string => SectionResource::getUrl('index', ['termId' => $this->termId, 'action' => 'create']))->visible(fn (): bool => $this->termId !== null && (bool) auth()->user()?->can('create', Section::class)),
            ])
            ->recordActions([
                $this->confirmClassAction()
                    ->visible(fn (Section $record): bool => $record->confirmed_at === null && ! in_array($record->state, [Section::StateClosed, Section::StateCancelled], true) && (bool) auth()->user()?->can('update', $record)),
                $this->classDetailsAction('viewClass'),
                Action::make('manageClass')->label('Manage class')
                    ->url(fn (Section $record): string => SectionResource::getUrl('view', ['record' => $record, 'termId' => $this->termId]))
                    ->visible(fn (Section $record): bool => (bool) auth()->user()?->can('update', $record)),
                $this->prepareClassOfferingAction()->visible(fn (Section $record): bool => $record->confirmed_at === null && ! in_array($record->state, [Section::StateClosed, Section::StateCancelled], true) && (bool) auth()->user()?->can('update', $record)),
            ])->defaultSort('code')->emptyStateHeading('No classes in this scope')->emptyStateDescription('Add the student sections and set up the offered courses for this Term.')->stackedOnMobile();
    }

    public function inspectLinkedClassAction(): Action
    {
        return $this->classDetailsAction('inspectLinkedClass')
            ->record(fn (array $arguments): Section => $this->classesQuery()->findOrFail((int) ($arguments['classId'] ?? 0)));
    }

    private function classDetailsAction(string $name): Action
    {
        return Action::make($name)->label('Class details')
            ->modalHeading(fn (Section $record): string => $record->courseRevision?->title ?? $record->termOffering?->curriculumEntry?->courseSpecification?->title ?? $record->code)
            ->schema(fn (Schema $schema): Schema => SectionInfolist::configure($schema, showCourseTitle: false))
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    private function classesQuery(): Builder
    {
        return Section::query()
            ->where(fn (Builder $query) => $query->whereHas('calendarPackage', fn (Builder $package) => $package->where('term_id', $this->termId ?? 0))
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('term_calendar_package_id')->whereHas('termOffering', fn (Builder $offering) => $offering->where('term_id', $this->termId ?? 0))))
            ->with(['termOffering.curriculumEntry.curriculumVersion.program', 'termOffering.curriculumEntry.courseSpecification.course', 'courseRevision.course', 'cohorts', 'deliveryGroups.schedulingDemands.courseComponent', 'calendarPackage.teachingGridRows', 'calendarPackage.datedExceptions']);
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
            return 'Needs active course revision';
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
}
