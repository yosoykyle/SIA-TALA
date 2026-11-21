<?php

namespace App\Filament\Resources\CurriculumVersions\Pages;

use App\Actions\AcademicSetup\AcademicReadinessService;
use App\Actions\AcademicSetup\CourseSpecificationLifecycleService;
use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\CourseSpecifications\Schemas\CourseSpecificationForm;
use App\Filament\Resources\CurriculumVersions\Actions\ActivateCurriculumAction;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\CurriculumVersions\Schemas\CurriculumVersionForm;
use App\Filament\Resources\Programs\ProgramResource;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\ExternalCompetencyRequirement;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class ReviewCurriculumVersion extends Page
{
    use InteractsWithRecord;

    #[Url]
    public ?int $programId = null;

    #[Url]
    public ?int $oversightTermId = null;

    #[Url(as: 'year')]
    public ?int $activeYear = null;

    public string $search = '';

    /** @var array<string, mixed> */
    public array $subjectData = [];

    #[Locked]
    public string $subjectMode = 'existing';

    #[Locked]
    public ?int $selectedEntryId = null;

    #[Locked]
    public bool $subjectEditorOpen = false;

    #[Locked]
    public int $scaffoldYears = 1;

    /** @var list<int> */
    #[Locked]
    public array $enabledSummerYears = [];

    /** @var array<string, mixed>|null */
    private ?array $cachedSheet = null;

    /** @var array<int, array{status:string, color:string, blocker:string, next_action:string}> */
    private array $cachedEntryReadiness = [];

    /** @var array<string, bool> */
    private array $cachedAbilities = [];

    private ?bool $cachedIsEditableDraft = null;

    private ?bool $cachedIsReadyToActivate = null;

    /** @var array<int, string>|null */
    private ?array $cachedCourseSpecificationOptions = null;

    protected static string $resource = CurriculumVersionResource::class;

    protected static ?string $navigationLabel = 'Study plan';

    protected string $view = 'filament.resources.curriculum-versions.pages.review-curriculum-version';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();

        $this->syncYearScaffold(resetToInitial: true);
        $this->resolveInitialActiveYear();
        $this->subjectForm->fill($this->newSubjectDefaults());
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(['record' => $this->getRecord()]), 403);
    }

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;
        $user = auth()->user();

        return $record instanceof CurriculumVersion
            && $user instanceof User
            && $user->can('view', $record);
    }

    public function selectYear(int $year): void
    {
        $this->activeYear = max(1, min($this->scaffoldYears, $year));
    }

    public function updatedSearch(): void
    {
        $needle = mb_strtolower(trim($this->search));

        if ($needle === '') {
            return;
        }

        $entries = $this->loadedCurriculum()->entries;

        $currentYearMatches = $entries->contains(function (CurriculumEntry $entry) use ($needle): bool {
            return $this->parseYearNumber($entry->year_level) === $this->currentActiveYear()
                && $this->entryMatchesSearch($entry, $needle);
        });

        if ($currentYearMatches) {
            return;
        }

        $firstMatchingYear = $entries
            ->filter(fn (CurriculumEntry $entry): bool => $this->entryMatchesSearch($entry, $needle))
            ->map(fn (CurriculumEntry $entry): int => $this->parseYearNumber($entry->year_level))
            ->filter(fn (int $year): bool => $year >= 1)
            ->sort()
            ->first();

        if ($firstMatchingYear !== null) {
            $this->activeYear = $firstMatchingYear;
        }
    }

    public function clearSearch(): void
    {
        $this->search = '';
    }

    public function addYear(): void
    {
        $curriculum = $this->curriculumVersion();
        Gate::forUser($this->actor())->authorize('update', $curriculum);

        if ($this->scaffoldYears >= 8) {
            return;
        }

        $nextYear = $this->scaffoldYears + 1;
        $curriculum->loadMissing('program');

        if ($curriculum->program !== null
            && $this->actor()->can('update', $curriculum->program)
            && $nextYear > (int) ($curriculum->program->duration_years ?? 0)) {
            app(CurriculumWorkbenchService::class)->setProgramDurationYears($this->actor(), $curriculum, $nextYear);
        }

        $this->flushRenderCache();
        $this->getRecord()->refresh();
        $this->scaffoldYears = $nextYear;
        $this->activeYear = $nextYear;
        $this->cacheSchema('subjectForm', null);

        Notification::make()
            ->title("Year {$nextYear} added")
            ->body('Add subjects into its semesters, or remove it if you decide not to use it.')
            ->success()
            ->send();
    }

    public function removeEmptyYear(int $year): void
    {
        $curriculum = $this->curriculumVersion();
        Gate::forUser($this->actor())->authorize('update', $curriculum);

        if ($year !== $this->scaffoldYears || $year <= 1) {
            return;
        }

        if ($curriculum->entries()->where('year_level', (string) $year)->exists()) {
            Notification::make()
                ->title("Year {$year} still has subjects")
                ->body('Move or remove its subjects before removing this year.')
                ->danger()
                ->send();

            return;
        }

        $curriculum->loadMissing('program');
        $targetYears = $year - 1;

        if ($curriculum->program !== null
            && $this->actor()->can('update', $curriculum->program)
            && (int) ($curriculum->program->duration_years ?? 0) >= $year) {
            app(CurriculumWorkbenchService::class)->setProgramDurationYears($this->actor(), $curriculum, $targetYears);
        }

        $this->enabledSummerYears = array_values(array_filter(
            $this->enabledSummerYears,
            fn (int $enabledYear): bool => $enabledYear !== $year,
        ));

        $this->flushRenderCache();
        $this->getRecord()->refresh();
        $this->syncYearScaffold(resetToInitial: true);
        $this->activeYear = $targetYears;
        $this->cacheSchema('subjectForm', null);

        Notification::make()->title("Year {$year} removed")->success()->send();
    }

    public function enableSummerForYear(int $year): void
    {
        $curriculum = $this->curriculumVersion();
        Gate::forUser($this->actor())->authorize('update', $curriculum);

        if ($year < 1 || $year > $this->scaffoldYears) {
            return;
        }

        if (! in_array($year, $this->enabledSummerYears, true)) {
            $this->enabledSummerYears[] = $year;
            sort($this->enabledSummerYears);
        }
    }

    public function removeEmptySummerForYear(int $year): void
    {
        $curriculum = $this->curriculumVersion();
        Gate::forUser($this->actor())->authorize('update', $curriculum);

        if ($curriculum->entries()->where('year_level', (string) $year)->where('term_type', Term::TypeSpecialTerm)->exists()) {
            Notification::make()
                ->title('Special Term still has subjects')
                ->body('Move or remove its subjects before closing Special Term (Summer).')
                ->danger()
                ->send();

            return;
        }

        $this->enabledSummerYears = array_values(array_filter(
            $this->enabledSummerYears,
            fn (int $enabledYear): bool => $enabledYear !== $year,
        ));
    }

    public function sortSemesterEntry(int|string $id, int $position): void
    {
        $curriculum = $this->curriculumVersion();
        Gate::forUser($this->actor())->authorize('update', $curriculum);

        $entry = $curriculum->entries()->findOrFail((int) $id);
        app(CurriculumWorkbenchService::class)->reorderEntrySequence($this->actor(), $entry, $position);
        $this->flushRenderCache();
        $this->getRecord()->refresh();
    }

    /** @return array<string, mixed> */
    private function newSubjectDefaults(?int $year = null, ?string $termType = null): array
    {
        return [
            'year_level' => $year,
            'term_type' => $termType,
            'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            'course_specification_ids' => [],
            'new_subjects' => [[
                'grading_profile_key' => CourseSpecification::GradingProfileServitechV1,
                'grading_profile_version' => 1,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'same_faculty_default' => false,
                'allowed_modalities' => [],
                'components' => [[
                    'component_type' => CourseComponent::TypeLecture,
                    'meeting_pattern' => '2x90',
                    'weekly_contact_hours' => 3,
                    'requires_consecutive_block' => false,
                    'same_faculty' => false,
                    'sequence' => 1,
                ]],
                'prerequisite_ids' => [],
                'corequisite_ids' => [],
                'equivalent_ids' => [],
            ]],
            'grading_profile_key' => CourseSpecification::GradingProfileServitechV1,
            'grading_profile_version' => 1,
            'same_faculty_default' => false,
            'allowed_modalities' => [],
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'components' => [],
            'prerequisite_ids' => [],
            'corequisite_ids' => [],
            'equivalent_ids' => [],
        ];
    }

    public function subjectForm(Schema $schema): Schema
    {
        $definition = in_array($this->subjectMode, ['new', 'edit'], true);
        $fields = [];

        if ($this->subjectMode === 'existing') {
            $fields[] = Select::make('course_specification_ids')
                ->label('Subjects')
                ->multiple()
                ->options(fn (): array => $this->courseSpecificationOptions())
                ->searchable()
                ->preload()
                ->required();
        }

        if ($this->subjectMode === 'new') {
            $fields[] = Repeater::make('new_subjects')
                ->label('New subjects')
                ->extraAttributes(['class' => 'tala-record-repeater'])
                ->schema(CourseSpecificationForm::newSubjectFields(columns: 2))
                ->defaultItems(1)
                ->minItems(1)
                ->reorderable(false)
                ->addActionLabel('Add another subject')
                ->itemLabel(fn (array $state): string => filled($state['code'] ?? null)
                    ? (string) $state['code'].' · '.((string) ($state['title'] ?? 'New subject'))
                    : 'New subject');
        } elseif ($definition) {
            $fields = [...$fields, ...$this->specificationFields()];
        }

        $maxYear = $this->yearLevelLimit();
        $yearChoices = array_combine(
            range(1, $maxYear),
            array_map(fn (int $year): string => "Year {$year}", range(1, $maxYear)),
        );

        $placementGrid = Grid::make(2)->schema([
            Select::make('year_level')
                ->label('Year level')
                ->placeholder('Choose year')
                ->options($yearChoices)
                ->required(),
            Select::make('term_type')
                ->label('Semester')
                ->placeholder('Choose semester')
                ->options(self::semesterFieldOptions())
                ->required(),
            Select::make('requirement_group')
                ->label('Subject type')
                ->options(CurriculumEntry::requirementGroupOptions())
                ->required()
                ->columnSpanFull(),
        ]);

        $placement = $this->selectedEntryId !== null
            ? [
                Section::make('Placement in this curriculum')
                    ->description('Move this subject to another year or semester, or change whether it is required or elective.')
                    ->compact()
                    ->schema([$placementGrid]),
            ]
            : [$placementGrid];

        return $schema->components([...$placement, ...$fields])->statePath('subjectData')->columns(1);
    }

    public function openSubjectEditor(string $mode = 'existing', ?int $year = null, ?string $termType = null): void
    {
        Gate::forUser($this->actor())->authorize('update', $this->curriculumVersion());
        abort_unless(in_array($mode, ['existing', 'new'], true), 422);

        $switchingAddTabs = $this->subjectEditorOpen && $this->selectedEntryId === null;
        $preservedKeys = $switchingAddTabs
            ? ['year_level', 'term_type', 'requirement_group', 'course_specification_ids', 'new_subjects']
            : [];
        $preserved = array_intersect_key($this->subjectData, array_flip($preservedKeys));

        if ($year !== null) {
            $preserved['year_level'] = $year;
        } elseif (! $switchingAddTabs) {
            $preserved['year_level'] = $this->currentActiveYear();
        }

        if ($termType !== null) {
            $preserved['term_type'] = $termType;
        } elseif (! $switchingAddTabs) {
            $preserved['term_type'] = Term::TypeFirstSemester;
        }

        $this->selectedEntryId = null;
        $this->subjectEditorOpen = true;
        $this->subjectMode = $mode;
        $this->resetValidation();
        $this->cacheSchema('subjectForm', null);
        $this->subjectForm->fill([...$this->newSubjectDefaults(), ...$preserved]);
        $this->dispatch('close-modal', id: 'curriculum-subject-inspector');
        $this->dispatch('open-modal', id: 'add-curriculum-subjects');
    }

    public function closeSubjectEditor(): void
    {
        $this->subjectEditorOpen = false;
        $this->selectedEntryId = null;
        $this->subjectMode = 'existing';
        $this->resetValidation();
        $this->cacheSchema('subjectForm', null);
        $this->subjectForm->fill($this->newSubjectDefaults());
        $this->dispatch('close-modal', id: 'add-curriculum-subjects');
        $this->dispatch('close-modal', id: 'curriculum-subject-inspector');
    }

    public function selectCurriculumSubject(int $entryId): void
    {
        Gate::forUser($this->actor())->authorize('view', $this->curriculumVersion());
        $entry = $this->curriculumVersion()->entries()->with('courseSpecification.components')->findOrFail($entryId);
        $this->selectedEntryId = $entry->id;
        $this->subjectEditorOpen = true;
        $this->activeYear = max(1, $this->parseYearNumber($entry->year_level));
        $this->dispatch('close-modal', id: 'add-curriculum-subjects');

        if (! $this->currentUserCan('update')) {
            $this->subjectMode = 'readonly';
            $this->resetValidation();
            $this->dispatch('open-modal', id: 'curriculum-subject-inspector');

            return;
        }

        $this->subjectMode = $this->canEditSpecification($entry) ? 'edit' : 'placement';
        $this->resetValidation();
        $this->cacheSchema('subjectForm', null);
        $this->subjectForm->fill([
            ...$this->newSubjectDefaults(),
            ...$this->specificationFormDefaults($entry),
            'year_level' => max(1, $this->parseYearNumber($entry->year_level)),
            'term_type' => $entry->term_type,
            'requirement_group' => $entry->requirement_group,
        ]);
        $this->dispatch('open-modal', id: 'curriculum-subject-inspector');
    }

    public function saveCurriculumSubject(): void
    {
        Gate::forUser($this->actor())->authorize('update', $this->curriculumVersion());
        $data = $this->subjectForm->getState();
        $service = app(CurriculumWorkbenchService::class);
        $savedYear = max(1, (int) ($data['year_level'] ?? $this->currentActiveYear()));
        $savedTermType = (string) ($data['term_type'] ?? Term::TypeFirstSemester);

        try {
            DB::transaction(function () use ($data, $service): void {
                if ($this->selectedEntryId !== null) {
                    $entry = $this->curriculumVersion()->entries()->findOrFail($this->selectedEntryId);

                    if ($this->subjectMode === 'edit') {
                        $service->updateSpecification($this->actor(), $entry, $data);
                    }

                    $service->updatePlacement($this->actor(), $entry, [
                        ...$data,
                        'sequence' => $entry->sequence,
                        'term_label' => $entry->term_type === $data['term_type']
                            ? $entry->term_label
                            : (Term::typeOptions()[$data['term_type']] ?? ''),
                    ]);
                } elseif ($this->subjectMode === 'new') {
                    $keys = array_keys($this->subjectData['new_subjects'] ?? []);

                    foreach ($data['new_subjects'] as $index => $subject) {
                        try {
                            $service->createSubjectEntry($this->actor(), $this->curriculumVersion(), [
                                ...$subject,
                                'year_level' => $data['year_level'],
                                'term_type' => $data['term_type'],
                                'requirement_group' => $data['requirement_group'],
                            ]);
                        } catch (ValidationException $exception) {
                            $key = $keys[$index] ?? $index;
                            throw ValidationException::withMessages(collect($exception->errors())
                                ->mapWithKeys(fn (array $messages, string $field): array => ['new_subjects.'.$key.'.'.$field => $messages])
                                ->all());
                        }
                    }
                } else {
                    foreach ($data['course_specification_ids'] as $specificationId) {
                        $service->createEntry($this->actor(), $this->curriculumVersion(), [
                            ...$data,
                            'course_specification_id' => $specificationId,
                        ]);
                    }
                }
            });
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => ['subjectData.'.match ($key) {
                    'term_label' => 'term_type',
                    'course_specification_id' => 'course_specification_ids',
                    default => $key,
                } => $messages])
                ->all());
        }

        $this->flushRenderCache();
        $this->getRecord()->refresh();
        $this->syncYearScaffold();
        $this->activeYear = $savedYear;

        if ($savedTermType === Term::TypeSpecialTerm && ! in_array($savedYear, $this->enabledSummerYears, true)) {
            $this->enabledSummerYears[] = $savedYear;
            sort($this->enabledSummerYears);
        }

        $this->closeSubjectEditor();
        Notification::make()->title('Curriculum saved')->success()->send();
    }

    public function getTitle(): string
    {
        return $this->curriculumVersion()->name;
    }

    public function getHeading(): string|Htmlable
    {
        $curriculum = $this->loadedCurriculum();
        $stateLabel = CurriculumVersion::stateOptions()[$curriculum->state] ?? $curriculum->state;
        $badgeColor = match ($curriculum->state) {
            CurriculumVersion::StateActive => 'success',
            CurriculumVersion::StateDraft => 'warning',
            default => 'gray',
        };

        return new HtmlString(Blade::render(<<<'BLADE'
            <span class="tala-curriculum-heading">
                <span class="tala-curriculum-heading__title">{{ $name }}</span>
                <x-filament::badge :color="$badgeColor" size="sm">{{ $stateLabel }}</x-filament::badge>
            </span>
        BLADE, [
            'name' => $curriculum->name,
            'stateLabel' => $stateLabel,
            'badgeColor' => $badgeColor,
        ]));
    }

    public function getSubheading(): string|Htmlable
    {
        $curriculum = $this->loadedCurriculum();
        $sheet = $this->groupedSheet();
        $subjects = (int) $sheet['summary']['total_entries'];
        $units = rtrim(rtrim((string) $sheet['summary']['total_units'], '0'), '.');
        $years = $curriculum->program?->duration_years;
        $intake = $curriculum->effectiveEntryTerm instanceof Term
            ? 'First intake: '.collect([$curriculum->effectiveEntryTerm->label, $curriculum->effectiveEntryTerm->academicYear?->label])->filter()->implode(', ')
            : null;

        return collect([
            $curriculum->program?->name,
            $years ? $years.' '.Str::plural('year', $years) : null,
            $subjects.' '.Str::plural('subject', $subjects),
            $units.' units',
            $intake,
        ])->filter()->implode(' · ');
    }

    public function getBreadcrumbs(): array
    {
        $curriculum = $this->curriculumVersion();
        $curriculum->loadMissing('program');
        $params = array_filter([
            'oversightTermId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null);

        $breadcrumbs = [
            CatalogCurriculaWorkbench::getUrl($params) => 'Programs & Curricula',
            CurriculumVersionResource::getUrl('index', $params) => 'Curricula',
        ];

        if ($curriculum->program !== null) {
            $breadcrumbs[ProgramResource::getUrl('view', ['record' => $curriculum->program, ...$params])] = $curriculum->program->code;
        }

        $breadcrumbs[] = $curriculum->name;

        return $breadcrumbs;
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        $readOnly = $this->isReadOnly();
        $oversightParameters = array_filter([
            'termId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null);

        return [
            Action::make('returnToOversight')
                ->label('Return to Academic Oversight')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(AcademicApprovals::getUrl($oversightParameters))
                ->visible(fn (): bool => $readOnly),
            $this->planSettingsAction(),
            $this->discardDraftAction(),
            $this->activateCurriculumAction(),
            $this->copyToDraftAction(),
        ];
    }

    public function planSettingsAction(): Action
    {
        return Action::make('planSettings')
            ->label('Curriculum details')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Curriculum details')
            ->modalDescription('Update the curriculum name, first student intake, and program length in years.')
            ->modalSubmitActionLabel('Save details')
            ->fillForm(function (): array {
                $curriculum = $this->curriculumVersion();
                $curriculum->loadMissing('program');

                return [
                    'name' => $curriculum->name,
                    'effective_entry_term_id' => $curriculum->effective_entry_term_id,
                    'duration_years' => (int) ($curriculum->program?->duration_years ?: 4),
                ];
            })
            ->schema(function (): array {
                $curriculum = $this->loadedCurriculum();
                $maxPlacedYear = $this->maxPlacedYear();
                $canUpdateProgram = $curriculum->program !== null && $this->actor()->can('update', $curriculum->program);

                return [
                    ...CurriculumVersionForm::planFields(),
                    Select::make('duration_years')
                        ->label('Program length (years)')
                        ->options(array_combine(
                            range(1, 8),
                            array_map(fn (int $year): string => $year.' '.Str::plural('year', $year), range(1, 8)),
                        ))
                        ->disableOptionWhen(fn (string|int $value): bool => (int) $value < $maxPlacedYear)
                        ->helperText($maxPlacedYear > 1
                            ? "Controls how many years appear in this study plan and on the program catalog. Years 1–{$maxPlacedYear} already contain subjects."
                            : 'Controls how many years appear in this study plan and on the program catalog.')
                        ->disabled(! $canUpdateProgram)
                        ->dehydrated($canUpdateProgram)
                        ->required(),
                ];
            })
            ->visible(fn (): bool => $this->isEditableDraft() && $this->currentUserCan('update'))
            ->action(function (array $data): void {
                $curriculum = $this->curriculumVersion();

                try {
                    $updated = app(CurriculumWorkbenchService::class)->updatePlanSettings($this->actor(), $curriculum, $data);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Could not update plan settings')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                    $this->halt();

                    return;
                }

                $this->flushRenderCache();
                $this->record = $updated;
                $this->syncYearScaffold(resetToInitial: true);
                $this->activeYear = max(1, min($this->scaffoldYears, $this->currentActiveYear()));
                $this->cacheSchema('subjectForm', null);

                Notification::make()->title('Plan settings saved')->success()->send();
            });
    }

    public function viewSubjectDetailsAction(): Action
    {
        return Action::make('viewSubjectDetails')
            ->label('All subject details')
            ->link()
            ->color('primary')
            ->visible(fn (): bool => $this->selectedEntryId !== null)
            ->record(fn (): CourseSpecification => $this->authorizedSubject($this->selectedSubject()))
            ->modalHeading(fn (CourseSpecification $record): string => $record->course?->code ? "{$record->course->code} · {$record->title}" : $record->title)
            ->modalContent(fn (CourseSpecification $record): View => view('filament.admin.catalog.course-detail', [
                'course' => $record->course,
                'specification' => $record->loadMissing(['course', 'components', 'requirements.relatedCourse']),
                'revisions' => collect([$record]),
                'canManage' => false,
                'oversightTermId' => $this->oversightTermId,
            ]))
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public function removeSelectedSubjectAction(): Action
    {
        return Action::make('removeSelectedSubject')
            ->label('Remove from plan')
            ->button()
            ->size('sm')
            ->color('danger')
            ->outlined()
            ->icon(Heroicon::OutlinedTrash)
            ->visible(fn (): bool => $this->selectedEntryId !== null && $this->currentUserCan('update'))
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Remove this subject from the study plan?')
            ->modalDescription('The subject stays in the shared catalog. Only this curriculum placement is removed.')
            ->action(function (): void {
                app(CurriculumWorkbenchService::class)->removeEntry($this->actor(), $this->selectedSubject());
                $this->flushRenderCache();
                $this->getRecord()->refresh();
                $this->closeSubjectEditor();
                Notification::make()->title('Subject removed from curriculum')->success()->send();
            });
    }

    public function copySelectedSubjectAction(): Action
    {
        return Action::make('copySelectedSubject')
            ->label('Edit teaching details')
            ->button()
            ->size('sm')
            ->color('primary')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (): bool => $this->selectedEntryId !== null && $this->currentUserCan('update') && $this->subjectMode === 'placement')
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Change this subject only for this curriculum?')
            ->modalDescription('TALA makes an editable copy of the subject for this draft. Other curricula keep the current details.')
            ->modalSubmitActionLabel('Make editable copy')
            ->action(function (array $data = []): void {
                Gate::forUser($this->actor())->authorize('update', $this->curriculumVersion());
                $entry = $this->selectedSubject();
                $revisionCode = filled($data['revision_code'] ?? null)
                    ? (string) $data['revision_code']
                    : 'DRAFT-'.Str::ulid();
                $draft = app(CourseSpecificationLifecycleService::class)->copyToDraft(
                    $this->actor(),
                    $this->authorizedSubject($entry),
                    $revisionCode,
                );
                $entry->update(['course_specification_id' => $draft->id]);
                $this->flushRenderCache();
                $this->getRecord()->refresh();
                $this->selectCurriculumSubject($entry->id);
                Notification::make()
                    ->title('Editable subject copy linked to curriculum')
                    ->body('You can now edit its units, class schedule, and teaching details directly.')
                    ->success()
                    ->send();
            });
    }

    public function replaceSelectedSubjectAction(): Action
    {
        return Action::make('replaceSelectedSubject')
            ->label('Replace subject')
            ->button()
            ->size('sm')
            ->color('gray')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (): bool => $this->selectedEntryId !== null && $this->currentUserCan('update'))
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Replace subject in this curriculum')
            ->modalDescription('Keep this year, semester, and sequence position while swapping in a different catalog subject.')
            ->modalSubmitActionLabel('Replace subject')
            ->fillForm(fn (): array => [
                'course_specification_id' => $this->selectedEntryId !== null
                    ? $this->selectedSubject()->course_specification_id
                    : null,
            ])
            ->schema([
                Select::make('course_specification_id')
                    ->label('Replacement subject')
                    ->options(fn (): array => $this->courseSpecificationOptions())
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $curriculum = $this->curriculumVersion();
                Gate::forUser($this->actor())->authorize('update', $curriculum);
                $entry = $this->selectedSubject();
                $replacementSpecId = (int) ($data['course_specification_id'] ?? 0);

                if ($replacementSpecId === (int) $entry->course_specification_id) {
                    Notification::make()->title('No change made')->info()->send();

                    return;
                }

                try {
                    DB::transaction(function () use ($entry, $replacementSpecId): void {
                        $lockedEntry = CurriculumEntry::query()
                            ->with('curriculumVersion')
                            ->lockForUpdate()
                            ->findOrFail($entry->id);
                        Gate::forUser($this->actor())->authorize('update', $lockedEntry->curriculumVersion);

                        $replacementSpec = CourseSpecification::query()->findOrFail($replacementSpecId);

                        $duplicateExists = CurriculumEntry::query()
                            ->where('curriculum_version_id', $lockedEntry->curriculum_version_id)
                            ->where('course_specification_id', $replacementSpec->id)
                            ->where('year_level', $lockedEntry->year_level)
                            ->where('term_label', $lockedEntry->term_label)
                            ->whereKeyNot($lockedEntry->id)
                            ->exists();

                        if ($duplicateExists) {
                            throw ValidationException::withMessages([
                                'course_specification_id' => 'That subject is already placed in this same year and semester.',
                            ]);
                        }

                        $previousSpecId = $lockedEntry->course_specification_id;
                        $lockedEntry->update(['course_specification_id' => $replacementSpec->id]);

                        activity()
                            ->performedOn($lockedEntry)
                            ->causedBy($this->actor())
                            ->event('curriculum_workbench_row_replaced')
                            ->withProperties([
                                'curriculum_version_id' => $lockedEntry->curriculum_version_id,
                                'previous_course_specification_id' => $previousSpecId,
                                'new_course_specification_id' => $replacementSpec->id,
                            ])
                            ->log('Curriculum subject replaced');
                    });
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Cannot replace subject')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();

                    return;
                }

                $this->flushRenderCache();
                $this->getRecord()->refresh();
                $this->selectCurriculumSubject($entry->id);
                Notification::make()
                    ->title('Subject replaced in curriculum')
                    ->success()
                    ->send();
            });
    }

    public function addCurriculumRowAction(): Action
    {
        return Action::make('addCurriculumRow')
            ->label('Add subjects')
            ->icon(Heroicon::OutlinedPlus)
            ->color(fn (): string => $this->isReadyToActivate() ? 'gray' : 'primary')
            ->visible(fn (): bool => $this->currentUserCan('update'))
            ->action(fn () => $this->openSubjectEditor('existing', $this->currentActiveYear()));
    }

    public function addExternalCompetencyAction(): Action
    {
        return Action::make('addExternalCompetency')
            ->label('Add qualification')
            ->modalHeading('Add external qualification')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('For a qualification assessed outside the school, such as a TESDA certificate. Students’ results are recorded later in Grades & Completion.')
            ->modalSubmitActionLabel('Add qualification')
            ->fillForm(['treatment' => ExternalCompetencyRequirement::TreatmentTrackedOnly])
            ->schema(fn (): array => $this->externalCompetencyFields())
            ->visible(fn (): bool => $this->currentUserCan('update'))
            ->action(function (array $data): void {
                app(CurriculumWorkbenchService::class)->addExternalCompetencyRequirement($this->actor(), $this->curriculumVersion(), $data);
                $this->flushRenderCache();
                $this->getRecord()->refresh();
                Notification::make()->title('External qualification added')->success()->send();
            });
    }

    public function editExternalCompetencyAction(): Action
    {
        return Action::make('editExternalCompetency')
            ->label('Edit qualification')
            ->modalHeading('Edit external qualification')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Save changes')
            ->fillForm(function (array $arguments): array {
                $requirement = $this->resolveRequirement((int) ($arguments['requirementId'] ?? 0));

                return $requirement->only(['qualification_label', 'qualification_level', 'curriculum_entry_id', 'treatment']);
            })
            ->schema(fn (): array => $this->externalCompetencyFields())
            ->visible(fn (): bool => $this->currentUserCan('update'))
            ->action(function (array $data, array $arguments): void {
                $requirement = $this->resolveRequirement((int) ($arguments['requirementId'] ?? 0));
                app(CurriculumWorkbenchService::class)->updateExternalCompetencyRequirement($this->actor(), $requirement, [
                    ...$data,
                    'requirement_code' => $requirement->requirement_code,
                ]);
                $this->flushRenderCache();
                $this->getRecord()->refresh();
                Notification::make()->title('External qualification updated')->success()->send();
            });
    }

    public function removeExternalCompetencyAction(): Action
    {
        return Action::make('removeExternalCompetency')
            ->label('Remove qualification')
            ->color('danger')
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Remove this external qualification?')
            ->modalDescription('It is removed from this draft curriculum only.')
            ->visible(fn (): bool => $this->currentUserCan('update'))
            ->action(function (array $arguments): void {
                app(CurriculumWorkbenchService::class)->removeExternalCompetencyRequirement($this->actor(), $this->resolveRequirement((int) ($arguments['requirementId'] ?? 0)));
                $this->flushRenderCache();
                $this->getRecord()->refresh();
                Notification::make()->title('External requirement removed')->success()->send();
            });
    }

    public function activateCurriculumAction(): Action
    {
        return ActivateCurriculumAction::make()->record(fn (): CurriculumVersion => $this->curriculumVersion());
    }

    public function discardDraftAction(): Action
    {
        return Action::make('discardDraft')
            ->label('Discard draft')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->outlined()
            ->requiresConfirmation()
            ->modalHeading('Discard this draft curriculum?')
            ->modalDescription('Its draft subject placements will be permanently removed. Active curricula and student records are not affected.')
            ->modalSubmitActionLabel('Discard draft')
            ->authorize(fn (): bool => $this->currentUserCan('update')
                && app(CurriculumVersionLifecycleService::class)->canDiscardDraft($this->curriculumVersion()))
            ->visible(fn (): bool => $this->currentUserCan('update')
                && app(CurriculumVersionLifecycleService::class)->canDiscardDraft($this->curriculumVersion()))
            ->action(function (): void {
                try {
                    app(CurriculumVersionLifecycleService::class)->discardDraft($this->curriculumVersion(), $this->actor());
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Draft curriculum cannot be discarded')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Draft curriculum discarded')
                    ->success()
                    ->send();

                $this->redirect(CurriculumVersionResource::getUrl('index', array_filter([
                    'oversightTermId' => $this->oversightTermId,
                ], fn (mixed $value): bool => $value !== null)));
            });
    }

    public function externalQualificationsAction(): Action
    {
        return Action::make('externalQualifications')
            ->label(function (): string {
                $count = count($this->groupedSheet()['external_competencies']);

                return $count > 0 ? "External qualifications ({$count})" : 'External qualifications';
            })
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('External qualifications')
            ->modalDescription('Qualifications assessed outside the school, such as TESDA National Certificates.')
            ->registerModalActions([
                $this->addExternalCompetencyAction(),
                $this->editExternalCompetencyAction(),
                $this->removeExternalCompetencyAction(),
            ])
            ->modalContent(fn (): View => view('filament.resources.curriculum-versions.partials.external-qualifications', [
                'qualifications' => $this->groupedSheet()['external_competencies'],
                'canEdit' => $this->currentUserCan('update'),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->visible(fn (): bool => $this->currentUserCan('update') || $this->groupedSheet()['external_competencies'] !== []);
    }

    public function copyToDraftAction(): Action
    {
        return Action::make('copyToDraft')
            ->label('Duplicate curriculum')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('primary')
            ->modalHeading('Duplicate curriculum into a new draft')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Start with the subjects in this plan. Changes are saved in a new draft; this curriculum and its student assignments stay unchanged.')
            ->modalSubmitActionLabel('Duplicate curriculum')
            ->fillForm(fn (): array => [
                'name' => $this->curriculumVersion()->name.' (Draft)',
                'effective_entry_term_id' => $this->curriculumVersion()->effective_entry_term_id,
            ])
            ->schema(fn (): array => CurriculumVersionForm::planFields())
            ->visible(fn (): bool => ! $this->isEditableDraft() && $this->currentUserCan('copy'))
            ->action(function (array $data): void {
                $draft = app(CurriculumVersionLifecycleService::class)->copyToDraft(
                    actor: $this->actor(),
                    source: $this->curriculumVersion(),
                    versionCode: CurriculumVersionForm::draftCodeFor($this->curriculumVersion()->program_id),
                    name: (string) $data['name'],
                    effectiveEntryTermId: filled($data['effective_entry_term_id'] ?? null) ? (int) $data['effective_entry_term_id'] : null,
                );
                $this->redirect(CurriculumVersionResource::getUrl('review', array_filter([
                    'record' => $draft,
                    'programId' => $draft->program_id,
                    'oversightTermId' => $this->oversightTermId,
                ], fn (mixed $value): bool => $value !== null)));
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $curriculum = $this->loadedCurriculum();
        $sheet = $this->groupedSheet();
        $canEdit = $this->currentUserCan('update');
        $activeYear = $this->currentActiveYear();
        $needle = mb_strtolower(trim($this->search));
        $isSearching = $needle !== '';

        $allEntries = $curriculum->entries
            ->sortBy([
                fn (CurriculumEntry $a, CurriculumEntry $b): int => $this->parseYearNumber($a->year_level) <=> $this->parseYearNumber($b->year_level),
                fn (CurriculumEntry $a, CurriculumEntry $b): int => $this->termOrder($a->term_type) <=> $this->termOrder($b->term_type),
                fn (CurriculumEntry $a, CurriculumEntry $b): int => $a->sequence <=> $b->sequence,
                fn (CurriculumEntry $a, CurriculumEntry $b): int => $a->id <=> $b->id,
            ])
            ->values();

        $entriesByYear = $allEntries->groupBy(fn (CurriculumEntry $entry): int => $this->parseYearNumber($entry->year_level));
        $yearTabs = [];
        $totalMatches = 0;

        for ($year = 1; $year <= $this->scaffoldYears; $year++) {
            /** @var Collection<int, CurriculumEntry> $yearEntries */
            $yearEntries = $entriesByYear->get($year, collect());
            $matchCount = $isSearching
                ? $yearEntries->filter(fn (CurriculumEntry $entry): bool => $this->entryMatchesSearch($entry, $needle))->count()
                : $yearEntries->count();
            $totalMatches += $matchCount;

            $incompleteCount = $yearEntries
                ->filter(fn (CurriculumEntry $entry): bool => $this->entryReadiness($entry)['status'] !== 'Specification ready')
                ->count();

            $yearTabs[] = [
                'year' => $year,
                'label' => "Year {$year}",
                'ordinal_label' => $this->ordinalYearLabel($year),
                'total_count' => $yearEntries->count(),
                'match_count' => $matchCount,
                'incomplete_count' => $incompleteCount,
                'units' => $this->formatUnits($yearEntries->sum(fn (CurriculumEntry $entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0))),
            ];
        }

        /** @var Collection<int, CurriculumEntry> $activeYearEntries */
        $activeYearEntries = $entriesByYear->get($activeYear, collect());
        $activeYearTotalCount = $activeYearEntries->count();
        $activeYearUnits = $this->formatUnits($activeYearEntries->sum(fn (CurriculumEntry $entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0)));

        $regularSemesters = [
            $this->buildSemesterPanel($activeYear, Term::TypeFirstSemester, 'First Semester', $activeYearEntries, $needle),
            $this->buildSemesterPanel($activeYear, Term::TypeSecondSemester, 'Second Semester', $activeYearEntries, $needle),
        ];

        $hasSummerEntries = $activeYearEntries->contains(fn (CurriculumEntry $entry): bool => $entry->term_type === Term::TypeSpecialTerm);
        $summerVisible = $hasSummerEntries || in_array($activeYear, $this->enabledSummerYears, true);
        $summerPanel = $summerVisible
            ? $this->buildSemesterPanel($activeYear, Term::TypeSpecialTerm, 'Special Term (Summer)', $activeYearEntries, $needle)
            : null;

        $selectedEntry = $this->selectedEntryId !== null
            ? $allEntries->firstWhere('id', $this->selectedEntryId)
            : null;
        $programId = $this->programId ?? $curriculum->program_id;
        $selectedCourse = $selectedEntry?->courseSpecification?->course;

        $selectedReadiness = $selectedEntry instanceof CurriculumEntry
            ? $this->entryReadiness($selectedEntry)
            : null;

        return [
            'curriculum' => $curriculum,
            'readOnly' => $this->isReadOnly(),
            'canEdit' => $canEdit,
            'isEmptyPlan' => $allEntries->isEmpty(),
            'isSearching' => $isSearching,
            'totalMatches' => $totalMatches,
            'activeYear' => $activeYear,
            'activeYearTab' => collect($yearTabs)->firstWhere('year', $activeYear),
            'activeYearTotalCount' => $activeYearTotalCount,
            'activeYearUnits' => $activeYearUnits,
            'yearTabs' => $yearTabs,
            'canAddYear' => $canEdit && $this->scaffoldYears < 8,
            'canRemoveActiveYear' => $canEdit && $activeYear === $this->scaffoldYears && $activeYear > 1 && $activeYearTotalCount === 0,
            'summerVisible' => $summerVisible,
            'canRemoveSummer' => $canEdit && $summerVisible && ! $hasSummerEntries,
            'regularSemesters' => $regularSemesters,
            'summerPanel' => $summerPanel,
            'selectedSubject' => $selectedEntry,
            'selectedSubjectWarning' => ($selectedReadiness !== null && $selectedReadiness['status'] !== 'Specification ready')
                ? $this->entryWarning($selectedEntry, $selectedReadiness['status'])
                : null,
            'selectedSubjectUrl' => $selectedCourse
                ? CourseResource::getUrl('view', array_filter([
                    'record' => $selectedCourse,
                    'revision' => $selectedEntry?->course_specification_id,
                    'curriculumVersionId' => $curriculum->id,
                    'programId' => $programId,
                    'year' => $activeYear,
                    'oversightTermId' => $this->oversightTermId,
                ], fn (mixed $value): bool => $value !== null))
                : null,
            'selectedSubjectFacts' => $selectedEntry !== null
                ? $this->subjectFacts($selectedEntry)
                : [],
            'externalQualifications' => $sheet['external_competencies'],
            'protectedReason' => $this->protectedReason($curriculum),
            'attention' => $this->activationAttention($curriculum, (int) $sheet['summary']['blocked_entries']),
        ];
    }

    private function ordinalYearLabel(int $year): string
    {
        return match ($year) {
            1 => 'First Year',
            2 => 'Second Year',
            3 => 'Third Year',
            4 => 'Fourth Year',
            5 => 'Fifth Year',
            6 => 'Sixth Year',
            7 => 'Seventh Year',
            8 => 'Eighth Year',
            default => "Year {$year}",
        };
    }

    private function shortOrdinalYearLabel(int $year): string
    {
        return match ($year) {
            1 => '1st Year',
            2 => '2nd Year',
            3 => '3rd Year',
            default => $year > 0 ? "{$year}th Year" : 'Year 1',
        };
    }

    /**
     * @param  Collection<int, CurriculumEntry>  $yearEntries
     * @return array<string, mixed>
     */
    private function buildSemesterPanel(
        int $year,
        string $termType,
        string $label,
        Collection $yearEntries,
        string $needle,
    ): array {
        $allSemesterEntries = $yearEntries
            ->where('term_type', $termType)
            ->values();

        $visibleEntries = $needle !== ''
            ? $allSemesterEntries->filter(fn (CurriculumEntry $entry): bool => $this->entryMatchesSearch($entry, $needle))->values()
            : $allSemesterEntries;

        $totalUnits = $this->formatUnits($allSemesterEntries->sum(fn (CurriculumEntry $entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0)));

        $rows = $visibleEntries->map(function (CurriculumEntry $entry): array {
            $spec = $entry->courseSpecification;
            $status = $this->entryReadiness($entry);
            $prerequisites = $spec instanceof CourseSpecification
                ? $spec->requirements
                    ->where('rule_type', CourseRequirement::TypePrerequisite)
                    ->map(fn (CourseRequirement $requirement): ?string => $requirement->relatedCourse?->code)
                    ->filter()
                    ->implode(', ')
                : '';

            return [
                'id' => $entry->id,
                'code' => (string) ($spec?->course?->code ?? '—'),
                'title' => (string) ($spec?->title ?? 'Subject details unavailable'),
                'units' => $spec instanceof CourseSpecification
                    ? $this->formatUnits((float) $spec->credit_units)
                    : '-',
                'is_elective' => $entry->requirement_group !== CurriculumEntry::RequirementGroupRequired,
                'elective_label' => CurriculumEntry::requirementGroupOptions()[$entry->requirement_group] ?? $entry->requirement_group,
                'prerequisites' => $prerequisites,
                'incomplete_message' => $status['status'] !== 'Specification ready'
                    ? ($status['status'] === 'Specification missing' ? 'No subject linked' : 'Missing teaching details')
                    : null,
            ];
        })->all();

        return [
            'year' => $year,
            'term_type' => $termType,
            'label' => $label,
            'total_count' => $allSemesterEntries->count(),
            'total_units' => $totalUnits,
            'rows' => $rows,
        ];
    }

    private function entryMatchesSearch(CurriculumEntry $entry, string $needle): bool
    {
        $code = mb_strtolower((string) ($entry->courseSpecification?->course?->code ?? ''));
        $title = mb_strtolower((string) ($entry->courseSpecification?->title ?? ''));

        return str_contains($code, $needle) || str_contains($title, $needle);
    }

    private function termOrder(string $termType): int
    {
        return match ($termType) {
            Term::TypeFirstSemester => 1,
            Term::TypeSecondSemester => 2,
            Term::TypeSpecialTerm => 3,
            default => 4,
        };
    }

    private function formatUnits(float|int|string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function syncYearScaffold(bool $resetToInitial = false): void
    {
        $curriculum = $this->loadedCurriculum();
        $programYears = (int) ($curriculum->program?->duration_years ?: 4);
        $maxEntryYear = $this->maxPlacedYear();

        $computed = max(1, min(8, max($programYears, $maxEntryYear)));
        $this->scaffoldYears = $resetToInitial
            ? $computed
            : max($computed, $this->scaffoldYears);

        $summerYearsFromEntries = $curriculum->entries
            ->where('term_type', Term::TypeSpecialTerm)
            ->pluck('year_level')
            ->map(fn (mixed $year): int => $this->parseYearNumber($year))
            ->filter(fn (int $year): bool => $year >= 1)
            ->unique()
            ->values()
            ->all();

        $this->enabledSummerYears = array_values(array_unique([
            ...$this->enabledSummerYears,
            ...$summerYearsFromEntries,
        ]));
        sort($this->enabledSummerYears);
    }

    private function resolveInitialActiveYear(): void
    {
        if ($this->activeYear !== null && $this->activeYear >= 1 && $this->activeYear <= $this->scaffoldYears) {
            return;
        }

        $firstPopulatedYear = $this->loadedCurriculum()->entries
            ->pluck('year_level')
            ->map(fn (mixed $year): int => $this->parseYearNumber($year))
            ->filter(fn (int $year): bool => $year >= 1)
            ->min();

        $this->activeYear = $firstPopulatedYear ?: 1;
    }

    private function parseYearNumber(mixed $yearLevel): int
    {
        if (is_int($yearLevel)) {
            return $yearLevel;
        }

        $normalized = strtolower(trim((string) $yearLevel));

        if (preg_match('/\d+/', $normalized, $matches) === 1) {
            return (int) $matches[0];
        }

        return match (true) {
            str_contains($normalized, 'first') => 1,
            str_contains($normalized, 'second') => 2,
            str_contains($normalized, 'third') => 3,
            str_contains($normalized, 'fourth') => 4,
            str_contains($normalized, 'fifth') => 5,
            str_contains($normalized, 'sixth') => 6,
            str_contains($normalized, 'seventh') => 7,
            str_contains($normalized, 'eighth') => 8,
            default => 0,
        };
    }

    private function currentActiveYear(): int
    {
        return max(1, min($this->scaffoldYears, $this->activeYear ?? 1));
    }

    /**
     * @return array<string, string>
     */
    private static function semesterFieldOptions(): array
    {
        $options = Term::typeOptions();
        $options[Term::TypeSpecialTerm] = 'Special Term (Summer)';

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function intakeTermOptions(): array
    {
        return Term::query()
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(fn (Term $term): array => [
                $term->id => collect([
                    $term->academicYear?->label,
                    $term->label,
                ])->filter()->implode(' · '),
            ])
            ->all();
    }

    /**
     * Summarize a placed subject's shared teaching facts for the inspector header.
     *
     * @return array<string, string>
     */
    private function subjectFacts(?CurriculumEntry $entry): array
    {
        $specification = $entry?->courseSpecification;

        if (! $specification instanceof CourseSpecification) {
            return [];
        }

        $specification->loadMissing(['components', 'requirements.relatedCourse']);
        $teaching = $specification->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged
            ? 'Arranged / flexible schedule'
            : $specification->components->sortBy('sequence')->map(fn (CourseComponent $component): string => sprintf(
                '%s %s h/week',
                CourseComponent::typeOptions()[$component->component_type] ?? $component->component_type,
                $this->formatUnits((float) $component->weekly_contact_hours),
            ))->implode(' + ');
        $deliveryModes = ! empty($specification->allowed_modalities)
            ? collect($specification->allowed_modalities)->map(fn (string $mode): string => CourseSpecification::modalityOptions()[$mode] ?? $mode)->implode(', ')
            : 'Not set yet';
        $prerequisites = $specification->requirements
            ->where('rule_type', CourseRequirement::TypePrerequisite)
            ->map(fn (CourseRequirement $requirement): ?string => $requirement->relatedCourse?->code)
            ->filter()
            ->implode(', ');

        $facts = [];

        if (! $this->currentUserCan('update') && $entry instanceof CurriculumEntry) {
            $semester = self::semesterFieldOptions()[$entry->term_type] ?? ($entry->term_label ?: $entry->term_type);
            $yearNumber = max(1, $this->parseYearNumber($entry->year_level));
            $facts['Placement'] = $this->shortOrdinalYearLabel($yearNumber).' · '.$semester;
            $facts['Type'] = CurriculumEntry::requirementGroupOptions()[$entry->requirement_group] ?? $entry->requirement_group;
        }

        $facts['Units'] = $this->formatUnits((float) $specification->credit_units);
        $facts['Weekly schedule'] = $teaching !== '' ? $teaching : 'Not set yet';
        $facts['Delivery modes'] = $deliveryModes;
        $facts['Prerequisites'] = $prerequisites !== '' ? $prerequisites : 'None';

        if (! $this->currentUserCan('update') && filled($specification->description)) {
            $facts['Description'] = (string) $specification->description;
        }

        return $facts;
    }

    private function protectedReason(CurriculumVersion $curriculum): ?string
    {
        if ($this->isEditableDraft() || $this->isReadOnly()) {
            return null;
        }

        return match ($curriculum->state) {
            CurriculumVersion::StateActive => 'This is the active study plan, so it can’t be edited. To change it, create a revised draft; students already assigned keep this plan.',
            CurriculumVersion::StateSuperseded => 'This study plan was replaced and is kept as history. Students assigned to it keep it.',
            default => 'This curriculum is already in use, so it can’t be edited. Create a revised draft to make changes.',
        };
    }

    /**
     * Name the concrete blockers an editable draft must clear before activation.
     *
     * @return list<array{message: string, url?: string, link?: string}>
     */
    private function activationAttention(CurriculumVersion $curriculum, int $blockedEntries): array
    {
        if (! $this->isEditableDraft() || ! $this->currentUserCan('update')) {
            return [];
        }

        $items = [];

        if ($blockedEntries > 0) {
            $items[] = [
                'message' => $blockedEntries.' '.Str::plural('subject', $blockedEntries).' '.($blockedEntries === 1 ? 'needs' : 'need').' teaching details. Select any highlighted subject below to complete it.',
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function specificationFormDefaults(CurriculumEntry $entry): array
    {
        $specification = $entry->courseSpecification;

        if (! $specification instanceof CourseSpecification) {
            return [];
        }

        $specification->loadMissing('components');

        return [
            'title' => $specification->title,
            'credit_units' => $specification->credit_units,
            'grading_profile_key' => $specification->grading_profile_key,
            'grading_profile_version' => $specification->grading_profile_version,
            'scheduling_treatment' => $specification->scheduling_treatment,
            'allowed_modalities' => $specification->allowed_modalities,
            'same_faculty_default' => $specification->same_faculty_default,
            'components' => $specification->components
                ->sortBy('sequence')
                ->map(fn (CourseComponent $component): array => [
                    'component_type' => $component->component_type,
                    'weekly_contact_hours' => $component->weekly_contact_hours,
                    'meeting_pattern' => $component->meeting_pattern,
                    'room_type_default' => $component->room_type_default,
                    'required_room_feature_keys' => $component->required_room_feature_keys,
                    'modality_restriction' => $component->modality_restriction,
                    'requires_consecutive_block' => $component->requires_consecutive_block,
                    'same_faculty' => $component->same_faculty,
                    'sequence' => $component->sequence,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, CheckboxList|Repeater|Select|TextInput|Toggle>
     */
    private function specificationFields(): array
    {
        return CourseSpecificationForm::workbenchFields();
    }

    /**
     * @return array<int, Grid>
     */
    private function externalCompetencyFields(): array
    {
        $curriculum = $this->loadedCurriculum();
        $entryOptions = $curriculum->entries
            ->sortBy([
                fn (CurriculumEntry $a, CurriculumEntry $b): int => $this->parseYearNumber($a->year_level) <=> $this->parseYearNumber($b->year_level),
                fn (CurriculumEntry $a, CurriculumEntry $b): int => $this->termOrder($a->term_type) <=> $this->termOrder($b->term_type),
                fn (CurriculumEntry $a, CurriculumEntry $b): int => $a->sequence <=> $b->sequence,
            ])
            ->mapWithKeys(fn (CurriculumEntry $entry): array => [
                $entry->id => collect([
                    $this->shortOrdinalYearLabel(max(1, $this->parseYearNumber($entry->year_level))),
                    $entry->term_label,
                    $entry->courseSpecification?->course?->code,
                    $entry->courseSpecification?->title,
                ])->filter()->implode(' · '),
            ])
            ->all();

        return [
            Grid::make(2)->schema([
                TextInput::make('qualification_label')
                    ->label('Qualification')
                    ->placeholder('e.g. Computer Systems Servicing')
                    ->required()
                    ->maxLength(255),
                TextInput::make('qualification_level')
                    ->label('Level (optional)')
                    ->placeholder('e.g. NC II')
                    ->maxLength(128),
                Select::make('curriculum_entry_id')
                    ->label('Linked subject (optional)')
                    ->helperText('Choose one only if the curriculum ties the qualification to a subject.')
                    ->options($entryOptions)
                    ->searchable()
                    ->nullable()
                    ->columnSpanFull(),
                Radio::make('treatment')
                    ->label('Effect on graduation')
                    ->options([
                        ExternalCompetencyRequirement::TreatmentTrackedOnly => 'Record the result only',
                        ExternalCompetencyRequirement::TreatmentCompletionRequired => 'Required for graduation',
                    ])
                    ->descriptions([
                        ExternalCompetencyRequirement::TreatmentTrackedOnly => 'Shown in the student’s curriculum evaluation. Does not block graduation.',
                        ExternalCompetencyRequirement::TreatmentCompletionRequired => 'Choose only when the curriculum requires this qualification to graduate.',
                    ])
                    ->required()
                    ->columnSpanFull(),
            ]),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function courseSpecificationOptions(): array
    {
        return $this->cachedCourseSpecificationOptions ??= CourseSpecification::query()
            ->with('course')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (CourseSpecification $specification): array => [
                $specification->id => collect([
                    $specification->course?->code,
                    $specification->title,
                    CourseSpecification::stateOptions()[$specification->state] ?? str($specification->state)->headline(),
                ])->filter()->implode(' · '),
            ])
            ->all();
    }

    private function entryWarning(?CurriculumEntry $record, string $status): string
    {
        return match ($status) {
            'Specification missing' => 'No subject linked. Remove this row or choose a subject.',
            default => $record !== null && $this->canEditSpecification($record)
                ? 'Needs teaching details. Complete units, delivery modes, and class schedule below.'
                : 'Needs teaching details. Revise the subject for this curriculum to complete them.',
        };
    }

    private function flushRenderCache(): void
    {
        $this->cachedSheet = null;
        $this->cachedEntryReadiness = [];
        $this->cachedAbilities = [];
        $this->cachedIsEditableDraft = null;
        $this->cachedIsReadyToActivate = null;
        $this->cachedCourseSpecificationOptions = null;
    }

    private function loadedCurriculum(): CurriculumVersion
    {
        $curriculum = $this->curriculumVersion();
        $curriculum->loadMissing([
            'program',
            'effectiveEntryTerm.academicYear',
            'entries.courseSpecification.course',
            'entries.courseSpecification.components',
            'entries.courseSpecification.requirements.relatedCourse',
            'externalCompetencyRequirements.curriculumEntry.courseSpecification.course',
        ]);

        return $curriculum;
    }

    /**
     * @return array<string, mixed>
     */
    private function groupedSheet(): array
    {
        return $this->cachedSheet ??= app(CurriculumWorkbenchService::class)->groupedSheet($this->loadedCurriculum());
    }

    /**
     * @return array{status:string, color:string, blocker:string, next_action:string}
     */
    private function entryReadiness(CurriculumEntry $entry): array
    {
        return $this->cachedEntryReadiness[$entry->id] ??= app(AcademicReadinessService::class)->entryReadiness($entry);
    }

    private function maxPlacedYear(): int
    {
        return (int) ($this->loadedCurriculum()->entries
            ->max(fn (CurriculumEntry $entry): int => $this->parseYearNumber($entry->year_level)) ?? 0);
    }

    private function yearLevelLimit(): int
    {
        $curriculum = $this->loadedCurriculum();
        $programYears = (int) ($curriculum->program?->duration_years ?: 4);

        return max(1, $this->scaffoldYears, $programYears, $this->maxPlacedYear());
    }

    private function isEditableDraft(): bool
    {
        if ($this->cachedIsEditableDraft !== null) {
            return $this->cachedIsEditableDraft;
        }

        if ($this->cachedSheet !== null) {
            return $this->cachedIsEditableDraft = (bool) $this->cachedSheet['is_editable'];
        }

        return $this->cachedIsEditableDraft = $this->curriculumVersion()->isEditableDraft();
    }

    private function curriculumVersion(): CurriculumVersion
    {
        $record = $this->getRecord();
        abort_unless($record instanceof CurriculumVersion, 404);

        return $record;
    }

    private function selectedSubject(): CurriculumEntry
    {
        return $this->curriculumVersion()->entries()->with('courseSpecification.course')->findOrFail($this->selectedEntryId);
    }

    private function authorizedSubject(CurriculumEntry $entry): CourseSpecification
    {
        abort_unless($entry->curriculum_version_id === $this->curriculumVersion()->id, 404);
        $subject = $entry->courseSpecification;
        abort_unless($subject instanceof CourseSpecification, 404);
        Gate::forUser($this->actor())->authorize('view', $subject);

        return $subject;
    }

    private function resolveRequirement(int $requirementId): ExternalCompetencyRequirement
    {
        return ExternalCompetencyRequirement::query()
            ->where('curriculum_version_id', $this->curriculumVersion()->id)
            ->findOrFail($requirementId);
    }

    private function currentUserCan(string $ability): bool
    {
        return $this->cachedAbilities[$ability] ??= $this->actor()->can($ability, $this->curriculumVersion());
    }

    private function isReadyToActivate(): bool
    {
        if ($this->cachedIsReadyToActivate !== null) {
            return $this->cachedIsReadyToActivate;
        }

        $sheet = $this->groupedSheet();

        return $this->cachedIsReadyToActivate = $this->isEditableDraft()
            && (int) $sheet['summary']['total_entries'] > 0
            && (int) $sheet['summary']['blocked_entries'] === 0
            && app(CurriculumVersionLifecycleService::class)->readinessErrors($this->loadedCurriculum()) === [];
    }

    private function canEditSpecification(CurriculumEntry $entry): bool
    {
        $specification = $entry->courseSpecification;

        return $this->currentUserCan('update')
            && $specification instanceof CourseSpecification
            && $this->actor()->can('update', $specification);
    }

    private function isReadOnly(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? true;
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
