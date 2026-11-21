<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\SectionPlanningService;
use App\Actions\Scheduling\TermCoverageProjection;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\Sections\Schemas\SectionForm;
use App\Filament\Resources\Sections\Schemas\SectionInfolist;
use App\Filament\Resources\Sections\SectionResource;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\Room;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\SafeUserNotification;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
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
                    TextColumn::make('reference')->label('Student section')->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $identity): Builder => $identity
                        ->where('reference', 'like', '%'.$search.'%')
                        ->orWhereHas('program', fn (Builder $program): Builder => $program->where('name', 'like', '%'.$search.'%'))))->sortable()->weight('semibold')
                        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<span class="inline-block whitespace-nowrap">'.e($state).'</span>'))
                        ->description(fn (TermCohort $record): string => collect([$record->program->name, $record->curriculumVersion?->name ?: ($record->curriculumVersion?->version_code ?? 'Curriculum not associated'), $record->year_level])->filter()->implode(' · ')),
                    TextColumn::make('expected_learners')->label('Learners')->state(fn (TermCohort $record): string => match (true) {
                        $record->confirmed_count !== null => $record->confirmed_count.' confirmed',
                        $record->forecast_count !== null => $record->forecast_count.' forecast',
                        default => 'No count recorded',
                    }),
                    TextColumn::make('classOfferings.code')->label('Linked classes')->listWithLineBreaks()->placeholder('No classes linked')
                        ->description(fn (TermCohort $record): string => $record->classOfferings->isEmpty() ? 'The Registrar links this student section when preparing classes.' : $record->classOfferings->count().' '.Str::plural('linked class', $record->classOfferings->count())),
                ])->defaultSort('reference')
                ->recordActionsColumnLabel('Actions')->recordActions([Action::make('linkedClasses')->label(fn (TermCohort $record): string => $record->classOfferings->isEmpty() ? 'Prepare classes' : 'View classes')->button()->color('gray')
                ->unsavedChangesAlert(false)
                ->url(fn (TermCohort $record): ?string => $record->classOfferings->isEmpty() ? TermClassProposal::getUrl(['termId' => $this->termId]) : null)
                ->visible(fn (TermCohort $record): bool => $record->classOfferings->isEmpty() ? (bool) auth()->user()?->can('create', TermOffering::class) : true)
                ->modalHeading(fn (TermCohort $record): string => $record->reference.' · linked classes')
                ->modalWidth(Width::TwoExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->schema(fn (TermCohort $record): array => [RepeatableEntry::make('classes')->state($record->classOfferings)->schema([
                    TextEntry::make('code')->label('Class')->url(fn (Section $record): string => SectionResource::getUrl('view', ['record' => $record, 'termId' => $this->termId])),
                    TextEntry::make('title')->label('Course')->state(fn (Section $record): string => $record->courseRevision?->title ?? $record->termOffering?->curriculumEntry?->courseSpecification?->title ?? $record->code),
                ])->columns(2)])
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')])
                ->headerActions([
                    $this->recordCohortAction()->visible(fn (): bool => ! ($this->getViewData()['readOnly'] ?? false)),
                ])
                ->emptyState(view('filament.partials.collection-empty-state'))
                ->emptyStateHeading(fn (): string => filled($this->getTableSearch()) ? 'No matching student sections' : 'No student sections recorded for this Term')
                ->emptyStateDescription(fn (): string => filled($this->getTableSearch())
                    ? 'Try another name or clear the search.'
                    : (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'The Registrar records student sections before linking classes.' : 'Record a student section before linking classes.'))->stackedOnMobile();
        }

        return $table->query($this->classesQuery())
            ->columns([
                TextColumn::make('code')->label('Subject')->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $identity): Builder => $identity
                    ->where('code', 'like', '%'.$search.'%')
                    ->orWhereHas('courseRevision', fn (Builder $course): Builder => $course->where('title', 'like', '%'.$search.'%'))
                    ->orWhereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $course): Builder => $course->where('title', 'like', '%'.$search.'%'))))->sortable()->weight('bold')
                    ->formatStateUsing(fn (string $state, Section $record): string => ($record->courseRevision?->title ?? $record->termOffering?->curriculumEntry?->courseSpecification?->title) ?: $state)
                    ->description(fn (Section $record): string => $this->roomRequirementSummary($record))
                    ->wrap(),
                TextColumn::make('source_code')->label('Class code')->state(fn (Section $record): string => $record->code)->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('termOffering.curriculumEntry.curriculumVersion.program.code')->label('Curriculum origin')
                    ->description(fn (Section $record): string => (string) $record->termOffering?->curriculumEntry?->year_level)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cohorts.reference')->label('Student sections')->listWithLineBreaks()->placeholder('No sections linked')
                    ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<span class="inline-block whitespace-nowrap">'.e($state).'</span>'))
                    ->description(fn (Section $record): string => collect([$record->termOffering?->curriculumEntry?->year_level, $record->cohorts->isEmpty() ? null : $record->cohorts->sum(fn ($cohort): int => (int) $cohort->pivot->expected_count).' learners'])->filter()->implode(' · ')),
                TextColumn::make('weekly_meetings')->label('Weekly meetings')
                    ->state(fn (Section $record): int => (int) $this->demands($record)->sum('meeting_count'))
                    ->description(fn (Section $record): string => collect([
                        $this->demands($record)->map(fn (SchedulingDemand $demand): string => $demand->meeting_count.' × '.$demand->required_duration_minutes.' min')->unique()->implode(' · '),
                        $this->roomRequirementSummary($record),
                    ])->filter()->implode(' · '))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('teacher_options')->label('Teacher options')
                    ->state(function (Section $record): string {
                        $course = $record->courseRevision?->course?->code ?? $record->termOffering?->curriculumEntry?->courseSpecification?->course?->code;
                        if (! $course) {
                            return 'Course not recorded';
                        }
                        $count = $this->getViewData()['facultyRecords']->filter(fn (array $faculty): bool => in_array($course, $faculty['courses'], true))->count();

                        return $count ? $count.' qualified' : 'No qualified teacher';
                    })->tooltip('Generation chooses among eligible teachers using availability and load checks. Grading assignment is separate.')->toggleable(isToggledHiddenByDefault: true)->wrap(),
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
                        'Review recorded findings', 'Needs active course revision' => 'danger',
                        'Outside generation' => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (Section $record): ?string => $this->inlineFindingsDescription($record))
                    ->wrap(),
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
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->label('Filter classes')->button()->color('gray'))
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
                            SafeUserNotification::sendValidationError();
                            throw ValidationException::withMessages([$schema->getStatePath().'.classes' => $messages]);
                        }
                        $this->classViewData = null;
                        Notification::make()->title('Selected classes confirmed')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->headerActions([
                Action::make('setUpYear')->label('Prepare classes')->icon('heroicon-o-plus')->url(fn (): string => TermClassProposal::getUrl(['termId' => $this->termId]))->visible(fn (): bool => $this->termId !== null && (bool) auth()->user()?->can('create', TermOffering::class)),
                $this->syncSchedulingDemandsAction(),
                $this->addClassAction(),
            ])
            ->recordActions([
                $this->prepareClassOfferingAction()->label('Prepare')->icon('heroicon-o-pencil-square')->button()
                    ->visible(fn (Section $record): bool => $this->canPrepareClass($record) && $this->preparation($record) === 'Needs active calendar source'),
                $this->confirmClassAction()->label('Confirm learners')->icon('heroicon-o-check')->button()
                    ->visible(fn (Section $record): bool => $this->canPrepareClass($record) && $this->preparation($record) !== 'Needs active calendar source'),
                $this->editClassAction(),
                $this->classDetailsAction('viewClass')->label('Details')->icon('heroicon-o-eye')->button()->color('gray'),
            ])->recordActionsColumnLabel('Actions')->defaultSort('code')
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (): string => filled($this->getTableSearch()) || $this->getTable()->getActiveFiltersCount() ? 'No matching classes' : 'No classes recorded for this Term')
            ->emptyStateDescription(fn (): string => filled($this->getTableSearch()) || $this->getTable()->getActiveFiltersCount()
                ? 'Try another subject or clear the search and filters.'
                : (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'The Registrar prepares the classes for this Term.' : 'Add the student sections and prepare the classes for this Term.'))->stackedOnMobile();
    }

    public function addClassAction(): CreateAction
    {
        return CreateAction::make('addClass')->label('Add draft class')->icon('heroicon-o-plus')->color('gray')->model(Section::class)
            ->authorize(fn (): bool => (bool) auth()->user()?->can('create', Section::class))
            ->modalHeading('Add draft class')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Record a class for an existing offered course. Prepare it and confirm its learners before timetable generation.')
            ->modalSubmitActionLabel('Add draft class')->createAnother(false)
            ->schema(fn (Schema $schema): Schema => SectionForm::configure($schema))
            ->mutateDataUsing(function (array $data): array {
                if (! TermOffering::query()->whereKey($data['term_offering_id'] ?? null)->where('term_id', $this->termId)->exists()) {
                    throw ValidationException::withMessages(['term_offering_id' => 'Choose an offered course for the selected Term.']);
                }

                return app(SectionPlanningService::class)->prepareForSave([...$data, 'state' => Section::StatePlanned]);
            })
            ->after(function (): void {
                $this->classViewData = null;
            })
            ->visible(fn (): bool => $this->termId !== null && (bool) auth()->user()?->can('create', Section::class));
    }

    public function inspectLinkedClassAction(): Action
    {
        return $this->classDetailsAction('inspectLinkedClass')
            ->record(fn (array $arguments): Section => $this->classesQuery()->findOrFail((int) ($arguments['classId'] ?? 0)));
    }

    private function classDetailsAction(string $name): Action
    {
        return Action::make($name)->label('Class details')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription(fn (): string => collect([$this->selectedTerm()->typeLabel(), $this->selectedTerm()->academicYear?->label])->filter()->unique()->implode(' · '))
            ->modalHeading(fn (Section $record): string => $record->courseRevision?->title ?? $record->termOffering?->curriculumEntry?->courseSpecification?->title ?? $record->code)
            ->schema(fn (Schema $schema): Schema => SectionInfolist::configure($schema, showCourseTitle: false))
            ->unsavedChangesAlert(false)
            ->extraModalFooterActions(fn (Section $record): array => [
                Action::make('manageClass')->label('Manage class')->color('gray')->icon('heroicon-o-arrow-right')->iconPosition('after')
                    ->url(SectionResource::getUrl('view', ['record' => $record, 'termId' => $this->termId]))
                    ->visible((bool) auth()->user()?->can('update', $record)),
            ])

            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    public function confirmClassAction(): Action
    {
        return parent::confirmClassAction()
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $counts = collect($data['cohorts'])->mapWithKeys(fn (array $row): array => [(int) $row['term_cohort_id'] => (int) $row['expected_count']])->all();

                try {
                    app(ConfirmClassOffering::class)->execute(
                        Section::query()->whereHas('calendarPackage', fn ($query) => $query->where('term_id', $this->termId))->findOrFail((int) $data['section_id']),
                        $actor,
                        $counts,
                        $data['additional_authority_reference'] ?? null,
                    );
                } catch (ValidationException $exception) {
                    $firstMessage = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->title('Cannot confirm class')
                        ->body(is_string($firstMessage) ? $firstMessage : 'Review the learner counts and class setup, then try again.')
                        ->danger()
                        ->persistent()
                        ->send();

                    throw ValidationException::withMessages(
                        collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.'.$field => $messages])->all(),
                    );
                }

                $this->classViewData = null;
                $this->coverageScope = null;
                Notification::make()->title('Class confirmed')->success()->send();
            });
    }

    public function editClassAction(): Action
    {
        return Action::make('editClass')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
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
                    ->label('Sync latest active subject & curriculum')
                    ->helperText('Re-points this class to the program’s current active curriculum version and active subject revision before refreshing schedule requirements.'),
                Repeater::make('cohorts')
                    ->label('Linked student sections')
                    ->schema([
                        Select::make('term_cohort_id')
                            ->label('Student section')
                            ->options(fn (): array => TermCohort::query()->where('term_id', $this->termId)->orderBy('reference')->pluck('reference', 'id')->all())
                            ->searchable()
                            ->required()
                            ->distinct(),
                        TextInput::make('expected_count')
                            ->label('Learners in this class')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required(),
                    ])
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

                        $section = Section::query()
                            ->with(['termOffering.curriculumEntry.curriculumVersion', 'termOffering.curriculumEntry.courseSpecification.components', 'courseRevision.components', 'cohorts', 'deliveryGroups'])
                            ->lockForUpdate()
                            ->findOrFail($record->id);

                        if (in_array($section->state, [Section::StateClosed, Section::StateCancelled], true)
                            || PublishedTimetableMeeting::query()->where('section_id', $section->id)->exists()) {
                            throw ValidationException::withMessages(['capacity' => 'Only unpublished, active classes can be edited here. Published classes require a controlled timetable revision.']);
                        }

                        $offering = TermOffering::query()
                            ->with(['curriculumEntry.curriculumVersion', 'curriculumEntry.courseSpecification.components'])
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
                                throw ValidationException::withMessages(['cohorts' => 'Each linked student section requires at least 1 learner.']);
                            }

                            $cohortCounts[$cohortId] = $count;
                        }

                        if ($cohortCounts !== []) {
                            $cohorts = TermCohort::query()
                                ->where('term_id', $term->id)
                                ->whereKey(array_keys($cohortCounts))
                                ->lockForUpdate()
                                ->get()
                                ->keyBy('id');

                            if ($cohorts->count() !== count($cohortCounts)) {
                                throw ValidationException::withMessages(['cohorts' => 'Every linked student section must belong to this exact Term.']);
                            }

                            foreach ($cohortCounts as $cohortId => $count) {
                                $cohort = $cohorts->get($cohortId);

                                if ($cohort instanceof TermCohort && $cohort->confirmed_count !== null && $count > (int) $cohort->confirmed_count) {
                                    throw ValidationException::withMessages(['cohorts' => "Learner count for {$cohort->reference} ({$count}) exceeds its confirmed count ({$cohort->confirmed_count})."]);
                                }
                            }

                            if (array_sum($cohortCounts) > $capacity) {
                                throw ValidationException::withMessages(['capacity' => 'Total linked learner count ('.array_sum($cohortCounts).') exceeds approved class capacity ('.$capacity.').']);
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

                $this->classViewData = null;
                $this->coverageScope = null;
                Notification::make()
                    ->title('Class and room requirements updated')
                    ->body("Schedule requirements refreshed ({$summary['ready']} ready · {$summary['action_required']} needing attention).")
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
                'termOffering.curriculumEntry.courseSpecification.course',
                'termOffering.curriculumEntry.courseSpecification.components',
                'courseRevision.course',
                'courseRevision.components',
                'cohorts',
                'deliveryGroups.schedulingDemands.courseComponent',
                'calendarPackage.teachingGridRows',
                'calendarPackage.datedExceptions',
            ]);
    }

    private function canEditClass(Section $class): bool
    {
        if (! (bool) auth()->user()?->hasRole(User::StaffRoleRegistrar)
            || ! (bool) auth()->user()?->can('update', $class)) {
            return false;
        }

        if ($this->selectedTerm()?->state === Term::StateClosed
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
        $offering = $class->termOffering;
        $currentEntry = $offering?->curriculumEntry;
        $currentSpec = $class->courseRevision ?? $currentEntry?->courseSpecification;
        $courseId = $currentSpec?->course_id;
        $programId = $currentEntry?->curriculumVersion?->program_id ?? $class->cohorts->first()?->program_id;

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

        if ($programId && $currentEntry?->curriculumVersion?->state !== CurriculumVersion::StateActive) {
            return CurriculumEntry::query()
                ->whereHas('curriculumVersion', fn (Builder $q) => $q->where('program_id', $programId)->where('state', CurriculumVersion::StateActive))
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
        $programId = $currentEntry?->curriculumVersion?->program_id ?? $section->cohorts->first()?->program_id;

        if (! $courseId) {
            return $offering;
        }

        $latestActiveSpec = CourseSpecification::query()
            ->where('course_id', $courseId)
            ->where('state', CourseSpecification::StateActive)
            ->with('components')
            ->latest('id')
            ->first();

        $latestActiveEntry = $programId
            ? CurriculumEntry::query()
                ->whereHas('curriculumVersion', fn (Builder $q) => $q->where('program_id', $programId)->where('state', CurriculumVersion::StateActive))
                ->whereHas('courseSpecification', fn (Builder $q) => $q->where('course_id', $courseId))
                ->with(['curriculumVersion', 'courseSpecification.components'])
                ->orderByRaw('CASE WHEN term_type = ? THEN 0 ELSE 1 END', [$term->type])
                ->latest('id')
                ->first()
            : null;

        if ($latestActiveEntry instanceof CurriculumEntry) {
            if ($latestActiveSpec instanceof CourseSpecification && (int) $latestActiveEntry->course_specification_id !== (int) $latestActiveSpec->id) {
                $latestActiveEntry->update(['course_specification_id' => $latestActiveSpec->id]);
            }

            if ((int) $offering->curriculum_entry_id !== (int) $latestActiveEntry->id) {
                $existingOffering = TermOffering::query()
                    ->where('term_id', $term->id)
                    ->where('curriculum_entry_id', $latestActiveEntry->id)
                    ->where('delivery_variant', $offering->delivery_variant)
                    ->whereKeyNot($offering->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingOffering instanceof TermOffering) {
                    $section->term_offering_id = $existingOffering->id;
                    $offering = $existingOffering;
                } else {
                    $offering->update(['curriculum_entry_id' => $latestActiveEntry->id]);
                }
            }

            foreach ($section->cohorts as $cohort) {
                if ((int) $cohort->program_id === (int) $programId
                    && (int) $cohort->curriculum_version_id !== (int) $latestActiveEntry->curriculum_version_id) {
                    $cohort->update(['curriculum_version_id' => $latestActiveEntry->curriculum_version_id]);
                }
            }
        } elseif ($latestActiveSpec instanceof CourseSpecification && $currentEntry instanceof CurriculumEntry) {
            $currentEntry->update(['course_specification_id' => $latestActiveSpec->id]);
        }

        $targetSpecId = $latestActiveSpec?->id ?? $latestActiveEntry?->course_specification_id;

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

        $activeComponentIds = $latestActiveSpec?->components?->modelKeys()
            ?? $latestActiveEntry?->courseSpecification?->components?->modelKeys()
            ?? [];

        if ($activeComponentIds !== []) {
            SchedulingDemand::query()
                ->where('term_offering_id', $offering->id)
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
                'Needs active course revision' => 'Course revision is inactive. Click Edit and sync the latest active subject.',
                'Needs student sections' => 'Link at least one confirmed student section.',
                'Needs meeting requirements' => 'Click Refresh meeting requirements or Edit to sync.',
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
