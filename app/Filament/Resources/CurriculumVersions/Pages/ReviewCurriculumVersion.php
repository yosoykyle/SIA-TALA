<?php

namespace App\Filament\Resources\CurriculumVersions\Pages;

use App\Actions\AcademicSetup\AcademicReadinessService;
use App\Actions\AcademicSetup\CourseSpecificationLifecycleService;
use App\Actions\AcademicSetup\CurriculumSpreadsheetExportService;
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
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\ExternalCompetencyRequirement;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\User;
use App\Support\CurriculumSpreadsheetWizard;
use App\Support\RoomEquipment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\GridDirection;
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
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReviewCurriculumVersion extends Page
{
    use InteractsWithRecord;

    #[Url]
    public ?int $programId = null;

    #[Url]
    public ?int $oversightTermId = null;

    #[Url(as: 'year')]
    public ?int $activeYear = null;

    #[Url(as: 'step')]
    public string $activeStep = 'year';

    public string $search = '';

    /** @var array<string, mixed> */
    public array $subjectData = [];

    /** @var list<int> */
    public array $selectedEntryIds = [];

    /** @var array<int, array{from_year: int, from_term_type: string, from_term_label: string, from_sequence: int, code: string, kind: string}> */
    public array $recentlyMovedEntries = [];

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

    #[Locked]
    public bool $confirmingExitDiscard = false;

    #[Locked]
    public ?string $lastSkippedImportCsvPath = null;

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

    /** @var array{options: array<int, string>, descriptions: array<int, string>}|null */
    private ?array $cachedAvailableCatalogSubjects = null;

    /** @var array<int, string>|null */
    private ?array $cachedCourseOptions = null;

    /** @var list<string>|null */
    private ?array $cachedReviewBlockers = null;

    protected static string $resource = CurriculumVersionResource::class;

    protected static ?string $navigationLabel = 'Study plan';

    protected string $view = 'filament.resources.curriculum-versions.pages.review-curriculum-version';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();

        $this->syncYearScaffold(resetToInitial: true);
        $this->resolveInitialActiveYear();
        $this->activeStep = in_array($this->activeStep, ['year', 'review'], true) ? $this->activeStep : 'year';
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
        $this->activeStep = 'year';
    }

    public function selectReviewStep(): void
    {
        $this->activeStep = 'review';
    }

    public function goToNextStep(): void
    {
        if ($this->activeStep === 'review') {
            return;
        }

        $current = $this->currentActiveYear();

        if ($current < $this->scaffoldYears) {
            $this->activeYear = $current + 1;
            $this->activeStep = 'year';

            return;
        }

        $this->activeStep = 'review';
    }

    public function goToPreviousStep(): void
    {
        if ($this->activeStep === 'review') {
            $this->activeStep = 'year';
            $this->activeYear = $this->scaffoldYears;

            return;
        }

        $current = $this->currentActiveYear();

        if ($current > 1) {
            $this->activeYear = $current - 1;
            $this->activeStep = 'year';
        }
    }

    public function saveDraftProgress(): void
    {
        $curriculum = $this->curriculumVersion();
        Gate::forUser($this->actor())->authorize('update', $curriculum);
        $curriculum->touch();

        Notification::make()
            ->title('Draft saved')
            ->body('Your curriculum draft and all entered subjects are safely saved.')
            ->success()
            ->send();
    }

    public function openExitModal(): void
    {
        $this->confirmingExitDiscard = false;
        $this->dispatch('open-modal', id: 'curriculum-exit-modal');
    }

    public function closeExitModal(): void
    {
        $this->confirmingExitDiscard = false;
        $this->dispatch('close-modal', id: 'curriculum-exit-modal');
    }

    public function promptDiscardEntireDraft(): void
    {
        $this->confirmingExitDiscard = true;
    }

    public function cancelDiscardEntireDraft(): void
    {
        $this->confirmingExitDiscard = false;
    }

    public function saveAndExitDraft(): void
    {
        $curriculum = $this->curriculumVersion();
        Gate::forUser($this->actor())->authorize('update', $curriculum);
        $curriculum->touch();

        Notification::make()
            ->title('Draft saved')
            ->success()
            ->send();

        $this->redirect($this->programCurriculaReturnUrl($curriculum->program_id));
    }

    public function confirmDiscardEntireDraft(): void
    {
        $curriculum = $this->curriculumVersion();
        Gate::forUser($this->actor())->authorize('update', $curriculum);
        $programId = $curriculum->program_id;

        try {
            app(CurriculumVersionLifecycleService::class)->discardDraft($curriculum, $this->actor());
        } catch (ValidationException $exception) {
            $this->confirmingExitDiscard = false;
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

        $this->redirect($this->programCurriculaReturnUrl($programId));
    }

    public function updatedSearch(): void
    {
        $needle = mb_strtolower(trim($this->search));

        if ($needle === '') {
            return;
        }

        $this->activeStep = 'year';
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
        $this->activeStep = 'year';
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
        $this->activeStep = 'year';
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

        $entry = $curriculum->entries()->with('courseSpecification.course')->findOrFail((int) $id);
        $previousSequence = (int) $entry->sequence;
        $previousYear = max(1, $this->parseYearNumber($entry->year_level));
        $previousTermType = (string) $entry->term_type;
        $previousTermLabel = (string) $entry->term_label;
        $code = trim((string) ($entry->courseSpecification?->course?->code ?? 'Subject'));

        app(CurriculumWorkbenchService::class)->reorderEntrySequence($this->actor(), $entry, $position);

        if ($previousSequence !== ($position + 1)) {
            $this->recentlyMovedEntries[(int) $entry->id] = [
                'from_year' => $previousYear,
                'from_term_type' => $previousTermType,
                'from_term_label' => $previousTermLabel,
                'from_sequence' => $previousSequence,
                'code' => $code,
                'kind' => 'reordered',
            ];

            Notification::make()
                ->title("{$code} order updated")
                ->body('Use Undo move above if this was unintentional.')
                ->info()
                ->send();
        }

        $this->flushRenderCache();
        $this->getRecord()->refresh();
    }

    public function undoRecentMoves(): void
    {
        $curriculum = $this->curriculumVersion();
        Gate::forUser($this->actor())->authorize('update', $curriculum);
        abort_unless($curriculum->isEditableDraft(), 403);

        if ($this->recentlyMovedEntries === []) {
            return;
        }

        $service = app(CurriculumWorkbenchService::class);
        $restoredCount = 0;
        $targetYear = $this->currentActiveYear();

        DB::transaction(function () use ($curriculum, $service, &$restoredCount, &$targetYear): void {
            foreach ($this->recentlyMovedEntries as $entryId => $origin) {
                $entry = $curriculum->entries()->find($entryId);

                if (! $entry instanceof CurriculumEntry) {
                    continue;
                }

                if (($origin['kind'] ?? 'moved') === 'reordered') {
                    $service->reorderEntrySequence($this->actor(), $entry, max(0, ((int) ($origin['from_sequence'] ?? 1)) - 1));
                } else {
                    $targetYear = max(1, (int) ($origin['from_year'] ?? $targetYear));
                    $fromTermType = (string) ($origin['from_term_type'] ?? Term::TypeFirstSemester);
                    $service->updatePlacement($this->actor(), $entry, [
                        'year_level' => $targetYear,
                        'term_type' => $fromTermType,
                        'term_label' => (string) ($origin['from_term_label'] ?: (Term::typeOptions()[$fromTermType] ?? 'First Semester')),
                        'sequence' => max(1, (int) ($origin['from_sequence'] ?? 1)),
                        'requirement_group' => $entry->requirement_group,
                    ]);
                }

                $restoredCount++;
            }
        });

        $this->recentlyMovedEntries = [];
        $this->flushRenderCache();
        $this->getRecord()->refresh();
        $this->syncYearScaffold();

        if ($this->activeStep !== 'review') {
            $this->activeYear = $targetYear;
        }

        if ($restoredCount > 0) {
            Notification::make()
                ->title('Move undone')
                ->body('Restored '.$restoredCount.' '.Str::plural('subject', $restoredCount).' to previous placement.')
                ->success()
                ->send();
        }
    }

    public function dismissMovedMarkers(): void
    {
        $this->recentlyMovedEntries = [];
    }

    public function toggleEntrySelection(int $entryId): void
    {
        $curriculum = $this->curriculumVersion();
        Gate::forUser($this->actor())->authorize('update', $curriculum);
        abort_unless($curriculum->isEditableDraft(), 403);

        $current = $this->normalizeSelectedEntryIds();

        if (in_array($entryId, $current, true)) {
            $this->selectedEntryIds = array_values(array_filter(
                $current,
                fn (int $id): bool => $id !== $entryId,
            ));

            return;
        }

        if ($curriculum->entries()->whereKey($entryId)->exists()) {
            $current[] = $entryId;
            $this->selectedEntryIds = array_values(array_unique($current));
        }
    }

    public function toggleSemesterSelection(int $year, string $termType): void
    {
        $curriculum = $this->loadedCurriculum();
        Gate::forUser($this->actor())->authorize('update', $curriculum);
        abort_unless($curriculum->isEditableDraft(), 403);

        $needle = mb_strtolower(trim($this->search));
        $semesterEntryIds = $curriculum->entries
            ->filter(fn (CurriculumEntry $entry): bool => $this->parseYearNumber($entry->year_level) === $year
                && $entry->term_type === $termType
                && ($needle === '' || $this->entryMatchesSearch($entry, $needle)))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($semesterEntryIds === []) {
            return;
        }

        $current = $this->normalizeSelectedEntryIds($curriculum->entries);
        $allSelected = collect($semesterEntryIds)->every(fn (int $id): bool => in_array($id, $current, true));

        if ($allSelected) {
            $removeLookup = array_flip($semesterEntryIds);
            $this->selectedEntryIds = array_values(array_filter(
                $current,
                fn (int $id): bool => ! isset($removeLookup[$id]),
            ));

            return;
        }

        $this->selectedEntryIds = array_values(array_unique([
            ...$current,
            ...$semesterEntryIds,
        ]));
    }

    public function clearSelectedEntries(): void
    {
        $this->selectedEntryIds = [];
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
                'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'same_faculty_default' => false,
                'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
                'components' => [[
                    'component_type' => CourseComponent::TypeLecture,
                    'room_type_default' => CourseComponent::RoomTypeLectureRoom,
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
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
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
            $fields[] = CheckboxList::make('course_specification_ids')
                ->label('Subjects')
                ->options(fn (): array => $this->availableCatalogSubjects()['options'])
                ->descriptions(fn (): array => $this->availableCatalogSubjects()['descriptions'])
                ->in(fn (): array => array_keys($this->courseSpecificationOptions()))
                ->searchable()
                ->bulkToggleable()
                ->columns(['default' => 1, 'md' => 2])
                ->gridDirection(GridDirection::Row)
                ->searchPrompt('Search subjects by code or name')
                ->noSearchResultsMessage('No matching subjects found.')
                ->helperText(fn (): string => $this->availableCatalogSubjects()['options'] === []
                    ? 'All catalog subjects are already placed in this curriculum. Switch to New subjects to create another.'
                    : 'Subjects already in this curriculum are excluded. Select one or more subjects to add.')
                ->extraAttributes(['class' => 'tala-catalog-subject-picker'])
                ->required();
        }

        if ($this->subjectMode === 'new') {
            $fields[] = Repeater::make('new_subjects')
                ->label('New subjects')
                ->extraAttributes(['class' => 'tala-record-repeater'])
                ->schema($this->newSubjectRepeaterFields())
                ->defaultItems(1)
                ->minItems(1)
                ->reorderable(false)
                ->addActionLabel('Add another subject')
                ->itemLabel(fn (array $state): string => filled($state['code'] ?? null)
                    ? (string) $state['code'].' · '.(filled($state['title'] ?? null) ? (string) $state['title'] : 'New subject')
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
                ->live()
                ->required(),
            Select::make('term_type')
                ->label('Semester')
                ->placeholder('Choose semester')
                ->options(self::semesterFieldOptions())
                ->live()
                ->required(),
            Placeholder::make('placement_move_guard')
                ->label('Move preview & prerequisite check')
                ->visible(fn (Get $get): bool => $this->isSelectedEntryPlacementChanging(
                    $get('year_level'),
                    $get('term_type'),
                ))
                ->content(fn (Get $get): Htmlable => $this->renderSelectedEntryMoveGuardHtml(
                    $get('year_level'),
                    $get('term_type'),
                ))
                ->columnSpanFull(),
            Checkbox::make('confirm_placement_move')
                ->label('Confirm moving this subject to the selected year and semester')
                ->visible(fn (Get $get): bool => $this->isSelectedEntryPlacementChanging(
                    $get('year_level'),
                    $get('term_type'),
                ))
                ->accepted()
                ->dehydrated(false)
                ->columnSpanFull(),
            Select::make('requirement_group')
                ->label('Subject requirement')
                ->options(self::subjectRequirementOptions())
                ->helperText('Required subjects apply to every student following this study plan.')
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
        $entry = $this->curriculumVersion()
            ->entries()
            ->with(['courseSpecification.components', 'courseSpecification.requirements'])
            ->findOrFail($entryId);
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
            'confirm_placement_move' => false,
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
        $movedFromSnapshot = null;

        try {
            DB::transaction(function () use ($data, $service, $savedYear, $savedTermType, &$movedFromSnapshot): void {
                if ($this->selectedEntryId !== null) {
                    $entry = $this->curriculumVersion()
                        ->entries()
                        ->with(['courseSpecification.course', 'courseSpecification.components', 'courseSpecification.requirements'])
                        ->findOrFail($this->selectedEntryId);

                    $fromYear = max(1, $this->parseYearNumber($entry->year_level));
                    $fromTermType = (string) $entry->term_type;
                    if ($fromYear !== $savedYear || $fromTermType !== $savedTermType) {
                        $movedFromSnapshot = [
                            'entry_id' => (int) $entry->id,
                            'from_year' => $fromYear,
                            'from_term_type' => $fromTermType,
                            'from_term_label' => (string) $entry->term_label,
                            'from_sequence' => (int) $entry->sequence,
                            'code' => trim((string) ($entry->courseSpecification?->course?->code ?? 'Subject')),
                            'kind' => 'moved',
                        ];
                    }

                    if ($this->subjectMode === 'edit' && $this->specificationHasChanges($entry, $data)) {
                        $service->updateSpecification($this->actor(), $entry, $data);
                        $entry->refresh();
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

        if ($this->activeStep !== 'review') {
            $this->activeYear = $savedYear;
        }

        if ($savedTermType === Term::TypeSpecialTerm && ! in_array($savedYear, $this->enabledSummerYears, true)) {
            $this->enabledSummerYears[] = $savedYear;
            sort($this->enabledSummerYears);
        }

        $this->closeSubjectEditor();

        if ($movedFromSnapshot !== null) {
            $entryId = (int) $movedFromSnapshot['entry_id'];
            unset($movedFromSnapshot['entry_id']);
            $this->recentlyMovedEntries[$entryId] = $movedFromSnapshot;
            $semLabel = self::semesterFieldOptions()[$savedTermType] ?? $savedTermType;
            Notification::make()
                ->title("Moved {$movedFromSnapshot['code']} to Year {$savedYear} · {$semLabel}")
                ->body('Highlighted on the destination semester. Use Undo move if needed.')
                ->success()
                ->send();

            return;
        }

        Notification::make()->title('Curriculum saved')->success()->send();
    }

    public function getTitle(): string
    {
        return $this->loadedCurriculum()->batchLabel();
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
                <span class="tala-curriculum-heading__title">{{ $title }}</span>
                <x-filament::badge :color="$badgeColor" size="sm">{{ $stateLabel }}</x-filament::badge>
            </span>
        BLADE, [
            'title' => $curriculum->batchLabel(),
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
        $programDisplay = collect([
            $curriculum->program?->code,
            $curriculum->program?->name,
        ])->filter()->implode(' · ');
        $batchLabel = $curriculum->batchLabel();
        $customName = filled($curriculum->name) && $curriculum->name !== $batchLabel
            ? (string) $curriculum->name
            : null;
        $intake = $curriculum->batches->isEmpty() && $curriculum->effectiveEntryTerm instanceof Term
            ? 'First intake: '.collect([$curriculum->effectiveEntryTerm->label, $curriculum->effectiveEntryTerm->academicYear?->label])->filter()->implode(', ')
            : null;

        return collect([
            $programDisplay !== '' ? $programDisplay : null,
            $customName,
            $years ? $years.' '.Str::plural('year', $years) : null,
            $subjects.' '.Str::plural('subject', $subjects),
            $units.' units',
            $intake,
        ])->filter()->implode(' · ');
    }

    public function getBreadcrumbs(): array
    {
        $curriculum = $this->loadedCurriculum();
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

        $breadcrumbs[] = $curriculum->batchLabel();

        return $breadcrumbs;
    }

    /**
     * @return list<Action|ActionGroup>
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
            ActionGroup::make([
                $this->importSpreadsheetAction(),
                $this->exportXlsxAction(),
                $this->exportCsvAction(),
                $this->downloadXlsxTemplateAction(),
                $this->downloadCsvTemplateAction(),
            ])
                ->label(fn (): string => $this->isEditableDraft() && $this->currentUserCan('update') ? 'Import / Export' : 'Export')
                ->icon(fn (): Heroicon => $this->isEditableDraft() && $this->currentUserCan('update') ? Heroicon::OutlinedArrowsUpDown : Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->button()
                ->visible(fn (): bool => $this->currentUserCan('view')),
            ActionGroup::make([
                $this->planSettingsAction(),
                $this->discardDraftAction(),
            ])
                ->label('Manage draft')
                ->icon(Heroicon::OutlinedEllipsisHorizontal)
                ->color('gray')
                ->button()
                ->visible(fn (): bool => $this->isEditableDraft() && $this->currentUserCan('update')),
            ActionGroup::make([
                $this->copyToDraftAction(),
                $this->retireCurriculumAction(),
            ])
                ->label('Manage curriculum')
                ->icon(Heroicon::OutlinedEllipsisHorizontal)
                ->color('gray')
                ->button()
                ->visible(fn (): bool => ! $this->isEditableDraft() && $this->currentUserCan('copy')),
        ];
    }

    public function importSpreadsheetAction(): Action
    {
        return CurriculumSpreadsheetWizard::makeCurriculumImportAction(
            curriculumResolver: fn (): CurriculumVersion => $this->curriculumVersion(),
            onSkippedCsvGenerated: function (string $path): void {
                $this->lastSkippedImportCsvPath = $path;
            },
            onCompleted: function (): void {
                $this->flushRenderCache();
                $this->getRecord()->refresh();
                $this->syncYearScaffold();
                $this->resolveInitialActiveYear();
                $this->cacheSchema('subjectForm', null);
            },
        )->url(function (): ?string {
            try {
                $curriculum = $this->curriculumVersion();

                return CurriculumVersionResource::getUrl('import', array_filter([
                    'record' => $curriculum,
                    'programId' => $this->programId,
                    'oversightTermId' => $this->oversightTermId,
                ], fn (mixed $value): bool => $value !== null));
            } catch (\Throwable) {
                return null;
            }
        });
    }

    public function exportXlsxAction(): Action
    {
        return Action::make('exportXlsx')
            ->label('Export as Excel (.xlsx)')
            ->icon(Heroicon::OutlinedTableCells)
            ->authorize(fn (): bool => $this->currentUserCan('view'))
            ->visible(fn (): bool => $this->currentUserCan('view'))
            ->action(fn (): BinaryFileResponse => $this->exportCurriculumSpreadsheet('xlsx'));
    }

    public function exportCsvAction(): Action
    {
        return Action::make('exportCsv')
            ->label('Export as CSV (.csv)')
            ->icon(Heroicon::OutlinedDocumentText)
            ->authorize(fn (): bool => $this->currentUserCan('view'))
            ->visible(fn (): bool => $this->currentUserCan('view'))
            ->action(fn (): BinaryFileResponse => $this->exportCurriculumSpreadsheet('csv'));
    }

    public function exportCurriculumXlsxAction(): Action
    {
        return Action::make('exportCurriculumXlsx')
            ->label('Export as Excel (.xlsx)')
            ->authorize(fn (): bool => $this->currentUserCan('view'))
            ->action(fn (): BinaryFileResponse => $this->exportCurriculumSpreadsheet('xlsx'));
    }

    public function exportCurriculumCsvAction(): Action
    {
        return Action::make('exportCurriculumCsv')
            ->label('Export as CSV (.csv)')
            ->authorize(fn (): bool => $this->currentUserCan('view'))
            ->action(fn (): BinaryFileResponse => $this->exportCurriculumSpreadsheet('csv'));
    }

    public function downloadXlsxTemplateAction(): Action
    {
        return CurriculumSpreadsheetWizard::makeTemplateDownloadAction(
            name: 'downloadXlsxTemplate',
            scope: 'curriculum',
            format: 'xlsx',
            label: 'Download template (.xlsx)',
        )->visible(fn (): bool => $this->isEditableDraft() && $this->currentUserCan('update'));
    }

    public function downloadCsvTemplateAction(): Action
    {
        return CurriculumSpreadsheetWizard::makeTemplateDownloadAction(
            name: 'downloadCsvTemplate',
            scope: 'curriculum',
            format: 'csv',
            label: 'Download template (.csv)',
        )->visible(fn (): bool => $this->isEditableDraft() && $this->currentUserCan('update'));
    }

    public function downloadTemplateAction(): Action
    {
        return Action::make('downloadTemplate')
            ->label('Download template')
            ->color('gray')
            ->action(fn (array $arguments): BinaryFileResponse => $this->downloadTemplate((string) ($arguments['format'] ?? 'xlsx')));
    }

    public function downloadTemplate(string $format = 'xlsx'): BinaryFileResponse
    {
        $exportService = app(CurriculumSpreadsheetExportService::class);
        $path = $exportService->exportTemplate('curriculum', $format);

        return $exportService->downloadAndDelete($path, basename($path), $format);
    }

    public function downloadSkippedRowsCsvAction(): Action
    {
        return Action::make('downloadSkippedRowsCsv')
            ->label('Download skipped rows (.csv)')
            ->color('gray')
            ->visible(fn (): bool => (is_string($this->lastSkippedImportCsvPath) && is_file($this->lastSkippedImportCsvPath))
                || (is_string(session('tala.skipped_import_csv_path')) && is_file((string) session('tala.skipped_import_csv_path'))))
            ->action(fn (): ?BinaryFileResponse => $this->downloadSkippedRowsCsv());
    }

    #[On('download-skipped-import-rows')]
    public function downloadSkippedRowsCsv(): ?BinaryFileResponse
    {
        $path = $this->lastSkippedImportCsvPath ?: session()->pull('tala.skipped_import_csv_path');

        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        $this->lastSkippedImportCsvPath = null;

        return app(CurriculumSpreadsheetExportService::class)->downloadAndDelete($path, basename($path), 'csv');
    }

    public function exportCurriculumSpreadsheet(string $format = 'xlsx'): BinaryFileResponse
    {
        $curriculum = $this->curriculumVersion();
        Gate::forUser($this->actor())->authorize('view', $curriculum);

        $exportService = app(CurriculumSpreadsheetExportService::class);
        $path = $exportService->exportCurriculum($curriculum, $format);

        return $exportService->downloadAndDelete($path, basename($path), $format);
    }

    public function addBatchAction(): Action
    {
        return Action::make('addBatch')
            ->label('Add batch')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading(fn (): string => $this->loadedCurriculum()->batches->isEmpty()
                ? 'Assign starting batch to '.$this->loadedCurriculum()->batchLabel()
                : 'Add batch to '.$this->loadedCurriculum()->batchLabel())
            ->modalDescription('Assign an Academic Year batch to this curriculum without duplicating its subjects.')
            ->modalSubmitActionLabel(fn (): string => $this->loadedCurriculum()->program?->is_active === false
                ? 'Activate and proceed'
                : ($this->loadedCurriculum()->batches->isEmpty() ? 'Assign batch' : 'Add batch'))
            ->fillForm(fn (): array => [
                'academic_year_id' => CurriculumVersionForm::suggestedBatchId($this->curriculumVersion()->program_id),
                'activate_program_if_inactive' => false,
            ])
            ->schema(fn (): array => CurriculumVersionForm::addBatchFields($this->loadedCurriculum()))
            ->visible(fn (): bool => in_array($this->loadedCurriculum()->state, [CurriculumVersion::StateDraft, CurriculumVersion::StateActive], true)
                && $this->currentUserCan('copy'))
            ->action(function (array $data): void {
                $curriculum = $this->curriculumVersion();
                $academicYear = AcademicYear::query()->findOrFail((int) $data['academic_year_id']);

                try {
                    app(CurriculumVersionLifecycleService::class)->addBatch(
                        actor: $this->actor(),
                        curriculum: $curriculum,
                        academicYear: $academicYear,
                        activateProgramIfInactive: (bool) ($data['activate_program_if_inactive'] ?? false),
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Could not assign batch')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                    $this->halt();

                    return;
                }

                $this->flushRenderCache();
                $this->getRecord()->refresh();
                $short = CurriculumVersion::formatBatchShort($academicYear);

                Notification::make()
                    ->title("Batch {$short} added")
                    ->body("Now covering {$this->loadedCurriculum()->batchLabel()}.")
                    ->success()
                    ->send();
            });
    }

    public function removeBatchAction(): Action
    {
        return Action::make('removeBatch')
            ->label('Remove')
            ->color('danger')
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading('Remove batch assignment?')
            ->modalDescription('This batch has no students, student sections, or active applicants yet. Removing it makes the Academic Year available for another curriculum.')
            ->modalSubmitActionLabel('Remove batch')
            ->visible(fn (): bool => $this->currentUserCan('copy'))
            ->action(function (array $arguments): void {
                $curriculum = $this->curriculumVersion();
                $academicYear = AcademicYear::query()->findOrFail((int) ($arguments['academicYearId'] ?? 0));

                try {
                    app(CurriculumVersionLifecycleService::class)->removeBatch($this->actor(), $curriculum, $academicYear);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Batch cannot be removed')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                    $this->halt();

                    return;
                }

                $this->flushRenderCache();
                $this->getRecord()->refresh();
                $short = CurriculumVersion::formatBatchShort($academicYear);

                Notification::make()
                    ->title("Batch {$short} removed")
                    ->success()
                    ->send();
            });
    }

    public function retireCurriculumAction(): Action
    {
        return Action::make('retireCurriculum')
            ->label('Retire curriculum')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading(fn (): string => 'Retire '.$this->loadedCurriculum()->batchLabel().'?')
            ->modalDescription('No new batches can be assigned after retiring. Students already assigned to this curriculum continue uninterrupted.')
            ->modalSubmitActionLabel('Retire curriculum')
            ->visible(fn (): bool => $this->loadedCurriculum()->state === CurriculumVersion::StateActive
                && $this->currentUserCan('copy'))
            ->action(function (): void {
                try {
                    $retired = app(CurriculumVersionLifecycleService::class)->retire($this->actor(), $this->curriculumVersion());
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Could not retire curriculum')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                    $this->halt();

                    return;
                }

                $this->flushRenderCache();
                $this->record = $retired;

                Notification::make()
                    ->title('Curriculum retired')
                    ->body('Continuing students stay on this plan, and no new batches can be assigned to it.')
                    ->success()
                    ->send();
            });
    }

    public function publishCurriculumAction(): Action
    {
        return Action::make('publishCurriculum')
            ->label('Publish curriculum')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('primary')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->disabled(fn (): bool => $this->reviewReadinessBlockers() !== [])
            ->modalHeading(fn (): string => 'Publish '.$this->loadedCurriculum()->batchLabel().'?')
            ->modalDescription(function (): string {
                $curriculum = $this->loadedCurriculum();
                $program = $curriculum->program;
                $base = $curriculum->batches->isNotEmpty()
                    ? "Subjects can't be edited after publishing. Once published, this curriculum becomes Active and its assigned batches become eligible in Admissions."
                    : "Subjects can't be edited after publishing. Publishing with a starting batch makes this curriculum eligible in Admissions right away. Publishing without a batch activates the curriculum, but it will not appear in Admissions until a batch is assigned.";

                if ($program instanceof Program && ! $program->is_active) {
                    return "{$program->code} is currently inactive. Activate the program to proceed. {$base}";
                }

                return $base;
            })
            ->fillForm(function (): array {
                $curriculum = $this->loadedCurriculum();

                return [
                    'starting_academic_year_id' => $curriculum->batches->isEmpty()
                        ? CurriculumVersionForm::suggestedBatchId($curriculum->program_id)
                        : null,
                    'publish_without_batch' => false,
                    'activate_program_if_inactive' => false,
                ];
            })
            ->schema(function (): array {
                $curriculum = $this->loadedCurriculum();
                $program = $curriculum->program;
                $fields = [];

                if ($curriculum->batches->isEmpty()) {
                    $fields[] = CurriculumVersionForm::applyInlineAcademicYearCreation(
                        Select::make('starting_academic_year_id')
                            ->label('Starting batch (Academic Year)')
                            ->options(fn (): array => CurriculumVersionForm::availableBatchOptions($curriculum->program_id))
                            ->placeholder('Select starting Academic Year batch')
                            ->helperText('Choose the first incoming batch for this curriculum, or use + to add an academic year.')
                            ->native(true)
                            ->nullable()
                            ->live()
                            ->disabled(fn (Get $get): bool => (bool) $get('publish_without_batch'))
                            ->required(fn (Get $get): bool => ! (bool) $get('publish_without_batch'))
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                if (filled($state)) {
                                    $set('publish_without_batch', false);
                                }
                            }),
                    );

                    $fields[] = Checkbox::make('publish_without_batch')
                        ->label('Publish without a batch for now (will not appear in Admissions until a batch is assigned)')
                        ->default(false)
                        ->live()
                        ->afterStateUpdated(function (bool $state, Set $set): void {
                            if ($state) {
                                $set('starting_academic_year_id', null);
                            }
                        });
                }

                if ($program instanceof Program && ! $program->is_active) {
                    $fields[] = Checkbox::make('activate_program_if_inactive')
                        ->label('Activate program and proceed')
                        ->helperText("Marks {$program->code} · {$program->name} Active before publishing this curriculum.")
                        ->default(false)
                        ->accepted();
                }

                return $fields;
            })
            ->modalSubmitActionLabel(fn (): string => $this->loadedCurriculum()->program?->is_active === false
                ? 'Activate and proceed'
                : 'Publish curriculum')
            ->visible(fn (): bool => $this->isEditableDraft() && $this->currentUserCan('activate'))
            ->action(function (array $data): void {
                $curriculum = $this->loadedCurriculum();
                $blockers = $this->reviewReadinessBlockers();

                if ($blockers !== []) {
                    Notification::make()
                        ->title('Curriculum cannot be published yet')
                        ->body(implode(' ', $blockers))
                        ->danger()
                        ->send();
                    $this->halt();

                    return;
                }

                $needsBatchDecision = $curriculum->batches->isEmpty();
                $publishWithoutBatch = (bool) ($data['publish_without_batch'] ?? false);
                $selectedAcademicYearId = (! $publishWithoutBatch && filled($data['starting_academic_year_id'] ?? null))
                    ? (int) $data['starting_academic_year_id']
                    : null;

                if ($needsBatchDecision && ! $publishWithoutBatch && $selectedAcademicYearId === null) {
                    Notification::make()
                        ->title('Starting batch decision required')
                        ->body('Select a starting batch (Academic Year) or check Publish without a batch for now.')
                        ->danger()
                        ->send();
                    $this->halt();

                    return;
                }

                $program = $curriculum->program;

                if ($program instanceof Program && ! $program->is_active && ! (bool) ($data['activate_program_if_inactive'] ?? false)) {
                    Notification::make()
                        ->title('Program activation required')
                        ->body("{$program->code} is inactive. Confirm Activate program and proceed to publish.")
                        ->danger()
                        ->send();
                    $this->halt();

                    return;
                }

                $lifecycle = app(CurriculumVersionLifecycleService::class);
                $actor = $this->actor();

                try {
                    $activated = DB::transaction(function () use (
                        $actor,
                        $curriculum,
                        $program,
                        $selectedAcademicYearId,
                        $lifecycle,
                    ): CurriculumVersion {
                        if ($program instanceof Program && ! $program->is_active) {
                            Gate::forUser($actor)->authorize('update', $program);
                            $program->update(['is_active' => true]);

                            activity()
                                ->performedOn($program)
                                ->causedBy($actor)
                                ->event('program_activated_for_curriculum_publish')
                                ->withProperties(['curriculum_version_id' => $curriculum->id])
                                ->log('Program activated during curriculum publish');
                        }

                        if ($selectedAcademicYearId !== null) {
                            $academicYear = AcademicYear::query()->find($selectedAcademicYearId);

                            if (! $academicYear instanceof AcademicYear) {
                                throw ValidationException::withMessages([
                                    'starting_academic_year_id' => 'Choose an existing Academic Year for the starting batch.',
                                ]);
                            }

                            $lifecycle->addBatch(
                                actor: $actor,
                                curriculum: $curriculum,
                                academicYear: $academicYear,
                                activateProgramIfInactive: false,
                            );
                        }

                        return $lifecycle->activate(
                            actor: $actor,
                            curriculumVersion: $curriculum,
                        );
                    });
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Curriculum not published')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                    $this->halt();

                    return;
                }

                $this->flushRenderCache();
                $this->record = $activated;
                $this->activeStep = 'year';
                $publishedWithBatch = ! $needsBatchDecision || $selectedAcademicYearId !== null;

                Notification::make()
                    ->title('Curriculum published')
                    ->body($publishedWithBatch
                        ? 'This curriculum is now Active and eligible in Admissions for its assigned batches.'
                        : 'This curriculum is now Active, but it will not appear in Admissions until a batch is assigned.')
                    ->success()
                    ->send();
            });
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
                $removedId = $this->selectedEntryId;
                app(CurriculumWorkbenchService::class)->removeEntry($this->actor(), $this->selectedSubject());
                if ($removedId !== null) {
                    $this->selectedEntryIds = array_values(array_filter(
                        $this->selectedEntryIds,
                        fn (int $id): bool => $id !== (int) $removedId,
                    ));
                }
                $this->flushRenderCache();
                $this->getRecord()->refresh();
                $this->closeSubjectEditor();
                Notification::make()->title('Subject removed from curriculum')->success()->send();
            });
    }

    public function bulkMoveSelectedEntriesAction(): Action
    {
        return Action::make('bulkMoveSelectedEntries')
            ->label('Move to year / semester')
            ->button()
            ->size('sm')
            ->color('gray')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading(function (): string {
                $summary = $this->selectedEntriesSummary();
                $count = $summary['count'];

                return 'Confirm move of '.$count.' '.Str::plural('subject', $count);
            })
            ->modalDescription(function (): string {
                $summary = $this->selectedEntriesSummary();
                $count = $summary['count'];
                $scope = $summary['years_label'] !== '' ? " ({$summary['years_label']})" : '';

                return 'Review the destination semester unit load and prerequisite checks before moving the '.$count.' selected '.Str::plural('subject', $count).$scope.'.';
            })
            ->modalSubmitActionLabel('Confirm & move subjects')
            ->fillForm(fn (): array => [
                'year_level' => $this->currentActiveYear(),
                'term_type' => Term::TypeFirstSemester,
                'confirm_move' => false,
            ])
            ->schema(function (): array {
                $maxYear = $this->yearLevelLimit();
                $yearChoices = array_combine(
                    range(1, $maxYear),
                    array_map(fn (int $year): string => "Year {$year}", range(1, $maxYear)),
                );

                return [
                    Grid::make(2)->schema([
                        Select::make('year_level')
                            ->label('Year level')
                            ->placeholder('Choose year')
                            ->options($yearChoices)
                            ->live()
                            ->required(),
                        Select::make('term_type')
                            ->label('Semester')
                            ->placeholder('Choose semester')
                            ->options(self::semesterFieldOptions())
                            ->live()
                            ->required(),
                    ]),
                    Placeholder::make('move_impact_preview')
                        ->label('Move impact & prerequisite check')
                        ->content(fn (Get $get): Htmlable => $this->renderBulkMoveGuardHtml(
                            (int) ($get('year_level') ?? $this->currentActiveYear()),
                            (string) ($get('term_type') ?? Term::TypeFirstSemester),
                        )),
                    Checkbox::make('confirm_move')
                        ->label('Confirm moving these subjects to the destination semester')
                        ->visible(fn (Get $get): bool => $this->bulkMoveImpact(
                            (int) ($get('year_level') ?? $this->currentActiveYear()),
                            (string) ($get('term_type') ?? Term::TypeFirstSemester),
                        )['has_changes'])
                        ->accepted()
                        ->dehydrated(false),
                ];
            })
            ->authorize(fn (): bool => $this->isEditableDraft() && $this->currentUserCan('update'))
            ->visible(fn (): bool => $this->isEditableDraft() && $this->currentUserCan('update') && $this->normalizeSelectedEntryIds() !== [])
            ->action(function (array $data): void {
                $curriculum = $this->curriculumVersion();
                Gate::forUser($this->actor())->authorize('update', $curriculum);
                abort_unless($curriculum->isEditableDraft(), 403);

                $selectedIds = $this->normalizeSelectedEntryIds();

                if ($selectedIds === []) {
                    return;
                }

                $targetYear = max(1, (int) ($data['year_level'] ?? $this->currentActiveYear()));
                $targetTermType = (string) ($data['term_type'] ?? Term::TypeFirstSemester);
                $targetTermLabel = Term::typeOptions()[$targetTermType] ?? 'First Semester';
                $service = app(CurriculumWorkbenchService::class);
                $movedCount = 0;
                $movedSnapshots = [];

                try {
                    DB::transaction(function () use ($curriculum, $selectedIds, $targetYear, $targetTermType, $targetTermLabel, $service, &$movedCount, &$movedSnapshots): void {
                        $entries = $curriculum->entries()
                            ->with('courseSpecification.course')
                            ->whereIn('id', $selectedIds)
                            ->orderBy('year_level')
                            ->orderBy('sequence')
                            ->orderBy('id')
                            ->get();

                        $nextSequence = 1 + (int) $curriculum->entries()
                            ->where('year_level', (string) $targetYear)
                            ->where('term_type', $targetTermType)
                            ->whereNotIn('id', $entries->pluck('id')->all())
                            ->max('sequence');

                        foreach ($entries as $entry) {
                            $fromYear = max(1, $this->parseYearNumber($entry->year_level));
                            $fromTermType = (string) $entry->term_type;
                            if ($fromYear !== $targetYear || $fromTermType !== $targetTermType) {
                                $movedSnapshots[(int) $entry->id] = [
                                    'from_year' => $fromYear,
                                    'from_term_type' => $fromTermType,
                                    'from_term_label' => (string) $entry->term_label,
                                    'from_sequence' => (int) $entry->sequence,
                                    'code' => trim((string) ($entry->courseSpecification?->course?->code ?? 'Subject')),
                                    'kind' => 'moved',
                                ];
                            }

                            $service->updatePlacement($this->actor(), $entry, [
                                'year_level' => $targetYear,
                                'term_type' => $targetTermType,
                                'term_label' => $targetTermLabel,
                                'sequence' => $nextSequence++,
                                'requirement_group' => $entry->requirement_group,
                            ]);
                            $movedCount++;
                        }
                    });
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Could not move selected subjects')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                    $this->halt();

                    return;
                }

                $this->selectedEntryIds = [];
                foreach ($movedSnapshots as $movedEntryId => $snapshot) {
                    $this->recentlyMovedEntries[$movedEntryId] = $snapshot;
                }
                $this->flushRenderCache();
                $this->getRecord()->refresh();
                $this->syncYearScaffold();

                if ($targetTermType === Term::TypeSpecialTerm && ! in_array($targetYear, $this->enabledSummerYears, true)) {
                    $this->enabledSummerYears[] = $targetYear;
                    sort($this->enabledSummerYears);
                }

                if ($this->activeStep !== 'review') {
                    $this->activeYear = $targetYear;
                }

                $semesterDisplay = self::semesterFieldOptions()[$targetTermType] ?? $targetTermLabel;

                Notification::make()
                    ->title('Moved '.$movedCount.' '.Str::plural('subject', $movedCount)." to Year {$targetYear} · {$semesterDisplay}")
                    ->body('Moved rows are highlighted on the destination semester. Use Undo move if needed.')
                    ->success()
                    ->send();
            });
    }

    public function bulkRemoveSelectedEntriesAction(): Action
    {
        return Action::make('bulkRemoveSelectedEntries')
            ->label('Remove selected')
            ->button()
            ->size('sm')
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading(function (): string {
                $summary = $this->selectedEntriesSummary();
                $count = $summary['count'];

                return 'Remove '.$count.' '.Str::plural('subject', $count).' from the study plan?';
            })
            ->modalDescription(function (): string {
                $summary = $this->selectedEntriesSummary();
                $count = $summary['count'];
                $scope = $summary['scope_label'] !== '' ? " across {$summary['scope_label']}" : '';

                return 'Removes '.$count.' selected '.Str::plural('subject', $count).$scope.' from this draft curriculum. The subjects stay in the shared catalog.';
            })
            ->modalSubmitActionLabel('Remove selected subjects')
            ->authorize(fn (): bool => $this->isEditableDraft() && $this->currentUserCan('update'))
            ->visible(fn (): bool => $this->isEditableDraft() && $this->currentUserCan('update') && $this->normalizeSelectedEntryIds() !== [])
            ->action(function (): void {
                $curriculum = $this->curriculumVersion();
                Gate::forUser($this->actor())->authorize('update', $curriculum);
                abort_unless($curriculum->isEditableDraft(), 403);

                $selectedIds = $this->normalizeSelectedEntryIds();

                if ($selectedIds === []) {
                    return;
                }

                $service = app(CurriculumWorkbenchService::class);
                $removedCount = 0;

                DB::transaction(function () use ($curriculum, $selectedIds, $service, &$removedCount): void {
                    $entries = $curriculum->entries()
                        ->whereIn('id', $selectedIds)
                        ->get();

                    foreach ($entries as $entry) {
                        $service->removeEntry($this->actor(), $entry);
                        $removedCount++;
                    }
                });

                $this->selectedEntryIds = [];

                if ($this->selectedEntryId !== null && in_array($this->selectedEntryId, $selectedIds, true)) {
                    $this->closeSubjectEditor();
                }

                $this->flushRenderCache();
                $this->getRecord()->refresh();
                $this->syncYearScaffold();

                Notification::make()
                    ->title($removedCount.' '.Str::plural('subject', $removedCount).' removed from curriculum')
                    ->success()
                    ->send();
            });
    }

    public function copySelectedSubjectAction(): Action
    {
        return Action::make('copySelectedSubject')
            ->label('Edit subject & schedule')
            ->button()
            ->size('sm')
            ->color('primary')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (): bool => $this->selectedEntryId !== null && $this->currentUserCan('update') && $this->subjectMode === 'placement')
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
                    ->placeholder('Choose a replacement subject')
                    ->searchPrompt('Search subjects by code or name')
                    ->noSearchResultsMessage('No matching subjects found.')
                    ->options(fn (): array => $this->replacementCourseSpecificationOptions())
                    ->searchable()
                    ->preload()
                    ->extraAttributes(['class' => 'tala-replace-subject-select'])
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

                        $replacementSpec = CourseSpecification::query()->with('course')->findOrFail($replacementSpecId);

                        $duplicateEntry = CurriculumEntry::query()
                            ->where('curriculum_version_id', $lockedEntry->curriculum_version_id)
                            ->when(
                                $replacementSpec->course_id !== null,
                                fn ($query) => $query->whereHas('courseSpecification', fn ($specQuery) => $specQuery->where('course_id', $replacementSpec->course_id)),
                                fn ($query) => $query->where('course_specification_id', $replacementSpec->id),
                            )
                            ->whereKeyNot($lockedEntry->id)
                            ->with('courseSpecification.course')
                            ->first();

                        if ($duplicateEntry instanceof CurriculumEntry) {
                            $code = trim((string) ($replacementSpec->course?->code ?? $duplicateEntry->courseSpecification?->course?->code ?? $replacementSpec->title));
                            $existingYear = preg_match('/\d+/', (string) $duplicateEntry->year_level, $matches) === 1
                                ? 'Year '.$matches[0]
                                : trim((string) $duplicateEntry->year_level);

                            throw ValidationException::withMessages([
                                'course_specification_id' => "This subject ({$code}) is already placed in {$existingYear} · {$duplicateEntry->term_label}. Each subject is listed once per curriculum prospectus; retakes and mixed-year enrollments are handled in Term Planning and Enrollment.",
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
            ->label('Add external TESDA certification')
            ->modalHeading('Add external qualification')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('For a qualification assessed outside the school, such as a TESDA certificate. Students’ results are recorded later in Students Record.')
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
            ->modalHeading('Discard entire draft curriculum?')
            ->modalDescription('This action permanently deletes the draft and all entered subjects. Active curricula and student records will not be affected.')
            ->modalSubmitActionLabel('Discard entire draft')
            ->authorize(fn (): bool => $this->currentUserCan('update')
                && app(CurriculumVersionLifecycleService::class)->canDiscardDraft($this->curriculumVersion()))
            ->visible(fn (): bool => $this->currentUserCan('update')
                && app(CurriculumVersionLifecycleService::class)->canDiscardDraft($this->curriculumVersion()))
            ->action(function (): void {
                $curriculum = $this->curriculumVersion();
                $programId = $curriculum->program_id;

                try {
                    app(CurriculumVersionLifecycleService::class)->discardDraft($curriculum, $this->actor());
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

                $this->redirect($this->programCurriculaReturnUrl($programId));
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
            ->label('Copy to new curriculum')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->modalHeading('Copy to new curriculum')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Start with the subjects in this plan. Changes are saved in a new draft; this curriculum and its student assignments stay unchanged.')
            ->modalSubmitActionLabel(fn (): string => $this->loadedCurriculum()->program?->is_active === false
                ? 'Activate and proceed'
                : 'Copy to draft')
            ->fillForm(fn (): array => [
                'starting_academic_year_id' => CurriculumVersionForm::suggestedBatchId($this->curriculumVersion()->program_id),
                'activate_program_if_inactive' => false,
                'name' => $this->curriculumVersion()->name.' (Draft)',
                'effective_entry_term_id' => $this->curriculumVersion()->effective_entry_term_id,
            ])
            ->schema(fn (): array => CurriculumVersionForm::copyFields($this->loadedCurriculum()))
            ->visible(fn (): bool => ! $this->isEditableDraft() && $this->currentUserCan('copy'))
            ->action(function (array $data): void {
                $startingBatch = filled($data['starting_academic_year_id'] ?? null)
                    ? AcademicYear::query()->find((int) $data['starting_academic_year_id'])
                    : null;

                try {
                    $draft = app(CurriculumVersionLifecycleService::class)->copyToDraft(
                        actor: $this->actor(),
                        source: $this->curriculumVersion(),
                        versionCode: CurriculumVersionForm::draftCodeFor($this->curriculumVersion()->program_id),
                        name: filled($data['name'] ?? null) ? trim((string) $data['name']) : null,
                        effectiveEntryTermId: filled($data['effective_entry_term_id'] ?? null) ? (int) $data['effective_entry_term_id'] : null,
                        startingBatch: $startingBatch,
                        activateProgramIfInactive: (bool) ($data['activate_program_if_inactive'] ?? false),
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Could not copy curriculum')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                    $this->halt();

                    return;
                }

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
        $activeStep = $this->activeStep === 'review' ? 'review' : 'year';
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

        $selectedEntryIds = $this->normalizeSelectedEntryIds($allEntries);
        $selectedEntriesSummary = $this->selectedEntriesSummary($allEntries, $selectedEntryIds);

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
                'short_ordinal' => $this->shortOrdinalYearLabel($year),
                'ordinal_label' => $this->ordinalYearLabel($year),
                'total_count' => $yearEntries->count(),
                'match_count' => $matchCount,
                'incomplete_count' => $incompleteCount,
                'units' => $this->formatUnits($yearEntries->sum(fn (CurriculumEntry $entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0))),
            ];
        }

        $reviewData = $this->buildReviewData($curriculum, $allEntries, $entriesByYear);
        $entryMoves = is_array($reviewData['diff']['entry_moves'] ?? null) ? $reviewData['diff']['entry_moves'] : [];

        /** @var Collection<int, CurriculumEntry> $activeYearEntries */
        $activeYearEntries = $entriesByYear->get($activeYear, collect());
        $activeYearTotalCount = $activeYearEntries->count();
        $activeYearUnits = $this->formatUnits($activeYearEntries->sum(fn (CurriculumEntry $entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0)));

        $regularSemesters = [
            $this->buildSemesterPanel($activeYear, Term::TypeFirstSemester, 'First Semester', $activeYearEntries, $needle, $entryMoves),
            $this->buildSemesterPanel($activeYear, Term::TypeSecondSemester, 'Second Semester', $activeYearEntries, $needle, $entryMoves),
        ];

        $hasSummerEntries = $activeYearEntries->contains(fn (CurriculumEntry $entry): bool => $entry->term_type === Term::TypeSpecialTerm);
        $summerVisible = $hasSummerEntries || in_array($activeYear, $this->enabledSummerYears, true);
        $summerPanel = $summerVisible
            ? $this->buildSemesterPanel($activeYear, Term::TypeSpecialTerm, 'Special Term (Summer)', $activeYearEntries, $needle, $entryMoves)
            : null;

        $selectedEntry = $this->selectedEntryId !== null
            ? $allEntries->firstWhere('id', $this->selectedEntryId)
            : null;
        $programId = $this->programId ?? $curriculum->program_id;
        $selectedCourse = $selectedEntry?->courseSpecification?->course;

        $selectedReadiness = $selectedEntry instanceof CurriculumEntry
            ? $this->entryReadiness($selectedEntry)
            : null;

        $assignedBatches = $this->buildAssignedBatches($curriculum);
        $copyBanner = ($this->isEditableDraft() && $curriculum->copiedFrom instanceof CurriculumVersion)
            ? "Copied from {$curriculum->copiedFrom->batchLabel()}. Edit, add or remove subjects as needed. Changes you make will not affect the original curriculum or its students."
            : null;

        $nextStepLabel = $activeStep === 'year'
            ? ($activeYear < $this->scaffoldYears
                ? 'Next: '.$this->shortOrdinalYearLabel($activeYear + 1)
                : 'Next: Review')
            : null;

        return [
            'curriculum' => $curriculum,
            'readOnly' => $this->isReadOnly(),
            'canEdit' => $canEdit,
            'canDiscardDraft' => $canEdit && app(CurriculumVersionLifecycleService::class)->canDiscardDraft($curriculum),
            'isEditableDraft' => $this->isEditableDraft(),
            'isEmptyPlan' => $allEntries->isEmpty(),
            'totalSubjectsCount' => $allEntries->count(),
            'totalUnitsLabel' => $this->formatUnits((float) $sheet['summary']['total_units']),
            'isSearching' => $isSearching,
            'totalMatches' => $totalMatches,
            'activeYear' => $activeYear,
            'activeStep' => $activeStep,
            'activeYearTab' => collect($yearTabs)->firstWhere('year', $activeYear),
            'activeYearTotalCount' => $activeYearTotalCount,
            'activeYearUnits' => $activeYearUnits,
            'yearTabs' => $yearTabs,
            'nextStepLabel' => $nextStepLabel,
            'canGoBackStep' => $activeStep === 'review' || $activeYear > 1,
            'canAddYear' => $canEdit && $this->scaffoldYears < 8,
            'canRemoveActiveYear' => $canEdit && $activeYear === $this->scaffoldYears && $activeYear > 1 && $activeYearTotalCount === 0,
            'summerVisible' => $summerVisible,
            'canRemoveSummer' => $canEdit && $summerVisible && ! $hasSummerEntries,
            'regularSemesters' => $regularSemesters,
            'summerPanel' => $summerPanel,
            'selectedEntryIds' => $selectedEntryIds,
            'selectedEntriesSummary' => $selectedEntriesSummary,
            'copyBanner' => $copyBanner,
            'assignedBatches' => $assignedBatches,
            'showAssignedBatchesStrip' => $assignedBatches !== []
                || in_array($curriculum->state, [CurriculumVersion::StateDraft, CurriculumVersion::StateActive], true),
            'canAddBatch' => in_array($curriculum->state, [CurriculumVersion::StateDraft, CurriculumVersion::StateActive], true)
                && $this->currentUserCan('copy'),
            'reviewData' => $reviewData,
            'selectedSubject' => $selectedEntry,
            'selectedSubjectIsShared' => $selectedEntry instanceof CurriculumEntry
                && $this->isSubjectSharedWithOtherCurricula($selectedEntry),
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

    /**
     * @return list<array{
     *     id: int,
     *     academic_year_id: int,
     *     label: string,
     *     academic_year_label: string,
     *     students_count: int,
     *     added_at: string,
     *     in_use: bool,
     *     can_remove: bool
     * }>
     */
    private function buildAssignedBatches(CurriculumVersion $curriculum): array
    {
        $batches = $curriculum->batches
            ->filter(fn (CurriculumVersionBatch $batch): bool => $batch->academicYear instanceof AcademicYear)
            ->sortBy(fn (CurriculumVersionBatch $batch): array => [
                CurriculumVersion::extractAcademicYearStart($batch->academicYear) ?? PHP_INT_MAX,
                $batch->academicYear->starts_on?->timestamp ?? PHP_INT_MAX,
                (int) $batch->academic_year_id,
            ])
            ->values();

        if ($batches->isEmpty()) {
            return [];
        }

        $academicYearIds = $batches->pluck('academic_year_id')->all();
        $canManageBatches = $this->currentUserCan('copy');

        $studentCounts = StudentProfile::query()
            ->join('terms', 'terms.id', '=', 'student_profiles.entry_term_id')
            ->where('student_profiles.program_id', $curriculum->program_id)
            ->whereNull('student_profiles.merged_into_id')
            ->whereIn('terms.academic_year_id', $academicYearIds)
            ->selectRaw('terms.academic_year_id as academic_year_id, COUNT(*) as total')
            ->groupBy('terms.academic_year_id')
            ->pluck('total', 'academic_year_id');

        $cohortAcademicYearIds = TermCohort::query()
            ->join('terms', 'terms.id', '=', 'term_cohorts.term_id')
            ->where('term_cohorts.program_id', $curriculum->program_id)
            ->where('term_cohorts.curriculum_version_id', $curriculum->id)
            ->whereIn('terms.academic_year_id', $academicYearIds)
            ->distinct()
            ->pluck('terms.academic_year_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $applicantTable = (new AdmissionApplication)->getTable();
        $applicantAcademicYearIds = AdmissionApplication::query()
            ->join('terms', 'terms.id', '=', "{$applicantTable}.term_id")
            ->where("{$applicantTable}.program_id", $curriculum->program_id)
            ->whereNotIn("{$applicantTable}.application_state", [
                AdmissionApplication::StateWithdrawn,
                AdmissionApplication::StateNotAdmitted,
            ])
            ->whereIn('terms.academic_year_id', $academicYearIds)
            ->distinct()
            ->pluck('terms.academic_year_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $batches->map(function (CurriculumVersionBatch $batch) use (
            $studentCounts,
            $cohortAcademicYearIds,
            $applicantAcademicYearIds,
            $canManageBatches,
        ): array {
            $ayId = (int) $batch->academic_year_id;
            $studentsCount = (int) ($studentCounts->get($ayId) ?? 0);
            $inUse = $studentsCount > 0
                || in_array($ayId, $cohortAcademicYearIds, true)
                || in_array($ayId, $applicantAcademicYearIds, true);

            return [
                'id' => (int) $batch->id,
                'academic_year_id' => $ayId,
                'label' => 'Batch '.CurriculumVersion::formatBatchShort($batch->academicYear),
                'academic_year_label' => (string) $batch->academicYear->label,
                'students_count' => $studentsCount,
                'added_at' => $batch->created_at?->format('M j, Y') ?? 'Not recorded',
                'in_use' => $inUse,
                'can_remove' => $canManageBatches && ! $inUse,
            ];
        })->all();
    }

    /**
     * @param  Collection<int, CurriculumEntry>  $allEntries
     * @param  Collection<int, Collection<int, CurriculumEntry>>  $entriesByYear
     * @return array<string, mixed>
     */
    private function buildReviewData(
        CurriculumVersion $curriculum,
        Collection $allEntries,
        Collection $entriesByYear,
    ): array {
        $diff = $curriculum->copied_from_curriculum_version_id !== null
            ? app(CurriculumWorkbenchService::class)->diffEntriesAgainstSource($curriculum)
            : [
                'has_source' => false,
                'source_id' => null,
                'source_label' => null,
                'entry_statuses' => [],
                'entry_moves' => [],
                'counts' => ['copied' => 0, 'edited' => 0, 'added' => $allEntries->count(), 'removed' => 0],
                'moved_count' => 0,
                'removed' => [],
            ];

        $yearSummaryRows = [];
        $grand = [
            'sem1_count' => 0,
            'sem1_units' => 0.0,
            'sem2_count' => 0,
            'sem2_units' => 0.0,
            'summer_count' => 0,
            'summer_units' => 0.0,
            'total_count' => 0,
            'total_units' => 0.0,
        ];

        $groupedYears = [];
        $warnings = [];

        for ($year = 1; $year <= $this->scaffoldYears; $year++) {
            /** @var Collection<int, CurriculumEntry> $yearEntries */
            $yearEntries = $entriesByYear->get($year, collect());
            $sem1 = $yearEntries->where('term_type', Term::TypeFirstSemester)->values();
            $sem2 = $yearEntries->where('term_type', Term::TypeSecondSemester)->values();
            $summer = $yearEntries->where('term_type', Term::TypeSpecialTerm)->values();

            $sem1Units = (float) $sem1->sum(fn (CurriculumEntry $e): float => (float) ($e->courseSpecification?->credit_units ?? 0));
            $sem2Units = (float) $sem2->sum(fn (CurriculumEntry $e): float => (float) ($e->courseSpecification?->credit_units ?? 0));
            $summerUnits = (float) $summer->sum(fn (CurriculumEntry $e): float => (float) ($e->courseSpecification?->credit_units ?? 0));
            $yearUnits = $sem1Units + $sem2Units + $summerUnits;

            $grand['sem1_count'] += $sem1->count();
            $grand['sem1_units'] += $sem1Units;
            $grand['sem2_count'] += $sem2->count();
            $grand['sem2_units'] += $sem2Units;
            $grand['summer_count'] += $summer->count();
            $grand['summer_units'] += $summerUnits;
            $grand['total_count'] += $yearEntries->count();
            $grand['total_units'] += $yearUnits;

            $yearSummaryRows[] = [
                'year' => $year,
                'label' => $this->shortOrdinalYearLabel($year),
                'ordinal_label' => $this->ordinalYearLabel($year),
                'sem1_count' => $sem1->count(),
                'sem1_units' => $this->formatUnits($sem1Units),
                'sem2_count' => $sem2->count(),
                'sem2_units' => $this->formatUnits($sem2Units),
                'summer_count' => $summer->count(),
                'summer_units' => $this->formatUnits($summerUnits),
                'total_count' => $yearEntries->count(),
                'total_units' => $this->formatUnits($yearUnits),
            ];

            if ($this->isEditableDraft()) {
                if ($sem1->isEmpty()) {
                    $warnings[] = [
                        'scope' => "Year {$year} · 1st Sem",
                        'message' => 'No subjects placed yet.',
                        'year' => $year,
                    ];
                }

                if ($sem2->isEmpty()) {
                    $warnings[] = [
                        'scope' => "Year {$year} · 2nd Sem",
                        'message' => 'No subjects placed yet.',
                        'year' => $year,
                    ];
                }
            }

            $semesters = [];

            foreach ([
                Term::TypeFirstSemester => ['label' => '1st Semester', 'entries' => $sem1],
                Term::TypeSecondSemester => ['label' => '2nd Semester', 'entries' => $sem2],
                Term::TypeSpecialTerm => ['label' => 'Special Term (Summer)', 'entries' => $summer],
            ] as $termType => $termData) {
                if ($termData['entries']->isEmpty()) {
                    continue;
                }

                $termUnits = (float) $termData['entries']->sum(fn (CurriculumEntry $e): float => (float) ($e->courseSpecification?->credit_units ?? 0));
                $semesters[] = [
                    'term_type' => $termType,
                    'label' => $termData['label'],
                    'total_count' => $termData['entries']->count(),
                    'total_units' => $this->formatUnits($termUnits),
                    'rows' => $termData['entries']->map(function (CurriculumEntry $entry) use ($diff): array {
                        $sourceMoves = is_array($diff['entry_moves'] ?? null) ? $diff['entry_moves'] : [];
                        $row = $this->formatSubjectRow($entry, $sourceMoves);
                        $row['diff_status'] = $diff['has_source']
                            ? ($diff['entry_statuses'][$entry->id] ?? null)
                            : null;
                        $row['moved_from_label'] = $row['source_moved_label'];

                        return $row;
                    })->all(),
                ];
            }

            if ($semesters !== []) {
                $groupedYears[] = [
                    'year' => $year,
                    'label' => $this->ordinalYearLabel($year),
                    'short_label' => $this->shortOrdinalYearLabel($year),
                    'total_count' => $yearEntries->count(),
                    'total_units' => $this->formatUnits($yearUnits),
                    'semesters' => $semesters,
                ];
            }
        }

        if ($this->isEditableDraft() && $curriculum->batches->isEmpty()) {
            $warnings[] = [
                'scope' => 'Batch',
                'message' => 'No starting batch assigned yet. This curriculum will not appear in Admissions until a batch is assigned.',
                'year' => null,
            ];
        }

        if ($this->isEditableDraft() && $curriculum->program instanceof Program && ! $curriculum->program->is_active) {
            $warnings[] = [
                'scope' => (string) $curriculum->program->code,
                'message' => 'Program is currently inactive. Publishing will ask you to activate the program first.',
                'year' => null,
            ];
        }

        $blockers = $this->reviewReadinessBlockers();

        return [
            'summary_rows' => $yearSummaryRows,
            'grand_total' => [
                'sem1_count' => $grand['sem1_count'],
                'sem1_units' => $this->formatUnits($grand['sem1_units']),
                'sem2_count' => $grand['sem2_count'],
                'sem2_units' => $this->formatUnits($grand['sem2_units']),
                'summer_count' => $grand['summer_count'],
                'summer_units' => $this->formatUnits($grand['summer_units']),
                'total_count' => $grand['total_count'],
                'total_units' => $this->formatUnits($grand['total_units']),
            ],
            'grouped_years' => $groupedYears,
            'diff' => $diff,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'can_publish' => $this->isEditableDraft() && $this->currentUserCan('activate') && $blockers === [],
        ];
    }

    /**
     * Collect all hard readiness blockers that prevent publishing a draft curriculum.
     *
     * @return list<string>
     */
    private function reviewReadinessBlockers(): array
    {
        if ($this->cachedReviewBlockers !== null) {
            return $this->cachedReviewBlockers;
        }

        $curriculum = $this->loadedCurriculum();
        $blockers = [];

        if ($curriculum->entries->isEmpty()) {
            $blockers[] = 'Add at least one subject to this curriculum before publishing.';
        }

        $codeOccurrences = [];

        foreach ($curriculum->entries as $entry) {
            $spec = $entry->courseSpecification;

            if (! $spec instanceof CourseSpecification) {
                $blockers[] = 'One subject row is missing its linked subject details. Remove or replace it.';

                continue;
            }

            $code = strtoupper(trim((string) ($spec->course?->code ?? '')));
            $isNonCredit = $spec->academic_classification === CourseSpecification::AcademicClassificationNonCredit;

            if ($isNonCredit ? (float) $spec->credit_units < 0 : (float) $spec->credit_units <= 0) {
                $label = $code !== '' ? "{$code} · {$spec->title}" : $spec->title;
                $blockers[] = "{$label} must have more than 0 units.";
            }

            if ($code !== '') {
                $placement = $this->shortOrdinalYearLabel(max(1, $this->parseYearNumber($entry->year_level)))
                    .' · '.(self::semesterFieldOptions()[$entry->term_type] ?? $entry->term_label);
                $codeOccurrences[$code][] = $placement;
            }
        }

        foreach ($codeOccurrences as $code => $placements) {
            if (count($placements) > 1) {
                $blockers[] = "Duplicate subject code {$code} appears multiple times (".implode(', ', $placements).'). Remove or replace duplicate placements.';
            }
        }

        foreach (app(CurriculumVersionLifecycleService::class)->readinessErrors($curriculum) as $serviceError) {
            $blockers[] = $serviceError;
        }

        return $this->cachedReviewBlockers = array_values(array_unique($blockers));
    }

    private function programCurriculaReturnUrl(int $programId): string
    {
        return ProgramResource::getUrl('view', array_filter([
            'record' => $programId,
            'tab' => 'curricula',
            'oversightTermId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null));
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
            default => $year > 0 ? "{$year}th Year" : '1st Year',
        };
    }

    /**
     * @param  Collection<int, CurriculumEntry>  $yearEntries
     * @param  array<int, array{from_year: string, from_term_type: string, from_term_label: string}>  $entryMoves
     * @return array<string, mixed>
     */
    private function buildSemesterPanel(
        int $year,
        string $termType,
        string $label,
        Collection $yearEntries,
        string $needle,
        array $entryMoves = [],
    ): array {
        $allSemesterEntries = $yearEntries
            ->where('term_type', $termType)
            ->values();

        $visibleEntries = $needle !== ''
            ? $allSemesterEntries->filter(fn (CurriculumEntry $entry): bool => $this->entryMatchesSearch($entry, $needle))->values()
            : $allSemesterEntries;

        $totalUnits = $this->formatUnits($allSemesterEntries->sum(fn (CurriculumEntry $entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0)));

        $rows = $visibleEntries
            ->map(fn (CurriculumEntry $entry): array => $this->formatSubjectRow($entry, $entryMoves))
            ->all();

        $visibleIds = collect($rows)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $selectedInSemesterCount = count(array_intersect($visibleIds, $this->selectedEntryIds));
        $allSemesterSelected = $visibleIds !== [] && $selectedInSemesterCount === count($visibleIds);

        return [
            'year' => $year,
            'term_type' => $termType,
            'label' => $label,
            'total_count' => $allSemesterEntries->count(),
            'total_units' => $totalUnits,
            'selected_count' => $selectedInSemesterCount,
            'all_selected' => $allSemesterSelected,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int, array{from_year: string, from_term_type: string, from_term_label: string}>  $entryMoves
     * @return array<string, mixed>
     */
    private function formatSubjectRow(CurriculumEntry $entry, array $entryMoves = []): array
    {
        $spec = $entry->courseSpecification;
        $status = $this->entryReadiness($entry);
        $prerequisites = $spec instanceof CourseSpecification
            ? $spec->requirements
                ->where('rule_type', CourseRequirement::TypePrerequisite)
                ->map(fn (CourseRequirement $requirement): ?string => $requirement->relatedCourse?->code)
                ->filter()
                ->implode(', ')
            : '';

        $isExternallyArranged = $spec instanceof CourseSpecification
            && $spec->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged;

        $roomSummary = null;

        if ($spec instanceof CourseSpecification && ! $isExternallyArranged) {
            $components = $spec->components->sortBy('sequence');
            $hasLab = $components->contains(fn (CourseComponent $c): bool => $c->component_type === CourseComponent::TypeLaboratory);
            $hasLec = $components->contains(fn (CourseComponent $c): bool => $c->component_type === CourseComponent::TypeLecture);
            $hasComLab = $components->contains(fn (CourseComponent $c): bool => $c->room_type_default === CourseComponent::RoomTypeComputerLaboratory);

            if ($hasLec && $hasLab) {
                $roomSummary = $hasComLab ? 'Lec + Computer Lab' : 'Lec + Lab';
            } elseif ($hasComLab) {
                $roomSummary = 'Computer Lab';
            } elseif ($hasLab) {
                $roomSummary = 'Laboratory';
            }
        }

        $justMoved = $this->recentlyMovedEntries[(int) $entry->id] ?? null;
        $justMovedLabel = null;
        if (is_array($justMoved)) {
            $justMovedLabel = ($justMoved['kind'] ?? 'moved') === 'reordered'
                ? 'Reordered'
                : 'Moved from '.$this->shortOrdinalYearLabel(max(1, (int) $justMoved['from_year'])).' · '.(self::semesterFieldOptions()[(string) $justMoved['from_term_type']] ?? (string) $justMoved['from_term_label']);
        }

        $sourceMove = $entryMoves[(int) $entry->id] ?? null;
        $sourceMovedLabel = is_array($sourceMove)
            ? 'Moved from '.$this->shortOrdinalYearLabel(max(1, $this->parseYearNumber((string) $sourceMove['from_year']))).' · '.(self::semesterFieldOptions()[(string) $sourceMove['from_term_type']] ?? (string) $sourceMove['from_term_label'])
            : null;

        return [
            'id' => $entry->id,
            'code' => (string) ($spec?->course?->code ?? '-'),
            'title' => (string) ($spec?->title ?? 'Subject details unavailable'),
            'units' => $spec instanceof CourseSpecification
                ? $this->formatUnits((float) $spec->credit_units)
                : '-',
            'is_checked' => in_array((int) $entry->id, $this->selectedEntryIds, true),
            'is_elective' => $entry->requirement_group !== CurriculumEntry::RequirementGroupRequired,
            'elective_label' => CurriculumEntry::requirementGroupOptions()[$entry->requirement_group] ?? $entry->requirement_group,
            'is_externally_arranged' => $isExternallyArranged,
            'room_summary' => $roomSummary,
            'prerequisites' => $prerequisites,
            'just_moved_label' => $justMovedLabel,
            'source_moved_label' => $sourceMovedLabel,
            'incomplete_message' => $status['status'] !== 'Specification ready'
                ? ($status['status'] === 'Specification missing' ? 'No subject linked' : 'Missing teaching details')
                : null,
        ];
    }

    private function isSelectedEntryPlacementChanging(mixed $yearLevel, mixed $termType): bool
    {
        if ($this->selectedEntryId === null || ! is_numeric($yearLevel) || ! is_string($termType) || $termType === '') {
            return false;
        }

        $entry = $this->loadedCurriculum()->entries->firstWhere('id', $this->selectedEntryId);
        if (! $entry instanceof CurriculumEntry) {
            return false;
        }

        $currentYear = max(1, $this->parseYearNumber($entry->year_level));

        return (int) $yearLevel !== $currentYear || $termType !== (string) $entry->term_type;
    }

    private function renderSelectedEntryMoveGuardHtml(mixed $yearLevel, mixed $termType): Htmlable
    {
        $curriculum = $this->loadedCurriculum();
        $entry = $this->selectedEntryId !== null ? $curriculum->entries->firstWhere('id', $this->selectedEntryId) : null;
        if (! $entry instanceof CurriculumEntry) {
            return new HtmlString('');
        }

        $impact = $this->evaluateMoveImpact(
            $curriculum,
            collect([$entry]),
            max(1, (int) ($yearLevel ?? $this->currentActiveYear())),
            is_string($termType) && $termType !== '' ? $termType : (string) $entry->term_type,
        );

        return $this->renderMoveImpactHtml($impact);
    }

    /**
     * @return array<string, mixed>
     */
    private function bulkMoveImpact(int $targetYear, string $targetTermType): array
    {
        $curriculum = $this->loadedCurriculum();
        $selectedIds = $this->normalizeSelectedEntryIds($curriculum->entries);
        $entries = $curriculum->entries->whereIn('id', $selectedIds)->values();

        return $this->evaluateMoveImpact($curriculum, $entries, max(1, $targetYear), $targetTermType);
    }

    private function renderBulkMoveGuardHtml(int $targetYear, string $targetTermType): Htmlable
    {
        return $this->renderMoveImpactHtml($this->bulkMoveImpact($targetYear, $targetTermType));
    }

    /**
     * @param  Collection<int, CurriculumEntry>  $entriesToMove
     * @return array{
     *     has_changes: bool,
     *     to_label: string,
     *     current_units: string,
     *     projected_units: string,
     *     items: list<array{code: string, title: string, from_label: string, changing: bool}>,
     *     warnings: list<string>
     * }
     */
    private function evaluateMoveImpact(CurriculumVersion $curriculum, Collection $entriesToMove, int $targetYear, string $targetTermType): array
    {
        $curriculum->entries->loadMissing(['courseSpecification.course', 'courseSpecification.requirements.relatedCourse']);

        $termRank = fn (int $year, string $termType): int => ($year * 10) + match ($termType) {
            Term::TypeFirstSemester => 1,
            Term::TypeSecondSemester => 2,
            default => 3,
        };
        $formatPlace = fn (int $year, string $termType): string => $this->shortOrdinalYearLabel($year).' · '.(self::semesterFieldOptions()[$termType] ?? $termType);

        $movingLookup = $entriesToMove->pluck('id')->map(fn (mixed $id): int => (int) $id)->flip()->all();
        $targetRank = $termRank($targetYear, $targetTermType);
        $toLabel = $formatPlace($targetYear, $targetTermType);

        $currentDestEntries = $curriculum->entries->filter(
            fn (CurriculumEntry $entry): bool => max(1, $this->parseYearNumber($entry->year_level)) === $targetYear
                && (string) $entry->term_type === $targetTermType
        );
        $currentUnits = (float) $currentDestEntries->sum(fn (CurriculumEntry $entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0));

        $stayingDestEntries = $currentDestEntries->filter(
            fn (CurriculumEntry $entry): bool => ! isset($movingLookup[(int) $entry->id])
        );
        $projectedUnits = (float) $stayingDestEntries->sum(fn (CurriculumEntry $entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0))
            + (float) $entriesToMove->sum(fn (CurriculumEntry $entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0));

        $items = [];
        $hasChanges = false;
        $simulatedCourseLocations = [];

        foreach ($curriculum->entries as $entry) {
            $courseId = (int) ($entry->courseSpecification?->course_id ?? 0);
            if ($courseId <= 0) {
                continue;
            }
            $isMoving = isset($movingLookup[(int) $entry->id]);
            $simYear = $isMoving ? $targetYear : max(1, $this->parseYearNumber($entry->year_level));
            $simTermType = $isMoving ? $targetTermType : (string) $entry->term_type;
            $simulatedCourseLocations[$courseId] = [
                'code' => (string) ($entry->courseSpecification?->course?->code ?? 'Subject'),
                'rank' => $termRank($simYear, $simTermType),
                'label' => $formatPlace($simYear, $simTermType),
            ];
        }

        $warnings = [];
        foreach ($entriesToMove as $entry) {
            $fromYear = max(1, $this->parseYearNumber($entry->year_level));
            $fromTermType = (string) $entry->term_type;
            $changing = $fromYear !== $targetYear || $fromTermType !== $targetTermType;
            if ($changing) {
                $hasChanges = true;
            }

            $code = (string) ($entry->courseSpecification?->course?->code ?? '-');
            $items[] = [
                'code' => $code,
                'title' => (string) ($entry->courseSpecification?->title ?? 'Untitled subject'),
                'from_label' => $formatPlace($fromYear, $fromTermType),
                'changing' => $changing,
            ];

            if (! $changing) {
                continue;
            }

            $prereqs = $entry->courseSpecification?->requirements
                ?->where('rule_type', CourseRequirement::TypePrerequisite) ?? collect();

            foreach ($prereqs as $prereq) {
                $prereqCourseId = (int) $prereq->related_course_id;
                $prereqLoc = $simulatedCourseLocations[$prereqCourseId] ?? null;
                if (is_array($prereqLoc) && $prereqLoc['rank'] >= $targetRank) {
                    $warnings[] = "{$code} is moving to {$toLabel}, which is not after its prerequisite {$prereqLoc['code']} ({$prereqLoc['label']}).";
                }
            }

            $movedCourseId = (int) ($entry->courseSpecification?->course_id ?? 0);
            if ($movedCourseId > 0) {
                foreach ($curriculum->entries as $otherEntry) {
                    if (isset($movingLookup[(int) $otherEntry->id])) {
                        continue;
                    }
                    $otherPrereqs = $otherEntry->courseSpecification?->requirements
                        ?->where('rule_type', CourseRequirement::TypePrerequisite) ?? collect();
                    if ($otherPrereqs->contains('related_course_id', $movedCourseId)) {
                        $otherYear = max(1, $this->parseYearNumber($otherEntry->year_level));
                        $otherTermType = (string) $otherEntry->term_type;
                        if ($termRank($otherYear, $otherTermType) <= $targetRank) {
                            $otherCode = (string) ($otherEntry->courseSpecification?->course?->code ?? 'Subject');
                            $warnings[] = "{$otherCode} ({$formatPlace($otherYear, $otherTermType)}) requires {$code} and would come on or before {$toLabel}.";
                        }
                    }
                }
            }
        }

        return [
            'has_changes' => $hasChanges,
            'to_label' => $toLabel,
            'current_units' => $this->formatUnits($currentUnits),
            'projected_units' => $this->formatUnits($projectedUnits),
            'items' => $items,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  array<string, mixed>  $impact
     */
    private function renderMoveImpactHtml(array $impact): Htmlable
    {
        return new HtmlString(Blade::render(<<<'BLADE'
            <div class="rounded-lg border p-3 text-xs space-y-2" style="border-color: var(--tala-edge); background: var(--tala-surface-sunken);">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-semibold">Destination: {{ $impact['to_label'] }}</span>
                    <span class="tabular-nums text-gray-600 dark:text-gray-300">Semester load: <strong>{{ $impact['current_units'] }}</strong> → <strong>{{ $impact['projected_units'] }} units</strong></span>
                </div>
                @if (! $impact['has_changes'])
                    <p class="text-gray-600 dark:text-gray-300">Selected destination matches the current placement. Choose a different year level or semester to move.</p>
                @else
                    <ul class="space-y-1">
                        @foreach ($impact['items'] as $item)
                            <li class="flex flex-wrap items-center justify-between gap-2">
                                <span><strong>{{ $item['code'] }}</strong> {{ $item['title'] }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ $item['from_label'] }} → {{ $impact['to_label'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($impact['warnings'] !== [])
                        <div class="rounded-md border p-2.5 space-y-1" style="border-color: var(--tala-status-warning-border); background: var(--tala-status-warning-bg); color: var(--tala-status-warning-fg);">
                            <p class="font-semibold">Prerequisite order check</p>
                            @foreach ($impact['warnings'] as $warning)
                                <p>{{ $warning }}</p>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        BLADE, ['impact' => $impact]));
    }

    /**
     * Normalize `$selectedEntryIds` against existing entries in the loaded curriculum.
     *
     * @param  Collection<int, CurriculumEntry>|null  $entries
     * @return list<int>
     */
    private function normalizeSelectedEntryIds(?Collection $entries = null): array
    {
        $entries ??= $this->loadedCurriculum()->entries;
        $validLookup = $entries->pluck('id')->map(fn (mixed $id): int => (int) $id)->flip()->all();

        $normalized = [];

        foreach ($this->selectedEntryIds as $id) {
            $intId = (int) $id;

            if ($intId > 0 && isset($validLookup[$intId]) && ! in_array($intId, $normalized, true)) {
                $normalized[] = $intId;
            }
        }

        return $this->selectedEntryIds = $normalized;
    }

    /**
     * Summarize the current multi-year selection count and distinct years/semesters.
     *
     * @param  Collection<int, CurriculumEntry>|null  $entries
     * @param  list<int>|null  $selectedIds
     * @return array{count: int, years: list<int>, years_label: string, scope_label: string}
     */
    private function selectedEntriesSummary(?Collection $entries = null, ?array $selectedIds = null): array
    {
        $entries ??= $this->loadedCurriculum()->entries;
        $selectedIds ??= $this->normalizeSelectedEntryIds($entries);

        if ($selectedIds === []) {
            return [
                'count' => 0,
                'years' => [],
                'years_label' => '',
                'scope_label' => '',
            ];
        }

        $selectedLookup = array_flip($selectedIds);
        $selectedEntries = $entries
            ->filter(fn (CurriculumEntry $entry): bool => isset($selectedLookup[(int) $entry->id]))
            ->values();

        $years = $selectedEntries
            ->map(fn (CurriculumEntry $entry): int => max(1, $this->parseYearNumber($entry->year_level)))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $yearsLabel = implode(', ', array_map(fn (int $year): string => "Year {$year}", $years));

        $scopes = $selectedEntries
            ->sortBy([
                fn (CurriculumEntry $a, CurriculumEntry $b): int => $this->parseYearNumber($a->year_level) <=> $this->parseYearNumber($b->year_level),
                fn (CurriculumEntry $a, CurriculumEntry $b): int => $this->termOrder($a->term_type) <=> $this->termOrder($b->term_type),
            ])
            ->map(function (CurriculumEntry $entry): string {
                $yearNum = max(1, $this->parseYearNumber($entry->year_level));
                $semester = self::semesterFieldOptions()[$entry->term_type] ?? ($entry->term_label ?: $entry->term_type);

                return "Year {$yearNum} · {$semester}";
            })
            ->unique()
            ->values()
            ->all();

        return [
            'count' => $selectedEntries->count(),
            'years' => $years,
            'years_label' => $yearsLabel,
            'scope_label' => implode(', ', $scopes),
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
    private static function subjectRequirementOptions(): array
    {
        return [
            CurriculumEntry::RequirementGroupRequired => 'Required for all students',
            CurriculumEntry::RequirementGroupElective => 'Elective subject',
        ];
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
            ? 'Externally arranged (no class schedule)'
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
            $facts['Type'] = self::subjectRequirementOptions()[$entry->requirement_group] ?? $entry->requirement_group;
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
            CurriculumVersion::StateActive => 'This curriculum is Active and its subjects are read-only. You can assign additional batches, retire it, or copy it into a new draft curriculum.',
            CurriculumVersion::StateSuperseded => 'This curriculum is Retired and kept read-only for history. Continuing students remain on this plan.',
            default => 'This curriculum is read-only. Copy it into a new draft curriculum to make changes.',
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

        $specification->loadMissing(['components', 'requirements']);

        return [
            'title' => $specification->title,
            'description' => $specification->description,
            'credit_units' => $specification->credit_units,
            'academic_classification' => $specification->academic_classification ?: CourseSpecification::AcademicClassificationOrdinary,
            'grading_profile_key' => $specification->grading_profile_key,
            'grading_profile_version' => $specification->grading_profile_version,
            'scheduling_treatment' => $specification->scheduling_treatment,
            'allowed_modalities' => $specification->allowed_modalities,
            'same_faculty_default' => $specification->same_faculty_default,
            'prerequisite_ids' => $specification->requirements
                ->where('rule_type', CourseRequirement::TypePrerequisite)
                ->pluck('related_course_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all(),
            'corequisite_ids' => $specification->requirements
                ->where('rule_type', CourseRequirement::TypeCorequisite)
                ->pluck('related_course_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all(),
            'equivalent_ids' => $specification->requirements
                ->where('rule_type', CourseRequirement::TypeEquivalency)
                ->pluck('related_course_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all(),
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
     * Build the native Repeater fields for New subjects with code blur auto-fill from the catalog.
     *
     * @return list<Component>
     */
    private function newSubjectRepeaterFields(): array
    {
        $sharedSections = CourseSpecificationForm::newSubjectFields(columns: 2);

        $basicsSection = Section::make('Subject basics')
            ->description('Enter the subject code, name, units, and subject type. Known catalog codes auto-fill defaults on blur.')
            ->schema([
                TextInput::make('code')
                    ->label('Subject code')
                    ->placeholder('e.g. IT101')
                    ->datalist(fn (): array => array_values($this->courseOptions()))
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        $this->applyCatalogDefaultsToNewSubjectRow($state, $get, $set);
                    })
                    ->required()
                    ->maxLength(64),
                TextInput::make('title')
                    ->label('Subject name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('credit_units')
                    ->label('Units')
                    ->numeric()
                    ->minValue(fn (Get $get): float => $get('academic_classification') === CourseSpecification::AcademicClassificationNonCredit ? 0.0 : 0.25)
                    ->maxValue(30)
                    ->step(0.25)
                    ->required(),
                Select::make('academic_classification')
                    ->label('Subject type')
                    ->options(CourseSpecificationForm::subjectTypeOptions())
                    ->default(CourseSpecification::AcademicClassificationOrdinary)
                    ->live()
                    ->helperText('Controls GWA inclusion and marks TESDA competency courses. Link external TESDA NC assessments on the curriculum Review step.')
                    ->required(),
                Textarea::make('description')
                    ->label('Subject description (optional)')
                    ->placeholder('Brief overview of topics, competencies, or outcomes covered in this subject.')
                    ->maxLength(2000)
                    ->rows(2)
                    ->columnSpanFull(),
                Hidden::make('grading_profile_key')->default(CourseSpecification::GradingProfileServitechV1),
                Hidden::make('grading_profile_version')->default(1),
            ])
            ->columns(['default' => 1, 'md' => 2])
            ->columnSpanFull();

        return [
            $basicsSection,
            ...array_slice($sharedSections, 1),
        ];
    }

    /**
     * Auto-fill title, units, and scheduling defaults when a known catalog subject code is entered.
     */
    private function applyCatalogDefaultsToNewSubjectRow(?string $state, Get $get, Set $set): void
    {
        $rawCode = trim((string) $state);

        if ($rawCode === '') {
            return;
        }

        $defaults = app(CurriculumWorkbenchService::class)->lookupSubjectDefaultsByCode($rawCode);

        if ($defaults === null) {
            return;
        }

        $set('code', $defaults['code']);

        if (blank($get('title'))) {
            $set('title', $defaults['title']);
        }

        if (blank($get('credit_units'))) {
            $set('credit_units', $this->formatUnits($defaults['credit_units']));
        }

        if (filled($defaults['academic_classification'])) {
            $set('academic_classification', $defaults['academic_classification']);
        }

        if (filled($defaults['scheduling_treatment'])) {
            $set('scheduling_treatment', $defaults['scheduling_treatment']);
        }

        if ($defaults['allowed_modalities'] !== []) {
            $set('allowed_modalities', $defaults['allowed_modalities']);
        }

        if ($defaults['scheduling_treatment'] === CourseSpecification::SchedulingExternallyArranged) {
            $set('components', []);
        } elseif ($defaults['components'] !== []) {
            $mappedComponents = [];

            foreach ($defaults['components'] as $index => $component) {
                $mappedComponents[(string) Str::uuid()] = [
                    'component_type' => $component['component_type'],
                    'room_type_default' => $component['room_type_default'],
                    'meeting_pattern' => $component['meeting_pattern'],
                    'weekly_contact_hours' => $component['weekly_contact_hours'],
                    'required_room_feature_keys' => [],
                    'modality_restriction' => null,
                    'requires_consecutive_block' => (bool) ($component['requires_consecutive_block'] ?? false),
                    'same_faculty' => (bool) ($component['same_faculty'] ?? false),
                    'sequence' => (int) ($component['sequence'] ?? ($index + 1)),
                ];
            }

            $set('components', $mappedComponents);
        }

        if (empty($get('prerequisite_ids')) && $defaults['prerequisite_ids'] !== []) {
            $set('prerequisite_ids', $defaults['prerequisite_ids']);
        }

        if (empty($get('corequisite_ids')) && $defaults['corequisite_ids'] !== []) {
            $set('corequisite_ids', $defaults['corequisite_ids']);
        }

        if (empty($get('equivalent_ids')) && $defaults['equivalent_ids'] !== []) {
            $set('equivalent_ids', $defaults['equivalent_ids']);
        }
    }

    /**
     * @return list<Component>
     */
    private function specificationFields(): array
    {
        return [
            Section::make('Subject basics')
                ->compact()
                ->schema([
                    TextInput::make('title')
                        ->label('Subject name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('credit_units')
                        ->label('Units')
                        ->numeric()
                        ->minValue(fn (Get $get): float => $get('academic_classification') === CourseSpecification::AcademicClassificationNonCredit ? 0.0 : 0.25)
                        ->maxValue(30)
                        ->step(0.25)
                        ->required(),
                    Select::make('academic_classification')
                        ->label('Subject type')
                        ->options(CourseSpecificationForm::subjectTypeOptions())
                        ->default(CourseSpecification::AcademicClassificationOrdinary)
                        ->live()
                        ->helperText('Controls GWA inclusion and marks TESDA competency courses. Link external TESDA NC assessments on the curriculum Review step.')
                        ->required()
                        ->columnSpanFull(),
                    Textarea::make('description')
                        ->label('Subject description (optional)')
                        ->placeholder('Brief overview of topics, competencies, or outcomes covered in this subject.')
                        ->maxLength(2000)
                        ->rows(2)
                        ->columnSpanFull(),
                    Hidden::make('grading_profile_key')->default(CourseSpecification::GradingProfileServitechV1),
                    Hidden::make('grading_profile_version')->default(1),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),
            Section::make('Weekly classes & teaching')
                ->compact()
                ->description('Configure Lec+Lab splits (e.g. 2h Lec + 3h Lab), delivery modes, and room equipment.')
                ->schema([
                    Radio::make('scheduling_treatment')
                        ->label('Schedule type')
                        ->options([
                            CourseSpecification::SchedulingRecurring => 'Weekly scheduled class',
                            CourseSpecification::SchedulingExternallyArranged => 'Externally arranged (no class schedule)',
                        ])
                        ->descriptions([
                            CourseSpecification::SchedulingRecurring => 'Assigned fixed weekly days & times on the timetable',
                            CourseSpecification::SchedulingExternallyArranged => 'No fixed weekly room/time slot (e.g. OJT, practicum, thesis)',
                        ])
                        ->default(CourseSpecification::SchedulingRecurring)
                        ->columns(['default' => 1, 'sm' => 2])
                        ->gridDirection(GridDirection::Row)
                        ->extraAttributes([
                            'class' => 'tala-choice-options tala-choice-options--cards',
                        ])
                        ->live()
                        ->required()
                        ->columnSpanFull(),
                    CheckboxList::make('allowed_modalities')
                        ->label('Allowed delivery modes')
                        ->options(CourseSpecification::modalityOptions())
                        ->columns(2)
                        ->live()
                        ->required()
                        ->columnSpanFull(),
                    Toggle::make('same_faculty_default')
                        ->label('Same teacher for Lecture & Lab')
                        ->helperText('Keep one teacher assigned to both Lecture and Lab meetings of a section.')
                        ->inline(false)
                        ->default(false)
                        ->dehydratedWhenHidden()
                        ->visible(fn (Get $get): bool => $get('scheduling_treatment') !== CourseSpecification::SchedulingExternallyArranged
                            && count($get('components') ?? []) > 1)
                        ->columnSpanFull(),
                    Repeater::make('components')
                        ->label('Class meetings')
                        ->columnSpanFull()
                        ->extraAttributes(['class' => 'tala-record-repeater'])
                        ->live()
                        ->schema([
                            Select::make('component_type')
                                ->label('Class format')
                                ->options(CourseComponent::typeOptions())
                                ->default(CourseComponent::TypeLecture)
                                ->live()
                                ->afterStateUpdated(function (?string $state, Set $set): void {
                                    $set('room_type_default', match ($state) {
                                        CourseComponent::TypeLecture => CourseComponent::RoomTypeLectureRoom,
                                        CourseComponent::TypeLaboratory => CourseComponent::RoomTypeLaboratory,
                                        default => null,
                                    });
                                })
                                ->required(),
                            Select::make('room_type_default')
                                ->label('Room type')
                                ->options(CourseComponent::roomTypeOptions())
                                ->default(CourseComponent::RoomTypeLectureRoom)
                                ->placeholder('Select room type')
                                ->nullable(),
                            Select::make('meeting_pattern')
                                ->label('Weekly sessions')
                                ->options(CourseComponent::meetingPatternOptions())
                                ->default('2x90')
                                ->live()
                                ->afterStateUpdated(function (?string $state, Set $set): void {
                                    $parsed = CourseComponent::parseMeetingPattern((string) $state);

                                    if ($parsed !== null) {
                                        $set('weekly_contact_hours', round(($parsed['count'] * $parsed['duration_minutes']) / 60, 2));
                                    }
                                })
                                ->required(),
                            TextInput::make('weekly_contact_hours')
                                ->label('Weekly hours')
                                ->numeric()
                                ->default(3)
                                ->minValue(0.25)
                                ->maxValue(40)
                                ->step(0.25)
                                ->helperText('Auto-calculated from weekly sessions; adjust if needed.')
                                ->required(),
                            RoomEquipment::select('required_room_feature_keys')
                                ->multiple()
                                ->label('Room equipment (optional)')
                                ->visible(function (Get $get): bool {
                                    $modalities = (array) ($get('../../allowed_modalities') ?? []);

                                    return in_array(CourseSpecification::ModalityFaceToFace, $modalities, true)
                                        && $get('modality_restriction') !== CourseSpecification::ModalityOnline;
                                }),
                            Select::make('modality_restriction')
                                ->label('Meeting delivery mode')
                                ->placeholder('Follow subject modes')
                                ->options(CourseSpecification::modalityOptions())
                                ->nullable()
                                ->live()
                                ->visible(function (Get $get): bool {
                                    $modalities = (array) ($get('../../allowed_modalities') ?? []);

                                    return in_array(CourseSpecification::ModalityFaceToFace, $modalities, true)
                                        && in_array(CourseSpecification::ModalityOnline, $modalities, true);
                                }),
                            Hidden::make('requires_consecutive_block')->default(false),
                            Hidden::make('same_faculty')->default(false),
                            Hidden::make('sequence')->default(1),
                        ])
                        ->columns(['default' => 1, 'md' => 2])
                        ->defaultItems(1)
                        ->addActionLabel('Add class meeting')
                        ->visible(fn (Get $get): bool => $get('scheduling_treatment') !== CourseSpecification::SchedulingExternallyArranged),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),
            Section::make('Prerequisites, corequisites & equivalents (optional)')
                ->compact()
                ->collapsed()
                ->schema([
                    Select::make('prerequisite_ids')
                        ->label('Prerequisites')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => $this->courseOptions()),
                    Select::make('corequisite_ids')
                        ->label('Corequisites')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => $this->courseOptions()),
                    Select::make('equivalent_ids')
                        ->label('Approved equivalent subjects')
                        ->helperText('Older or alternate subject codes (such as bridging or previous-curriculum subjects) accepted in place of this subject and its prerequisites.')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => $this->courseOptions())
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),
        ];
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
     * Build searchable option labels and unit/room descriptions for catalog subjects not yet placed in this curriculum.
     *
     * @return array{options: array<int, string>, descriptions: array<int, string>}
     */
    private function availableCatalogSubjects(): array
    {
        if ($this->cachedAvailableCatalogSubjects !== null) {
            return $this->cachedAvailableCatalogSubjects;
        }

        $entries = $this->loadedCurriculum()->entries;

        $placedCourseIds = $entries
            ->map(fn (CurriculumEntry $entry): ?int => $entry->courseSpecification?->course_id)
            ->filter(fn (?int $id): bool => $id !== null)
            ->map(fn (int $id): int => $id)
            ->unique()
            ->all();

        $placedSpecIds = $entries
            ->pluck('course_specification_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        $placedCourseLookup = array_flip($placedCourseIds);
        $placedSpecLookup = array_flip($placedSpecIds);

        $specifications = CourseSpecification::query()
            ->whereIn('state', [CourseSpecification::StateActive, CourseSpecification::StateDraft])
            ->with(['course', 'components'])
            ->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', [CourseSpecification::StateActive])
            ->orderByDesc('id')
            ->get()
            ->filter(function (CourseSpecification $specification) use ($placedCourseLookup, $placedSpecLookup): bool {
                if (isset($placedSpecLookup[$specification->id])) {
                    return false;
                }

                if ($specification->course_id !== null && isset($placedCourseLookup[(int) $specification->course_id])) {
                    return false;
                }

                return true;
            })
            ->unique(fn (CourseSpecification $specification): string => $specification->course_id !== null
                ? 'course-'.$specification->course_id
                : 'spec-'.$specification->id)
            ->sortBy(fn (CourseSpecification $specification): string => strtoupper(trim((string) ($specification->course?->code ?? ''))).'|'.mb_strtolower((string) $specification->title))
            ->values();

        $options = [];
        $descriptions = [];

        foreach ($specifications as $specification) {
            $code = trim((string) ($specification->course?->code ?? ''));
            $title = trim((string) $specification->title);
            $options[$specification->id] = $code !== '' ? "{$code} · {$title}" : $title;
            $descriptions[$specification->id] = $this->describeCatalogSubject($specification);
        }

        return $this->cachedAvailableCatalogSubjects = [
            'options' => $options,
            'descriptions' => $descriptions,
        ];
    }

    private function describeCatalogSubject(CourseSpecification $specification): string
    {
        $unitsValue = (float) $specification->credit_units;
        $unitsText = $this->formatUnits($unitsValue).' '.($unitsValue === 1.0 ? 'unit' : 'units');

        if ($specification->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged) {
            return "{$unitsText} · Externally arranged";
        }

        $components = $specification->components->sortBy('sequence');
        $roomLabels = $components
            ->map(fn (CourseComponent $component): ?string => match ($component->room_type_default) {
                CourseComponent::RoomTypeLectureRoom => 'Lecture Room',
                CourseComponent::RoomTypeComputerLaboratory => 'Computer Lab',
                CourseComponent::RoomTypeLaboratory => 'Laboratory',
                default => null,
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($roomLabels !== []) {
            return $unitsText.' · '.implode(' / ', $roomLabels);
        }

        $componentLabels = $components
            ->map(fn (CourseComponent $component): string => CourseComponent::typeOptions()[$component->component_type] ?? $component->component_type)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($componentLabels !== []) {
            return $unitsText.' · '.implode(' / ', $componentLabels);
        }

        return "{$unitsText} · Schedule not set";
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

    /**
     * Build replacement subject options excluding courses already placed in other rows of this curriculum.
     *
     * @return array<int, string>
     */
    private function replacementCourseSpecificationOptions(): array
    {
        $otherEntries = $this->loadedCurriculum()->entries
            ->reject(fn (CurriculumEntry $entry): bool => $this->selectedEntryId !== null && $entry->id === $this->selectedEntryId);

        $placedCourseLookup = $otherEntries
            ->map(fn (CurriculumEntry $entry): ?int => $entry->courseSpecification?->course_id)
            ->filter(fn (?int $id): bool => $id !== null)
            ->flip()
            ->all();

        $placedSpecLookup = $otherEntries
            ->pluck('course_specification_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->flip()
            ->all();

        return CourseSpecification::query()
            ->with('course')
            ->orderBy('title')
            ->get()
            ->filter(function (CourseSpecification $specification) use ($placedCourseLookup, $placedSpecLookup): bool {
                if (isset($placedSpecLookup[$specification->id])) {
                    return false;
                }

                if ($specification->course_id !== null && isset($placedCourseLookup[(int) $specification->course_id])) {
                    return false;
                }

                return true;
            })
            ->mapWithKeys(fn (CourseSpecification $specification): array => [
                $specification->id => collect([
                    $specification->course?->code,
                    $specification->title,
                    CourseSpecification::stateOptions()[$specification->state] ?? str($specification->state)->headline(),
                ])->filter()->implode(' · '),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function courseOptions(): array
    {
        return $this->cachedCourseOptions ??= Course::query()
            ->orderBy('code')
            ->pluck('code', 'id')
            ->map(fn (mixed $code): string => (string) $code)
            ->all();
    }

    private function entryWarning(?CurriculumEntry $record, string $status): string
    {
        return match ($status) {
            'Specification missing' => 'No subject linked. Remove this row or choose a subject.',
            default => $record !== null && $this->canEditSpecification($record)
                ? 'Needs teaching details. Complete units, delivery modes, and class schedule below.'
                : 'Needs teaching details. Select Edit subject & schedule below to complete them.',
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
        $this->cachedAvailableCatalogSubjects = null;
        $this->cachedCourseOptions = null;
        $this->cachedReviewBlockers = null;
    }

    private function loadedCurriculum(): CurriculumVersion
    {
        $curriculum = $this->curriculumVersion();
        $curriculum->loadMissing([
            'program',
            'batches.academicYear',
            'copiedFrom.batches.academicYear',
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
            && ($this->actor()->can('update', $specification) || $this->actor()->can('copy', $specification));
    }

    /**
     * Determine whether any teaching or specification fields changed compared to the current CourseSpecification.
     *
     * @param  array<string, mixed>  $data
     */
    private function specificationHasChanges(CurriculumEntry $entry, array $data): bool
    {
        $specification = $entry->courseSpecification;

        if (! $specification instanceof CourseSpecification) {
            return false;
        }

        $specification->loadMissing(['components', 'requirements']);

        $submittedDescription = filled($data['description'] ?? null) ? trim((string) $data['description']) : null;
        $currentDescription = filled($specification->description) ? trim((string) $specification->description) : null;

        if (trim((string) ($data['title'] ?? '')) !== trim((string) $specification->title)
            || $submittedDescription !== $currentDescription
            || round((float) ($data['credit_units'] ?? 0), 2) !== round((float) $specification->credit_units, 2)) {
            return true;
        }

        $submittedClassification = (string) ($data['academic_classification'] ?? CourseSpecification::AcademicClassificationOrdinary);
        $currentClassification = (string) ($specification->academic_classification ?: CourseSpecification::AcademicClassificationOrdinary);
        $submittedGradingKey = filled($data['grading_profile_key'] ?? null)
            ? (string) $data['grading_profile_key']
            : CourseSpecification::GradingProfileServitechV1;
        $submittedGradingVersion = (int) ($data['grading_profile_version'] ?? 1);
        $submittedTreatment = (string) ($data['scheduling_treatment'] ?? '');

        if ($submittedClassification !== $currentClassification
            || $submittedGradingKey !== (string) $specification->grading_profile_key
            || $submittedGradingVersion !== (int) $specification->grading_profile_version
            || $submittedTreatment !== (string) $specification->scheduling_treatment
            || (bool) ($data['same_faculty_default'] ?? false) !== (bool) $specification->same_faculty_default) {
            return true;
        }

        $submittedModalities = collect((array) ($data['allowed_modalities'] ?? []))->map(fn (mixed $m): string => (string) $m)->unique()->sort()->values()->all();
        $currentModalities = collect((array) ($specification->allowed_modalities ?? []))->map(fn (mixed $m): string => (string) $m)->unique()->sort()->values()->all();

        if ($submittedModalities !== $currentModalities) {
            return true;
        }

        $courseId = (int) ($specification->course_id ?? 0);
        $normalizeCourseIds = static fn (array $ids): array => collect($ids)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $id !== $courseId)
            ->unique()
            ->sort()
            ->values()
            ->all();

        foreach ([
            'prerequisite_ids' => CourseRequirement::TypePrerequisite,
            'corequisite_ids' => CourseRequirement::TypeCorequisite,
            'equivalent_ids' => CourseRequirement::TypeEquivalency,
        ] as $field => $ruleType) {
            $submittedIds = $normalizeCourseIds((array) ($data[$field] ?? []));
            $currentIds = $normalizeCourseIds(
                $specification->requirements->where('rule_type', $ruleType)->pluck('related_course_id')->all()
            );

            if ($submittedIds !== $currentIds) {
                return true;
            }
        }

        if ($submittedTreatment === CourseSpecification::SchedulingExternallyArranged) {
            return false;
        }

        $normalizeComponent = static fn (array $component): array => [
            'component_type' => (string) ($component['component_type'] ?? ''),
            'weekly_contact_hours' => round((float) ($component['weekly_contact_hours'] ?? 0), 2),
            'meeting_pattern' => (string) ($component['meeting_pattern'] ?? ''),
            'room_type_default' => filled($component['room_type_default'] ?? null) ? (string) $component['room_type_default'] : null,
            'required_room_feature_keys' => collect((array) ($component['required_room_feature_keys'] ?? []))->map(fn (mixed $k): string => (string) $k)->unique()->sort()->values()->all(),
            'modality_restriction' => filled($component['modality_restriction'] ?? null) ? (string) $component['modality_restriction'] : null,
            'requires_consecutive_block' => (bool) ($component['requires_consecutive_block'] ?? false),
            'same_faculty' => (bool) ($component['same_faculty'] ?? false),
        ];

        $submittedComponents = collect((array) ($data['components'] ?? []))
            ->values()
            ->map(fn (mixed $component): array => $normalizeComponent(is_array($component) ? $component : []))
            ->all();

        $currentComponents = $specification->components
            ->sortBy('sequence')
            ->values()
            ->map(fn (CourseComponent $component): array => $normalizeComponent([
                'component_type' => $component->component_type,
                'weekly_contact_hours' => $component->weekly_contact_hours,
                'meeting_pattern' => $component->meeting_pattern,
                'room_type_default' => $component->room_type_default,
                'required_room_feature_keys' => $component->required_room_feature_keys,
                'modality_restriction' => $component->modality_restriction,
                'requires_consecutive_block' => $component->requires_consecutive_block,
                'same_faculty' => $component->same_faculty,
            ]))
            ->all();

        return $submittedComponents !== $currentComponents;
    }

    private function isSubjectSharedWithOtherCurricula(CurriculumEntry $entry): bool
    {
        $specification = $entry->courseSpecification;

        if (! $specification instanceof CourseSpecification) {
            return false;
        }

        if ($specification->state !== CourseSpecification::StateDraft) {
            return true;
        }

        $sharedSpecification = CurriculumEntry::query()
            ->where('course_specification_id', $specification->id)
            ->where('curriculum_version_id', '!=', $entry->curriculum_version_id)
            ->exists();

        if ($sharedSpecification) {
            return true;
        }

        if ($specification->course_id === null) {
            return false;
        }

        return CourseSpecification::query()
            ->where('course_id', $specification->course_id)
            ->whereKeyNot($specification->id)
            ->where(function ($query) use ($entry): void {
                $query->where('state', CourseSpecification::StateActive)
                    ->orWhereHas('curriculumEntries', fn ($entries) => $entries->where('curriculum_version_id', '!=', $entry->curriculum_version_id));
            })
            ->exists();
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
