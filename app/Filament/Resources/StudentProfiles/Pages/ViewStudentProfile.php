<?php

namespace App\Filament\Resources\StudentProfiles\Pages;

use App\Actions\Academics\AcademicEnrollmentGuidance;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\IrregularStudentReasons;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Academics\TermWeightedAverageProjection;
use App\Actions\Admissions\AdmissionPaperChecklist;
use App\Actions\Enrollment\CorrectRegistrationCreditEvaluation;
use App\Actions\Enrollment\EnrollmentAcademicContextResolver;
use App\Actions\Enrollment\RecordStudentSection;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\StudentProfiles\RecordPriorAcademicCredits;
use App\Actions\StudentProfiles\RecordStudentProfileCorrection;
use App\Actions\StudentProfiles\StudentAccountActivation;
use App\Filament\Concerns\HasAcademicOversightReturn;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Schemas\TransferCreditingForm;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\GradeRosters\Tables\ReleasedResultsTable;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Filament\Resources\StudentProfiles\IrregularReviewActions;
use App\Filament\Resources\StudentProfiles\RelationManagers\ChecklistItemsRelationManager;
use App\Filament\Resources\StudentProfiles\RelationManagers\HoldsRelationManager;
use App\Filament\Resources\StudentProfiles\StudentAcademicActions;
use App\Filament\Resources\StudentProfiles\StudentOnboardingActions;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Filament\Support\AdmissionPaperFields;
use App\Models\AdmissionApplication;
use App\Models\Assessment;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\ExternalCompetencyRequirement;
use App\Models\Hold;
use App\Models\PriorAcademicCredit;
use App\Models\RegistrationCreditEvaluation;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\GridDirection;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class ViewStudentProfile extends ViewRecord
{
    use HasAcademicOversightReturn;

    protected static string $resource = StudentProfileResource::class;

    #[Url]
    public string $viewTab = 'academic-progress';

    #[Locked]
    public bool $openedFromGradesWorkspace = false;

    #[Locked]
    public ?int $reviewedCreditEvaluationId = null;

    /** @var array<string, mixed> */
    #[Url]
    public array $queue = [];

    #[Url(history: true)]
    public string $recordTab = 'overview';

    #[Url]
    public ?int $academicTerm = null;

    #[Url]
    public ?string $academicPeriod = null;

    #[Url]
    public string $academicSearch = '';

    #[Url]
    public int $academicPage = 1;

    #[Url]
    public int|string|null $academicResult = null;

    #[Url]
    public ?int $enrollment = null;

    #[Url]
    public ?int $historyTerm = null;

    #[Url]
    public string $historyType = '';

    #[Url]
    public int $historyPage = 1;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->queue = StudentProfileResource::academicQueueParameters($this->queue);
        $this->openedFromGradesWorkspace = \Filament\Support\original_request()->query('viewTab') === 'academic-progress'
            || $this->queue !== [];
        $this->viewTab = 'academic-progress';
    }

    public function getTitle(): string
    {
        return collect([$this->record->first_name, $this->record->middle_name, $this->record->last_name])->filter()->implode(' ');
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function getHeading(): string
    {
        return '';
    }

    public function activeRecordTab(): string
    {
        return in_array($this->recordTab, ['overview', 'academic', 'enrollment', 'history', 'requirements'], true)
            ? $this->recordTab : 'overview';
    }

    public function selectRecordTab(string $tab): void
    {
        $this->recordTab = in_array($tab, ['overview', 'academic', 'enrollment', 'requirements', 'history'], true)
            ? $tab : 'overview';
    }

    public function getBreadcrumbs(): array
    {
        if ($this->isAcademicHeadReader()) {
            return [
                $this->oversightReturnUrl() => 'Academic Oversight',
                StudentProfileResource::getUrl('index', [
                    ...$this->oversightQueryParameters(),
                    ...($this->openedFromGradesWorkspace
                        ? ['viewTab' => 'academic-progress', ...StudentProfileResource::academicQueueParameters($this->queue)]
                        : []),
                ]) => $this->openedFromGradesWorkspace ? 'Student records' : 'Student Profiles',
                $this->getTitle(),
            ];
        }

        if (! $this->openedFromGradesWorkspace) {
            return [
                StudentProfileResource::getUrl('index') => 'Student Profiles',
                $this->getTitle(),
            ];
        }

        return [
            (GradesAndCompletion::canAccess()
                ? GradesAndCompletion::getUrl(['viewTab' => 'academic-progress', ...StudentProfileResource::academicQueueParameters($this->queue)])
                : StudentProfileResource::getUrl('index', ['viewTab' => 'academic-progress', ...StudentProfileResource::academicQueueParameters($this->queue)])) => 'Student records',
            $this->getTitle(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.admin.student-profiles.profile')]);
    }

    public function requirements(Schema $schema): Schema
    {
        return $schema->components(collect($this->getCachedRelationManagers())
            ->filter(fn (string $manager): bool => ($manager === ChecklistItemsRelationManager::class && $this->requirementsProfile['hasChecklist'])
                || ($manager === HoldsRelationManager::class && $this->requirementsProfile['hasHolds']))
            ->map(fn (string $manager): Livewire => Livewire::make($manager, [
                'ownerRecord' => $this->getRecord(), 'pageClass' => static::class,
                ...$manager::getDefaultProperties(),
            ])->key($manager))->all());
    }

    protected function getHeaderActions(): array
    {
        return $this->isAcademicHeadReader()
            ? [$this->returnToOversightAction()]
            : [];
    }

    public function recordCorrectionAction(): Action
    {
        return Action::make('recordCorrection')
            ->label('Record factual correction')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => $this->isRegistrarActor())
            ->authorize(fn (): bool => $this->isRegistrarActor())
            ->modalHeading('Record factual correction')
            ->modalDescription('Record an institutional factual correction to this Student Profile. Prior issued outputs remain unchanged.')
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Save correction')
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('first_name')->default(fn (): string => $this->studentProfile()->first_name)->required()->maxLength(255),
                    TextInput::make('middle_name')->default(fn (): ?string => $this->studentProfile()->middle_name)->maxLength(255),
                    TextInput::make('last_name')->default(fn (): string => $this->studentProfile()->last_name)->required()->maxLength(255),
                    DatePicker::make('birth_date')->default(fn () => $this->studentProfile()->birth_date)->native(false),
                    TextInput::make('prior_identifier')->label('LRN / Prior-Education Identifier')->default(fn (): ?string => $this->studentProfile()->prior_identifier)->maxLength(255),
                    Select::make('entry_term_id')
                        ->label('Entry Term')
                        ->options(fn (): array => Term::query()->orderByDesc('starts_on')->pluck('label', 'id')->all())
                        ->default(fn (): ?int => $this->studentProfile()->entry_term_id)
                        ->searchable(),
                    TextInput::make('email')->default(fn (): ?string => $this->studentProfile()->email)->email()->maxLength(255),
                    TextInput::make('phone')->default(fn (): ?string => $this->studentProfile()->phone)->maxLength(255),
                ]),
                Textarea::make('address')->default(fn (): ?string => $this->studentProfile()->address)->maxLength(2000),
                Textarea::make('reason')->required()->maxLength(2000),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                $record = $this->studentProfile();
                abort_unless($actor instanceof User, 403);

                app(RecordStudentProfileCorrection::class)->execute(
                    $record,
                    collect($data)->except(['reason'])->all(),
                    $actor,
                    (string) $data['reason'],
                );
                $record->refresh();
                unset($this->academicProfile);

                Notification::make()
                    ->title('Student Profile correction recorded')
                    ->body('The factual correction is attributable and prior issued outputs remain unchanged.')
                    ->success()
                    ->send();
            });
    }

    public function correctCreditingAction(): Action
    {
        return Action::make('correctCrediting')
            ->label('Correct crediting')
            ->icon('heroicon-o-academic-cap')
            ->color('gray')
            ->size('sm')
            ->authorize(fn (): bool => auth()->user() instanceof User && auth()->user()->canAuthenticate() && auth()->user()->hasRole(User::StaffRoleRegistrar))
            ->visible(fn (): bool => auth()->user() instanceof User && auth()->user()->canAuthenticate() && auth()->user()->hasRole(User::StaffRoleRegistrar)
                && RegistrationCreditEvaluation::effectiveForStudent($this->studentProfile()) !== null)
            ->modalHeading('Correct transfer crediting')
            ->modalDescription('Record a corrected crediting. It counts immediately for the study plan, enrollment and TOR; the earlier crediting stays in history. Subjects already passed or being taken at SIA are marked.')
            ->modalWidth(Width::Screen)->extraModalWindowAttributes(['class' => 'tala-crediting-modal'])
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Save crediting')
            ->fillForm(function (): array {
                $current = RegistrationCreditEvaluation::effectiveForStudent($this->studentProfile());
                $this->reviewedCreditEvaluationId = $current?->id;

                return [
                    'same_curriculum' => TransferCreditingForm::sameCurriculumState($current),
                    'rows' => TransferCreditingForm::rowsState($current?->curriculum_version_id, $current, $current?->same_curriculum === true),
                    'authority_reference' => null,
                    'approved_on' => null,
                ];
            })
            ->schema(fn (): array => TransferCreditingForm::withTorPreview(
                TransferCreditingForm::components(fn (): ?int => $this->studentProfile()->curriculum_version_id, $this->studentProfile()),
                RegistrationCreditEvaluation::effectiveForStudent($this->studentProfile())?->enrollment?->admissionApplication,
            ))
            ->action(function (array $data, Action $action): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $lines = TransferCreditingForm::creditLines($data);
                try {
                    app(CorrectRegistrationCreditEvaluation::class)->execute($this->studentProfile(), $actor, $lines,
                        TransferCreditingForm::sameCurriculum($data), (string) $data['authority_reference'],
                        CarbonImmutable::parse($data['approved_on']), (int) $this->reviewedCreditEvaluationId);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Crediting was not saved')->body(collect($exception->errors())->flatten()->first())->send();
                    $action->halt();
                }
                unset($this->academicProfile);
                Notification::make()->success()->title('Crediting corrected')
                    ->body(count($lines) === 0 ? 'No subjects are credited now.' : count($lines).' subject(s) credited.')->send();
            });
    }

    private function studentProfile(): StudentProfile
    {
        $record = $this->getRecord();
        abort_unless($record instanceof StudentProfile, 404);

        return $record;
    }

    public function recordAcademicDecisionAction(): Action
    {
        return StudentAcademicActions::recordAcademicDecision($this->record)
            ->visible(fn (): bool => $this->isRegistrarActor())
            ->authorize(fn (): bool => $this->isRegistrarActor());
    }

    public function confirmIrregularAction(): Action
    {
        return IrregularReviewActions::confirm(fn (): StudentProfile => $this->studentProfile());
    }

    public function keepRegularAction(): Action
    {
        return IrregularReviewActions::keepRegular(fn (): StudentProfile => $this->studentProfile());
    }

    /**
     * Irregular status and its named reasons for the Registrar record view (#99 Scope 11).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function irregularReview(): array
    {
        $reasons = app(IrregularStudentReasons::class);

        return [
            ...$reasons->forStudent($this->studentProfile()),
            'needs_review' => $reasons->needsReview($this->studentProfile()),
        ];
    }

    public function recordExternalCompetencyAction(): Action
    {
        return StudentAcademicActions::make($this->record)[0]
            ->visible(fn (): bool => $this->isRegistrarActor() && $this->hasExternalRequirement())
            ->authorize(fn (): bool => $this->isRegistrarActor());
    }

    private function hasExternalRequirement(): bool
    {
        return ExternalCompetencyRequirement::query()->applicableTo($this->record)->exists();
    }

    public function recordPriorSubjectsAction(): Action
    {
        return Action::make('recordPriorSubjects')
            ->label('Manage prior subjects')
            ->icon('heroicon-o-clipboard-document-list')
            ->color(fn (): string => $this->needsInitialPriorRecord() ? 'primary' : 'gray')
            ->size('sm')
            ->visible(fn (): bool => $this->isRegistrarActor() && $this->studentProfile()->curriculum_version_id !== null)
            ->authorize(fn (): bool => $this->isRegistrarActor())
            ->modalHeading('Manage prior curriculum subjects')
            ->modalDescription('Select subjects the student passed or received credit for before TALA, grouped by Year Level and Term. Historical failed, INC, or dropped records are preserved and never checked or revoked here.')
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Save prior subjects')
            ->fillForm(function (): array {
                $student = $this->studentProfile();
                $groups = $this->groupedCurriculumEntries($student);

                $activePassingEntryIds = PriorAcademicCredit::query()
                    ->active()
                    ->where('student_profile_id', $student->id)
                    ->whereNotNull('curriculum_entry_id')
                    ->get()
                    ->filter(fn (PriorAcademicCredit $credit): bool => $credit->isPassing())
                    ->pluck('curriculum_entry_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $termEntries = [];
                foreach ($groups as $groupKey => $group) {
                    $groupEntryIds = $group['entries']->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
                    $termEntries[$groupKey] = array_values(array_intersect($groupEntryIds, $activePassingEntryIds));
                }

                $latestPrior = PriorAcademicCredit::query()
                    ->active()
                    ->where('student_profile_id', $student->id)
                    ->latest('id')
                    ->first();

                return [
                    'quick_credit_through' => null,
                    'academic_year_label' => $latestPrior?->academic_year_label ?: 'Prior Institutional Record',
                    'is_external_transfer' => false,
                    'source_school' => $latestPrior?->source_school,
                    'term_entries' => $termEntries,
                ];
            })
            ->schema(function (): array {
                $student = $this->studentProfile();
                $groups = $this->groupedCurriculumEntries($student);
                $lockedEntryIds = $this->nativePassedCurriculumEntryIds($student);

                $activePriorByEntry = PriorAcademicCredit::query()
                    ->active()
                    ->where('student_profile_id', $student->id)
                    ->whereNotNull('curriculum_entry_id')
                    ->get()
                    ->groupBy('curriculum_entry_id');

                $termSections = [];
                foreach ($groups as $groupKey => $group) {
                    $options = [];
                    $descriptions = [];

                    /** @var CurriculumEntry $entry */
                    foreach ($group['entries'] as $entry) {
                        $spec = $entry->courseSpecification;
                        $entryId = (int) $entry->id;
                        $isLocked = in_array($entryId, $lockedEntryIds, true);
                        $units = $spec?->credit_units !== null
                            ? rtrim(rtrim(number_format((float) $spec->credit_units, 2, '.', ''), '0'), '.')
                            : '0';

                        $options[$entryId] = collect([$spec?->course?->code, $spec?->title])->filter()->implode(' · ');

                        $unitSummary = "{$units} ".((float) $units === 1.0 ? 'unit' : 'units');
                        $notes = [$unitSummary];
                        if ($isLocked) {
                            $notes[] = 'Passed in official SIA grade roster (locked)';
                        }

                        /** @var Collection<int, PriorAcademicCredit> $priorForEntry */
                        $priorForEntry = $activePriorByEntry->get($entryId, collect());
                        $nonPassingPrior = $priorForEntry->first(fn (PriorAcademicCredit $credit): bool => ! $credit->isPassing());
                        $passingPrior = $priorForEntry->first(fn (PriorAcademicCredit $credit): bool => $credit->isPassing());

                        if ($nonPassingPrior instanceof PriorAcademicCredit) {
                            $code = $nonPassingPrior->effectiveResultCode();
                            $notes[] = "Active prior {$code} (preserved — edit via Record or edit prior grade)";
                        } elseif ($passingPrior instanceof PriorAcademicCredit && ! $isLocked) {
                            $code = $passingPrior->effectiveResultCode();
                            $sourceTag = $passingPrior->isExternalTransfer() ? 'External transfer' : 'Pre-TALA';
                            $notes[] = "{$sourceTag} · {$code}";
                        }

                        $descriptions[$entryId] = implode(' · ', $notes);
                    }

                    $entryCount = $group['entries']->count();
                    $groupUnits = rtrim(rtrim(number_format((float) $group['units'], 2, '.', ''), '0'), '.');

                    $termSections[] = Section::make($group['label'])
                        ->description("{$entryCount} ".Str::plural('subject', $entryCount)." · {$groupUnits} units")
                        ->compact()
                        ->collapsible()
                        ->schema([
                            CheckboxList::make("term_entries.{$groupKey}")
                                ->hiddenLabel()
                                ->options($options)
                                ->descriptions($descriptions)
                                ->searchable()
                                ->searchPrompt('Search by subject code or title...')
                                ->disableOptionWhen(fn (string $value): bool => in_array((int) $value, $lockedEntryIds, true))
                                ->bulkToggleable()
                                ->selectAllAction(fn (Action $action): Action => $action->label('Mark entire term passed'))
                                ->deselectAllAction(fn (Action $action): Action => $action->label('Clear term'))
                                ->columns(['default' => 1, 'md' => 2])
                                ->gridDirection(GridDirection::Row)
                                ->extraAttributes(['class' => 'tala-card-picker']),
                        ]);
                }

                return [
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Select::make('quick_credit_through')
                            ->label('Quick-credit all terms through (optional)')
                            ->placeholder('None — choose by term below')
                            ->options(fn (): array => StudentOnboardingActions::completedThroughOptions($student->curriculum_version_id))
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state) use ($groups, $lockedEntryIds): void {
                                if (blank($state)) {
                                    return;
                                }

                                [$targetYear, $targetTermType] = StudentOnboardingActions::parseCompletedThroughValue((string) $state);
                                if ($targetYear === null || $targetTermType === null) {
                                    return;
                                }

                                $targetRank = $this->termTypeRank($targetTermType);
                                foreach ($groups as $groupKey => $group) {
                                    $groupYear = (int) $group['year_rank'];
                                    $groupTermRank = (int) $group['term_rank'];
                                    $withinRange = $groupYear < $targetYear
                                        || ($groupYear === $targetYear && $groupTermRank <= $targetRank);

                                    if (! $withinRange) {
                                        continue;
                                    }

                                    $selectableIds = $group['entries']
                                        ->pluck('id')
                                        ->map(fn (mixed $id): int => (int) $id)
                                        ->reject(fn (int $id): bool => in_array($id, $lockedEntryIds, true))
                                        ->values()
                                        ->all();

                                    $set("term_entries.{$groupKey}", $selectableIds);
                                }
                            })
                            ->helperText('Automatically checks every non-locked subject through the selected term. You can still uncheck individual exceptions below.'),
                        TextInput::make('academic_year_label')
                            ->label('Academic Year label')
                            ->default('Prior Institutional Record')
                            ->maxLength(120),
                        Toggle::make('is_external_transfer')
                            ->label('Record newly checked subjects as external transfer credits (TC)')
                            ->default(false)
                            ->live()
                            ->helperText('External transfer credits display as TC on the TOR and are excluded from SIA Cumulative GWA.'),
                        TextInput::make('source_school')
                            ->label('Source school')
                            ->placeholder('e.g. Prior campus or transfer institution')
                            ->required(fn (Get $get): bool => (bool) $get('is_external_transfer'))
                            ->maxLength(255),
                    ]),
                    ...$termSections,
                ];
            })
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $student = $this->studentProfile();

                $lockedEntryIds = $this->nativePassedCurriculumEntryIds($student);
                $ayLabel = filled($data['academic_year_label'] ?? null) ? (string) $data['academic_year_label'] : null;
                $sourceSchool = filled($data['source_school'] ?? null) ? (string) $data['source_school'] : null;
                $isExternalTransfer = (bool) ($data['is_external_transfer'] ?? false);

                $selectedIds = collect((array) ($data['term_entries'] ?? []))
                    ->flatten()
                    ->concat((array) ($data['selected_entry_ids'] ?? []))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->reject(fn (int $id): bool => in_array($id, $lockedEntryIds, true))
                    ->values()
                    ->all();

                if ($selectedIds === [] && filled($data['quick_credit_through'] ?? null)) {
                    [$yearLevel, $termType] = StudentOnboardingActions::parseCompletedThroughValue((string) $data['quick_credit_through']);
                    if ($yearLevel !== null && $termType !== null) {
                        $summary = app(RecordPriorAcademicCredits::class)->applyCompletedThroughTerm(
                            profile: $student,
                            yearLevel: $yearLevel,
                            termType: $termType,
                            actor: $actor,
                            academicYearLabel: $ayLabel,
                            sourceSchool: $sourceSchool,
                        );

                        $student->refresh();
                        unset($this->academicProfile, $this->studentTerms);

                        Notification::make()
                            ->title('Prior cohort credits applied')
                            ->body("Recorded {$summary['created']} prior subject credit(s) through Year {$yearLevel}.")
                            ->success()
                            ->send();

                        return;
                    }
                }

                $summary = app(RecordPriorAcademicCredits::class)->syncChecklistCredits(
                    profile: $student,
                    selectedEntryIds: $selectedIds,
                    actor: $actor,
                    academicYearLabel: $ayLabel,
                    sourceSchool: $sourceSchool,
                    isExternalTransfer: $isExternalTransfer,
                );

                $student->refresh();
                unset($this->academicProfile, $this->studentTerms);

                Notification::make()
                    ->title('Prior academic record updated')
                    ->body("Added {$summary['added']} and removed {$summary['revoked']} prior subject credit(s).")
                    ->success()
                    ->send();
            });
    }

    public function upsertPriorCreditAction(): Action
    {
        return Action::make('upsertPriorCredit')
            ->label('Record or edit prior grade')
            ->icon('heroicon-o-plus-circle')
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => $this->isRegistrarActor())
            ->authorize(fn (): bool => $this->isRegistrarActor())
            ->modalHeading(fn (array $arguments): string => filled($arguments['prior_credit_id'] ?? null)
                ? 'Edit prior academic record'
                : 'Record prior academic grade')
            ->modalDescription('Record or update an individual Servitech pre-TALA grade or external transfer credit. Servitech numeric grades count in SIA Cumulative GWA; external transfer credits display as TC and are excluded from SIA GWA.')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Save prior record')
            ->fillForm(function (array $arguments): array {
                $student = $this->studentProfile();
                $creditId = filled($arguments['prior_credit_id'] ?? null) ? (int) $arguments['prior_credit_id'] : null;

                if ($creditId !== null) {
                    $credit = PriorAcademicCredit::query()
                        ->where('student_profile_id', $student->id)
                        ->where('state', PriorAcademicCredit::StateActive)
                        ->find($creditId);

                    if ($credit instanceof PriorAcademicCredit) {
                        return [
                            'id' => $credit->id,
                            'course_specification_id' => $credit->course_specification_id,
                            'result_status' => $credit->result_status,
                            'final_grade' => $credit->final_grade ?? $credit->effectiveResultCode(),
                            'is_external_transfer' => $credit->isExternalTransfer(),
                            'source_school' => $credit->source_school,
                            'academic_year_label' => $credit->academic_year_label ?? 'Prior Institutional Record',
                            'term_label' => $credit->term_label,
                            'year_level' => $credit->year_level,
                        ];
                    }
                }

                return [
                    'id' => null,
                    'course_specification_id' => null,
                    'result_status' => PriorAcademicCredit::ResultPassed,
                    'final_grade' => null,
                    'is_external_transfer' => false,
                    'source_school' => null,
                    'academic_year_label' => 'Prior Institutional Record',
                    'term_label' => null,
                    'year_level' => null,
                ];
            })
            ->schema(function (): array {
                $student = $this->studentProfile();

                return [
                    Hidden::make('id'),
                    Select::make('course_specification_id')
                        ->label('Subject')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => $this->priorCreditSpecificationOptions($student))
                        ->getSearchResultsUsing(fn (string $search): array => $this->priorCreditSpecificationOptions($student, $search))
                        ->getOptionLabelUsing(function (mixed $value): ?string {
                            $spec = CourseSpecification::query()->with('course')->find($value);
                            if (! $spec instanceof CourseSpecification) {
                                return null;
                            }

                            return $this->formatCourseSpecificationOption($spec);
                        })
                        ->helperText('Choose a subject from the student’s curriculum or any Active / Retired catalog course specification (Draft specifications are excluded).'),
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Select::make('result_status')
                            ->label('Result status')
                            ->required()
                            ->live()
                            ->options(PriorAcademicCredit::resultStatusOptions())
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                $currentGrade = strtoupper(trim((string) ($get('final_grade') ?? '')));
                                if ($state === PriorAcademicCredit::ResultInc) {
                                    $set('final_grade', 'INC');
                                } elseif ($state === PriorAcademicCredit::ResultDropped) {
                                    $set('final_grade', 'DRP');
                                } elseif ($state === PriorAcademicCredit::ResultFailed && ($currentGrade === '' || ! is_numeric($currentGrade) || (float) $currentGrade <= 4.00)) {
                                    $set('final_grade', '5.00');
                                } elseif ($state === PriorAcademicCredit::ResultCredited && in_array($currentGrade, ['', 'INC', 'DRP', '5.00'], true)) {
                                    $set('final_grade', (bool) $get('is_external_transfer') ? 'TC' : 'CREDIT');
                                } elseif ($state === PriorAcademicCredit::ResultPassed && in_array($currentGrade, ['INC', 'DRP', '5.00', 'CREDIT', 'TC'], true)) {
                                    $set('final_grade', '');
                                }
                            }),
                        TextInput::make('final_grade')
                            ->label('Final grade / result code')
                            ->placeholder('e.g. 1.75, 5.00, CREDIT, TC, INC, DRP')
                            ->maxLength(20)
                            ->extraInputAttributes([
                                'autocorrect' => 'off',
                                'autocapitalize' => 'characters',
                                'spellcheck' => 'false',
                            ])
                            ->helperText('Numeric 1.00–4.00 (passed), 5.00 (failed), CREDIT / TC, INC, or DRP.'),
                    ]),
                    Toggle::make('is_external_transfer')
                        ->label('External transfer credit (TC — excluded from SIA Cumulative GWA)')
                        ->default(false)
                        ->live()
                        ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                            if ((bool) $state && $get('result_status') === PriorAcademicCredit::ResultCredited) {
                                $grade = strtoupper(trim((string) ($get('final_grade') ?? '')));
                                if ($grade === '' || $grade === 'CREDIT') {
                                    $set('final_grade', 'TC');
                                }
                            }
                        })
                        ->helperText('Leave off for Servitech pre-TALA records (numeric grades count in SIA GWA). Turn on for credits transferred from another school.'),
                    TextInput::make('source_school')
                        ->label('Source school')
                        ->placeholder('e.g. Previous college or university (required for external transfer)')
                        ->required(fn (Get $get): bool => (bool) $get('is_external_transfer'))
                        ->maxLength(255),
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        TextInput::make('academic_year_label')
                            ->label('Academic Year')
                            ->placeholder('e.g. AY 2023-2024')
                            ->maxLength(120),
                        TextInput::make('term_label')
                            ->label('Term label')
                            ->placeholder('e.g. First Semester')
                            ->maxLength(120),
                        Select::make('year_level')
                            ->label('Year level')
                            ->placeholder('Auto from curriculum')
                            ->options([
                                1 => 'Year 1',
                                2 => 'Year 2',
                                3 => 'Year 3',
                                4 => 'Year 4',
                                5 => 'Year 5',
                            ]),
                    ]),
                ];
            })
            ->action(function (array $data, Action $action): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $student = $this->studentProfile();

                try {
                    $credit = app(RecordPriorAcademicCredits::class)->upsertPriorCredit($student, $data, $actor);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Prior academic record was not saved')
                        ->body(collect($exception->errors())->flatten()->first())
                        ->send();
                    $action->halt();
                }

                $student->refresh();
                $this->academicPeriod = $credit->periodKey();
                $this->academicTerm = $credit->term_id;
                $this->academicResult = 'pc:'.$credit->id;
                unset($this->academicProfile, $this->studentTerms);

                Notification::make()
                    ->title(filled($data['id'] ?? null) ? 'Prior academic record updated' : 'Prior academic grade recorded')
                    ->body('Recorded '.$credit->effectiveResultCode().' for '.($credit->courseSpecification?->course?->code ?? 'subject').'.')
                    ->success()
                    ->send();
            });
    }

    public function settlePriorIncAction(): Action
    {
        return Action::make('settlePriorInc')
            ->label('Settle prior INC')
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->size('sm')
            ->visible(fn (): bool => $this->isRegistrarActor())
            ->authorize(fn (): bool => $this->isRegistrarActor())
            ->modalHeading('Settle pre-TALA INC record')
            ->modalDescription('Record the final completed grade (1.00–4.00) or lapsed failing grade (5.00). Cumulative GWA, study plan, and graduation readiness update immediately.')
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel('Settle INC')
            ->fillForm(fn (array $arguments): array => [
                'prior_credit_id' => filled($arguments['prior_credit_id'] ?? null) ? (int) $arguments['prior_credit_id'] : null,
                'result_status' => PriorAcademicCredit::ResultPassed,
                'final_grade' => null,
                'reason' => null,
            ])
            ->schema(function (): array {
                $student = $this->studentProfile();
                $incOptions = PriorAcademicCredit::query()
                    ->active()
                    ->where('student_profile_id', $student->id)
                    ->with('courseSpecification.course')
                    ->get()
                    ->filter(fn (PriorAcademicCredit $credit): bool => $credit->result_status === PriorAcademicCredit::ResultInc
                        || $credit->effectiveResultCode() === 'INC')
                    ->mapWithKeys(fn (PriorAcademicCredit $credit): array => [
                        $credit->id => collect([
                            $credit->courseSpecification?->course?->code,
                            $credit->courseSpecification?->title,
                            $credit->displayAcademicYearLabel(),
                        ])->filter()->implode(' · '),
                    ])
                    ->all();

                return [
                    Select::make('prior_credit_id')
                        ->label('Prior INC subject')
                        ->required()
                        ->options($incOptions),
                    Select::make('result_status')
                        ->label('Resolved outcome')
                        ->required()
                        ->live()
                        ->options([
                            PriorAcademicCredit::ResultPassed => 'Passed (1.00–4.00)',
                            PriorAcademicCredit::ResultFailed => 'Failed / Lapsed (5.00)',
                            PriorAcademicCredit::ResultCredited => 'Credited (CREDIT / TC)',
                            PriorAcademicCredit::ResultDropped => 'Dropped (DRP)',
                        ])
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            if ($state === PriorAcademicCredit::ResultFailed) {
                                $set('final_grade', '5.00');
                            } elseif ($state === PriorAcademicCredit::ResultDropped) {
                                $set('final_grade', 'DRP');
                            } elseif ($state === PriorAcademicCredit::ResultCredited) {
                                $set('final_grade', 'CREDIT');
                            }
                        }),
                    TextInput::make('final_grade')
                        ->label('Final grade')
                        ->required()
                        ->placeholder('e.g. 1.75 or 5.00')
                        ->maxLength(20)
                        ->extraInputAttributes([
                            'autocorrect' => 'off',
                            'autocapitalize' => 'characters',
                            'spellcheck' => 'false',
                        ]),
                    Textarea::make('reason')
                        ->label('Completion note (optional)')
                        ->placeholder('e.g. Completion form submitted to Registrar Office')
                        ->rows(2)
                        ->maxLength(500),
                ];
            })
            ->action(function (array $data, Action $action): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $student = $this->studentProfile();

                $credit = PriorAcademicCredit::query()
                    ->where('student_profile_id', $student->id)
                    ->where('state', PriorAcademicCredit::StateActive)
                    ->findOrFail((int) $data['prior_credit_id']);

                try {
                    $settled = app(RecordPriorAcademicCredits::class)->settlePriorInc(
                        credit: $credit,
                        finalGrade: (string) $data['final_grade'],
                        actor: $actor,
                        resultStatus: filled($data['result_status'] ?? null) ? (string) $data['result_status'] : null,
                        reason: filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Prior INC was not settled')
                        ->body(collect($exception->errors())->flatten()->first())
                        ->send();
                    $action->halt();
                }

                $student->refresh();
                $this->academicPeriod = $settled->periodKey();
                $this->academicResult = 'pc:'.$settled->id;
                unset($this->academicProfile, $this->studentTerms);

                Notification::make()
                    ->title('Prior INC settled')
                    ->body('Updated prior record to '.$settled->effectiveResultCode().'. GWA and graduation readiness have been refreshed.')
                    ->success()
                    ->send();
            });
    }

    public function revokePriorCreditAction(): Action
    {
        return Action::make('revokePriorCredit')
            ->label('Revoke prior record')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->size('sm')
            ->visible(fn (): bool => $this->isRegistrarActor())
            ->authorize(fn (): bool => $this->isRegistrarActor())
            ->modalHeading('Revoke prior academic credit')
            ->modalDescription('Revoking removes this credit from active study plan, GWA, and TOR projections while preserving its immutable audit record.')
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel('Revoke record')
            ->fillForm(fn (array $arguments): array => [
                'prior_credit_id' => filled($arguments['prior_credit_id'] ?? null) ? (int) $arguments['prior_credit_id'] : null,
                'reason' => null,
            ])
            ->schema([
                Hidden::make('prior_credit_id')->required(),
                Textarea::make('reason')
                    ->label('Reason for revoking')
                    ->placeholder('Explain why this prior academic credit is being revoked.')
                    ->required()
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $student = $this->studentProfile();

                $credit = PriorAcademicCredit::query()
                    ->where('student_profile_id', $student->id)
                    ->where('state', PriorAcademicCredit::StateActive)
                    ->findOrFail((int) $data['prior_credit_id']);

                app(RecordPriorAcademicCredits::class)->revokePriorCredit(
                    credit: $credit,
                    actor: $actor,
                    reason: (string) ($data['reason'] ?? ''),
                );

                $student->refresh();
                $this->academicResult = null;
                unset($this->academicProfile, $this->studentTerms);

                Notification::make()
                    ->title('Prior academic credit revoked')
                    ->body('The prior credit was revoked and academic projections have been updated.')
                    ->success()
                    ->send();
            });
    }

    public function resendActivationAction(): Action
    {
        return Action::make('resendActivation')
            ->label('Send / Resend activation email')
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->size('sm')
            ->visible(function (): bool {
                $student = $this->studentProfile();
                $student->loadMissing('user');

                return $this->isRegistrarActor() && StudentAccountActivation::canResendActivation($student);
            })
            ->authorize(fn (): bool => $this->isRegistrarActor())
            ->requiresConfirmation()
            ->modalHeading('Send activation email')
            ->modalDescription(fn (): string => 'Send a 7-day Servitech Student Hub activation link to '.($this->studentProfile()->user?->email ?? $this->studentProfile()->email ?? 'this student').'.')
            ->modalSubmitActionLabel('Send activation email')
            ->action(function (): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $student = $this->studentProfile();

                $sent = app(StudentAccountActivation::class)->sendActivationEmail($student, $actor);
                $student->refresh()->loadMissing('user');
                unset($this->academicProfile);

                if ($sent) {
                    Notification::make()
                        ->title('Activation email sent')
                        ->body('A 7-day activation link was sent to '.($student->user?->email ?? $student->email).'.')
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Activation email could not be sent')
                        ->body('Please verify mail delivery configuration or try again shortly.')
                        ->warning()
                        ->send();
                }
            });
    }

    public function correctActivationEmailAction(): Action
    {
        return Action::make('correctActivationEmail')
            ->label('Correct email & resend')
            ->icon('heroicon-o-at-symbol')
            ->color('gray')
            ->size('sm')
            ->visible(function (): bool {
                $student = $this->studentProfile();
                $student->loadMissing('user');

                return $this->isRegistrarActor() && StudentAccountActivation::canCorrectUnactivatedEmail($student);
            })
            ->authorize(fn (): bool => $this->isRegistrarActor())
            ->modalHeading('Correct email & resend activation')
            ->modalDescription('Update the email address on this unactivated student account and immediately send a fresh activation link.')
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel('Save & resend activation')
            ->fillForm(fn (): array => [
                'email' => $this->studentProfile()->user?->email ?? $this->studentProfile()->email ?? '',
            ])
            ->schema([
                TextInput::make('email')
                    ->label('Student Email')
                    ->email()
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $student = $this->studentProfile();

                $sent = app(StudentAccountActivation::class)->correctEmailAndResend(
                    $student,
                    (string) $data['email'],
                    $actor,
                );
                $student->refresh()->loadMissing('user');
                unset($this->academicProfile);

                Notification::make()
                    ->title($sent ? 'Email updated & activation sent' : 'Email updated')
                    ->body($sent
                        ? 'Updated student email and sent a fresh activation link to '.$student->email.'.'
                        : 'Updated student email, but activation delivery returned a warning.')
                    ->status($sent ? 'success' : 'warning')
                    ->send();
            });
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function academicProfile(): array
    {
        /** @var StudentProfile $student */
        $student = $this->getRecord();
        $student->loadMissing(['user', 'program', 'curriculumVersion']);
        $resolver = app(EnrollmentAcademicContextResolver::class);
        $enrollment = $resolver->currentEnrollmentForProfile($student);
        $context = $enrollment ? $resolver->forEnrollment($enrollment) : null;
        $results = $this->activeRecordTab() === 'academic'
            ? app(OfficialCourseResultProjection::class)->forStudent($student) : collect();
        $terms = $this->studentTerms;

        $periodEntries = collect();
        foreach ($terms as $term) {
            $key = 'term:'.(int) $term->id;
            $periodEntries->put($key, [
                'key' => $key,
                'label' => $this->termLabel($term),
                'short_label' => (string) $term->label,
                'term' => $term,
                'sort_key' => '1|'.($term->starts_on?->toDateString() ?? '0000-00-00').'|'.sprintf('%010d', (int) $term->id),
            ]);
        }

        foreach ($results as $result) {
            $key = (string) ($result['period_key'] ?? ('term:'.(int) $result['term']?->id));
            if ($periodEntries->has($key)) {
                continue;
            }

            $term = $result['term'] ?? null;
            if ($term instanceof Term) {
                $periodEntries->put($key, [
                    'key' => $key,
                    'label' => $this->termLabel($term),
                    'short_label' => (string) $term->label,
                    'term' => $term,
                    'sort_key' => '1|'.($term->starts_on?->toDateString() ?? '0000-00-00').'|'.sprintf('%010d', (int) $term->id),
                ]);

                continue;
            }

            $yearLabel = $this->yearLevelDisplay($result['year_level'] ?? null);
            $ayLabel = filled($result['academic_year_label'] ?? null) ? (string) $result['academic_year_label'] : null;
            $termLabel = filled($result['period_label'] ?? null) ? (string) $result['period_label'] : 'Prior Term';
            $fullLabel = collect([$yearLabel, $ayLabel, $termLabel])->filter()->implode(' · ');

            $periodEntries->put($key, [
                'key' => $key,
                'label' => $fullLabel !== '' ? $fullLabel : $termLabel,
                'short_label' => $termLabel,
                'term' => null,
                'sort_key' => (string) ($result['period_sort_key'] ?? '0|0000'),
            ]);
        }

        $periodEntries = $periodEntries->sortByDesc('sort_key');
        $selectedPeriodKey = null;
        if ($this->academicPeriod !== null && $periodEntries->has($this->academicPeriod)) {
            $selectedPeriodKey = $this->academicPeriod;
        } elseif ($this->academicTerm !== null && $periodEntries->has('term:'.$this->academicTerm)) {
            $selectedPeriodKey = 'term:'.$this->academicTerm;
        } else {
            $selectedPeriodKey = $periodEntries->keys()->first();
        }

        $selectedPeriod = $selectedPeriodKey !== null ? $periodEntries->get($selectedPeriodKey) : null;
        $selectedTerm = $selectedPeriod['term'] ?? null;

        $periodResults = $results->filter(fn (array $result): bool => $selectedPeriodKey !== null
            && (string) ($result['period_key'] ?? ('term:'.(int) $result['term']?->id)) === $selectedPeriodKey);

        (new EloquentCollection($periodResults->pluck('course_enrollment')->filter()->all()))
            ->loadMissing(['gradeRosterRow.roster', 'gradeRosterRow.outcomeEvents.completionSubmissions']);

        $released = $periodResults->filter(fn (array $result): bool => $result['outcome_source'] !== null)
            ->map(fn (array $result): array => $this->resultLinks($result));
        $search = Str::lower(trim($this->academicSearch));
        $filtered = $released->filter(fn (array $result): bool => $search === '' || Str::contains(
            Str::lower(collect([$result['course_specification']?->course?->code, $result['course_specification']?->title])->filter()->implode(' ')), $search,
        ))->values();
        $pagedResults = $this->paginateRows($filtered, $this->academicPage, 'academicPage');

        $selectedResult = null;
        if ($this->academicResult === null) {
            $selectedResult = $pagedResults->getCollection()->first();
        } else {
            $targetKey = is_int($this->academicResult) || ctype_digit((string) $this->academicResult)
                ? 'ce:'.(int) $this->academicResult
                : (string) $this->academicResult;
            $selectedResult = $filtered->first(fn (array $result): bool => $result['result_key'] === $targetKey);
        }

        $effect = app(AcademicEnrollmentGuidance::class)->forStudent($student);
        $accountingWait = ($context['responsible_office'] ?? null) === 'Accounting Office';
        $handoff = $enrollment?->canonical_outcome === Enrollment::OutcomeInProgress ? [
            'currentOwner' => $context['responsible_office'],
            'currentTask' => $context['next_action'],
            'nextOwner' => $accountingWait ? 'Registrar' : null,
            'nextTask' => $accountingWait ? 'Continue enrollment when cleared.' : null,
        ] : null;
        $requirements = $this->activeRecordTab() === 'academic'
            ? ExternalCompetencyRequirement::query()->applicableTo($student)
                ->withCurrentResultFor($student)->orderBy('requirement_code')->get()
            : collect();

        $activePriorIncCount = $this->activeRecordTab() === 'academic'
            ? $results->filter(fn (array $result): bool => ($result['prior_credit'] ?? null) instanceof PriorAcademicCredit
                && ($result['result'] ?? null) === 'INC')->count()
            : 0;

        return [
            'student' => $student, 'enrollment' => $enrollment, 'context' => $context, 'handoff' => $handoff,
            'termOptions' => $terms->mapWithKeys(fn (Term $term): array => [$term->id => $this->termLabel($term)])->all(),
            'periodOptions' => $periodEntries->mapWithKeys(fn (array $entry): array => [$entry['key'] => $entry['label']])->all(),
            'selectedPeriodKey' => $selectedPeriodKey,
            'selectedPeriodLabel' => $selectedPeriod['label'] ?? null,
            'selectedPeriodShortLabel' => $selectedPeriod['short_label'] ?? 'No enrolled term',
            'selectedTerm' => $selectedTerm,
            'results' => $pagedResults,
            'selectedResult' => $selectedResult,
            'activePriorIncCount' => $activePriorIncCount,
            'termAverage' => $selectedPeriodKey !== null && $this->activeRecordTab() === 'academic'
                ? ($selectedTerm instanceof Term
                    ? app(TermWeightedAverageProjection::class)->forResults($results, $selectedTerm)
                    : app(TermWeightedAverageProjection::class)->forPeriodResults($periodResults, (string) ($selectedPeriod['short_label'] ?? 'Prior Term')))
                : null,
            'termLabel' => collect([$enrollment?->term?->label, $enrollment?->term?->academicYear?->label])->filter()->implode(' · '),
            'enrollmentUrl' => $enrollment && EnrollmentResource::canView($enrollment)
                ? EnrollmentResource::getUrl('view', ['record' => $enrollment, ...$this->oversightQueryParameters()]) : null,
            'cumulative' => $this->activeRecordTab() === 'academic' ? app(CumulativeGwaProjection::class)->forStudent($student) : null,
            'effect' => $effect,
            'guidance' => $effect['label'],
            'releasedCount' => $results->filter(fn (array $result): bool => $result['outcome_source'] !== null)->count(),
            'holds' => Hold::query()->where('student_profile_id', $student->id)->where('status', Hold::StatusActive)->get(),
            'hasEnrollments' => $this->activeRecordTab() === 'enrollment' && $student->enrollments()
                ->when($enrollment, fn ($query) => $query->whereKeyNot($enrollment->id))->exists(),
            'hasAssessments' => $this->activeRecordTab() === 'history' && Assessment::query()
                ->whereHas('enrollment', fn ($query) => $query->where('student_profile_id', $student->id))->exists(),
            'hasLifecycleChanges' => $this->activeRecordTab() === 'history' && StudentLifecycleChange::query()
                ->where('student_profile_id', $student->id)->exists(),
            'curriculum' => $this->activeRecordTab() === 'academic' && $student->curriculumVersion !== null
                ? app(CurriculumEvaluation::class)->forStudent($student) : null,
            'averages' => $this->activeRecordTab() === 'academic' ? $results->pluck('term')->filter()->unique('id')
                ->sortByDesc('ends_on')->map(fn (Term $term): array => ['term' => $term,
                    'average' => app(TermWeightedAverageProjection::class)->forResults($results, $term)]) : collect(),
            'externalRequirements' => $requirements,
        ];
    }

    /** @return Collection<int, Term> */
    #[Computed]
    public function studentTerms(): Collection
    {
        return Term::query()->whereIn('id', $this->record->enrollments()->select('term_id'))
            ->with('academicYear')->orderByDesc('starts_on')->orderByDesc('id')->get();
    }

    private function termLabel(?Term $term): string
    {
        return collect([$term?->academicYear?->label, $term?->label])->filter()->implode(' · ');
    }

    private function yearLevelDisplay(mixed $yearLevel): ?string
    {
        $raw = trim((string) ($yearLevel ?? ''));
        if ($raw === '' || $raw === '0') {
            return null;
        }

        return match ($raw) {
            '1', '1st Year', 'First Year' => 'First Year',
            '2', '2nd Year', 'Second Year' => 'Second Year',
            '3', '3rd Year', 'Third Year' => 'Third Year',
            '4', '4th Year', 'Fourth Year' => 'Fourth Year',
            default => ctype_digit($raw) ? 'Year '.$raw : $raw,
        };
    }

    private function needsInitialPriorRecord(): bool
    {
        $student = $this->studentProfile();

        return $student->applicant_intake_id === null
            && ! $student->enrollments()->exists()
            && ! $student->priorAcademicCredits()->active()->exists();
    }

    /**
     * Group the student's curriculum entries chronologically by Year Level and Term.
     *
     * @return array<string, array{label: string, year_rank: int, term_rank: int, units: float, entries: Collection<int, CurriculumEntry>}>
     */
    private function groupedCurriculumEntries(StudentProfile $student): array
    {
        if ($student->curriculum_version_id === null) {
            return [];
        }

        $entries = CurriculumEntry::query()
            ->where('curriculum_version_id', $student->curriculum_version_id)
            ->with('courseSpecification.course')
            ->get()
            ->sortBy(fn (CurriculumEntry $entry): array => [
                $this->yearLevelRank($entry->year_level),
                $this->termTypeRank($entry->term_type, $entry->term_label),
                (int) $entry->sequence,
                (int) $entry->id,
            ])
            ->values();

        $groups = [];
        foreach ($entries as $entry) {
            $yearRank = $this->yearLevelRank($entry->year_level);
            $termRank = $this->termTypeRank($entry->term_type, $entry->term_label);
            $key = 'y'.$yearRank.'_t'.$termRank;

            if (! isset($groups[$key])) {
                $yearLabel = $yearRank < 99 ? 'Year '.$yearRank : ($this->yearLevelDisplay($entry->year_level) ?? 'Year not recorded');
                $termLabel = filled($entry->term_label) ? (string) $entry->term_label : 'Prior Term';
                $groups[$key] = [
                    'label' => $yearLabel.' · '.$termLabel,
                    'year_rank' => $yearRank,
                    'term_rank' => $termRank,
                    'units' => 0.0,
                    'entries' => collect(),
                ];
            }

            $groups[$key]['entries']->push($entry);
            $groups[$key]['units'] += (float) ($entry->courseSpecification?->credit_units ?? 0);
        }

        return $groups;
    }

    /**
     * Curriculum entry IDs already passed in official SIA grade rosters (locked in prior checklist).
     *
     * @return list<int>
     */
    private function nativePassedCurriculumEntryIds(StudentProfile $student): array
    {
        if ($student->curriculum_version_id === null) {
            return [];
        }

        $nativePassedCourseIds = app(OfficialCourseResultProjection::class)
            ->forStudent($student)
            ->filter(fn (array $result): bool => $result['course_enrollment'] instanceof CourseEnrollment
                && RegistrationAcademicEligibilityQuery::isPassingResult($result['result'] ?? null))
            ->pluck('course_specification.course_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        if ($nativePassedCourseIds === []) {
            return [];
        }

        return CurriculumEntry::query()
            ->where('curriculum_version_id', $student->curriculum_version_id)
            ->whereHas('courseSpecification', fn ($query) => $query->whereIn('course_id', $nativePassedCourseIds))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Build selectable non-DRAFT course specifications prioritizing the student's curriculum.
     *
     * @return array<int, string>
     */
    private function priorCreditSpecificationOptions(StudentProfile $student, string $search = ''): array
    {
        $options = [];

        if ($student->curriculum_version_id !== null) {
            $entries = CurriculumEntry::query()
                ->where('curriculum_version_id', $student->curriculum_version_id)
                ->with('courseSpecification.course')
                ->whereHas('courseSpecification', fn ($query) => $query->where('state', '!=', CourseSpecification::StateDraft))
                ->orderBy('year_level')
                ->orderBy('sequence')
                ->orderBy('id')
                ->get();

            foreach ($entries as $entry) {
                $spec = $entry->courseSpecification;
                if (! $spec instanceof CourseSpecification) {
                    continue;
                }

                $label = $this->formatCourseSpecificationOption($spec, $entry);
                if ($search !== '' && ! Str::contains(Str::lower($label), Str::lower($search))) {
                    continue;
                }

                $options[(int) $spec->id] = $label;
            }
        }

        $catalogQuery = CourseSpecification::query()
            ->with('course')
            ->where('state', '!=', CourseSpecification::StateDraft)
            ->when($options !== [], fn ($query) => $query->whereNotIn('id', array_keys($options)))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested->where('title', 'like', '%'.$search.'%')
                    ->orWhereHas('course', fn ($courses) => $courses->where('code', 'like', '%'.$search.'%'));
            }))
            ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 ELSE 1 END")
            ->orderBy('title')
            ->limit($search !== '' ? 50 : 30)
            ->get();

        foreach ($catalogQuery as $spec) {
            $options[(int) $spec->id] = $this->formatCourseSpecificationOption($spec);
        }

        return $options;
    }

    private function formatCourseSpecificationOption(CourseSpecification $spec, ?CurriculumEntry $entry = null): string
    {
        $units = rtrim(rtrim(number_format((float) $spec->credit_units, 2, '.', ''), '0'), '.');
        $period = $entry instanceof CurriculumEntry
            ? collect([
                filled($entry->year_level) ? 'Year '.$entry->year_level : null,
                $entry->term_label,
            ])->filter()->implode(' · ')
            : null;
        $stateTag = $spec->state === CourseSpecification::StateRetired ? 'Retired' : null;

        $meta = collect([
            $period !== '' ? $period : null,
            "{$units} ".((float) $units === 1.0 ? 'unit' : 'units'),
            $stateTag,
        ])->filter()->implode(' · ');

        return collect([$spec->course?->code, $spec->title])->filter()->implode(' · ')
            .($meta !== '' ? " ({$meta})" : '');
    }

    private function yearLevelRank(mixed $yearLevel): int
    {
        $label = strtolower(trim((string) ($yearLevel ?? '')));
        if (preg_match('/\d+/', $label, $match) === 1) {
            return (int) $match[0];
        }

        foreach (['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5] as $word => $rank) {
            if (str_contains($label, $word)) {
                return $rank;
            }
        }

        return 99;
    }

    private function termTypeRank(?string $termType, ?string $termLabel = null): int
    {
        if ($termType !== null) {
            $mapped = match ($termType) {
                Term::TypeFirstSemester => 1,
                Term::TypeSecondSemester => 2,
                Term::TypeSpecialTerm => 3,
                default => null,
            };

            if ($mapped !== null) {
                return $mapped;
            }
        }

        $label = strtolower(trim((string) ($termLabel ?? '')));

        return match (true) {
            str_contains($label, 'first') || str_contains($label, '1st') => 1,
            str_contains($label, 'second') || str_contains($label, '2nd') => 2,
            str_contains($label, 'summer') || str_contains($label, 'special') || str_contains($label, 'mid') => 3,
            default => 9,
        };
    }

    public function updatedAcademicTerm(): void
    {
        $this->academicPeriod = $this->academicTerm !== null ? 'term:'.$this->academicTerm : null;
        $this->academicPage = 1;
        $this->academicResult = null;
    }

    public function updatedAcademicPeriod(): void
    {
        if (is_string($this->academicPeriod) && str_starts_with($this->academicPeriod, 'term:')) {
            $termId = (int) substr($this->academicPeriod, 5);
            $this->academicTerm = $termId > 0 ? $termId : null;
        } else {
            $this->academicTerm = null;
        }

        $this->academicPage = 1;
        $this->academicResult = null;
    }

    public function updatedAcademicSearch(): void
    {
        $this->academicPage = 1;
        $this->academicResult = null;
    }

    public function setAcademicPage(int $page): void
    {
        $this->academicPage = max(1, $page);
        $this->academicResult = null;
    }

    public function setHistoryPage(int $page): void
    {
        $this->historyPage = max(1, $page);
    }

    public function selectAcademicResult(int|string|null $result): void
    {
        $this->academicResult = $result;
        unset($this->academicProfile);
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function resultLinks(array $result): array
    {
        $courseEnrollment = $result['course_enrollment'] ?? null;

        if (! $courseEnrollment instanceof CourseEnrollment) {
            $priorCredit = $result['prior_credit'] ?? null;

            return [
                ...$result,
                'result_key' => 'pc:'.($priorCredit?->id ?? 0),
                'sourceUrl' => null,
                'followUpUrl' => null,
                'followUp' => null,
            ];
        }

        $roster = $courseEnrollment->gradeRosterRow?->roster;
        $canView = $roster && GradeRosterResource::canView($roster);
        $parameters = ['record' => $roster, 'result' => $courseEnrollment->gradeRosterRow?->id, ...$this->oversightQueryParameters()];
        $enrollment = $courseEnrollment->enrollment;
        $sourceUrl = $canView
            ? GradeRosterResource::getUrl('view', [...$parameters, 'viewTab' => 'grade-review', 'history' => true]).'#course-result-'.$courseEnrollment->id
            : ($enrollment && EnrollmentResource::canView($enrollment)
                ? EnrollmentResource::getUrl('view', ['record' => $enrollment, ...$this->oversightQueryParameters()]) : null);

        return [...$result,
            'result_key' => 'ce:'.$courseEnrollment->id,
            'sourceUrl' => $sourceUrl,
            'followUpUrl' => $canView ? GradeRosterResource::getUrl('view', [...$parameters, 'viewTab' => 'inc-corrections']) : null,
            'followUp' => $result['result'] === 'INC' && $result['inc_deadline'] !== null
                && $courseEnrollment->gradeRosterRow !== null
                    ? ReleasedResultsTable::followUp($courseEnrollment->gradeRosterRow) : null,
        ];
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function enrollmentProfile(): array
    {
        $enrollments = $this->record->enrollments()->with(['term.academicYear', 'studentSection'])
            ->join('terms', 'terms.id', '=', 'enrollments.term_id')->select('enrollments.*')
            ->orderByDesc('terms.starts_on')->orderByDesc('terms.id')->orderByDesc('enrollments.id')->get();
        $resolver = app(EnrollmentAcademicContextResolver::class);
        $selected = $enrollments->firstWhere('id', $this->enrollment)
            ?? $enrollments->firstWhere('term.state', Term::StateActive)
            ?? $enrollments->first(fn (Enrollment $enrollment): bool => $enrollment->term?->starts_on?->isPast() ?? false);

        return [
            'selected' => $selected,
            'termOptions' => $enrollments->mapWithKeys(fn (Enrollment $enrollment): array => [$enrollment->id => $this->termLabel($enrollment->term)])->all(),
            'context' => $selected ? $resolver->forEnrollment($selected) : null,
            'sourceUrl' => $selected && EnrollmentResource::canView($selected)
                ? EnrollmentResource::getUrl('view', ['record' => $selected, ...$this->oversightQueryParameters()]) : null,
        ];
    }

    /**
     * Admission papers from the application this Student enrolled from (#103 Scope 7 follow-up):
     * each is Passed or To be followed, so owed papers stay visible after official enrollment.
     *
     * @return array{application: AdmissionApplication|null, items: list<array{label: string, status: string, passed: bool, due: string}>, followed: list<string>}
     */
    #[Computed]
    public function admissionPapers(): array
    {
        $checklist = app(AdmissionPaperChecklist::class);
        $application = $checklist->applicationForStudent($this->studentProfile());

        if (! $application instanceof AdmissionApplication) {
            return ['application' => null, 'items' => [], 'followed' => []];
        }

        $items = collect($checklist->categories($application))
            ->flatMap(fn (array $category): array => $category['items'])
            ->map(fn (array $item): array => [
                'label' => $item['label'],
                'status' => $item['status'],
                'passed' => $item['tone'] === 'verified',
                'due' => $item['due'],
            ])->values()->all();

        return [
            'application' => $application,
            'items' => $items,
            'followed' => collect($items)->reject(fn (array $item): bool => $item['passed'])->pluck('label')->values()->all(),
        ];
    }

    public function updateAdmissionPapersAction(): Action
    {
        return Action::make('updateAdmissionPapers')
            ->label('Update papers')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('gray')
            ->size('sm')
            ->modalHeading('Update papers')
            ->modalDescription('Tick the papers the student has now passed. They are tagged Passed; the rest stay To be followed.')
            ->modalSubmitActionLabel('Save papers')
            ->modalWidth(Width::TwoExtraLarge)
            ->schema(fn (): array => $this->admissionPapers['application'] instanceof AdmissionApplication
                ? AdmissionPaperFields::make(app(AdmissionPaperChecklist::class)->categories($this->admissionPapers['application']), onlyFollowed: true)
                : [])
            ->visible(fn (): bool => $this->canUpdateAdmissionPapers())
            ->authorize(fn (): bool => $this->canUpdateAdmissionPapers())
            ->action(function (array $data, Action $action): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                try {
                    DB::transaction(fn () => app(AdmissionPaperChecklist::class)->recordPassed(
                        $this->admissionPapers['application'],
                        $actor,
                        AdmissionPaperFields::checkedIds($data),
                    ));
                } catch (ValidationException $exception) {
                    Notification::make()->title('Papers were not updated')->body($exception->validator->errors()->first())->danger()->send();
                    $action->halt();
                }

                unset($this->admissionPapers);
                Notification::make()->title('Papers updated')->success()->send();
            });
    }

    private function canUpdateAdmissionPapers(): bool
    {
        $application = $this->admissionPapers['application'];
        $actor = auth()->user();

        return $application instanceof AdmissionApplication
            && $this->admissionPapers['followed'] !== []
            && $actor instanceof User
            && $actor->can('review', $application);
    }

    /** @return array{checklistCount: int, holdCount: int, hasChecklist: bool, hasHolds: bool} */
    #[Computed]
    public function requirementsProfile(): array
    {
        $checklistCount = $this->record->checklistItems()->count();
        $holdCount = $this->record->holds()->count();

        return ['checklistCount' => $checklistCount, 'holdCount' => $holdCount,
            'hasChecklist' => $checklistCount > 0, 'hasHolds' => $holdCount > 0];
    }

    public function updatedHistoryTerm(): void
    {
        $this->historyPage = 1;
    }

    public function updatedHistoryType(): void
    {
        $this->historyPage = 1;
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function historyProfile(): array
    {
        $assessments = Assessment::query()->join('enrollments', 'enrollments.id', '=', 'assessments.enrollment_id')
            ->where('enrollments.student_profile_id', $this->record->id)
            ->selectRaw("assessments.id, 'assessment' AS source, assessments.created_at AS occurred_at, enrollments.term_id");
        $lifecycle = StudentLifecycleChange::query()->where('student_profile_id', $this->record->id)
            ->selectRaw("id, 'lifecycle' AS source, effective_on AS occurred_at, term_id");
        $query = DB::query()->fromSub($assessments->toBase()->unionAll($lifecycle->toBase()), 'student_history');
        $terms = Term::query()->whereIn('id', (clone $query)->select('term_id'))->with('academicYear')
            ->orderByDesc('starts_on')->orderByDesc('id')->get()->keyBy('id');
        $query->when($this->historyTerm !== null, fn ($query) => $query->where('term_id', $this->historyTerm))
            ->when($this->historyType !== '', fn ($query) => $query->where('source', in_array($this->historyType, ['assessment', 'lifecycle'], true) ? $this->historyType : 'invalid'));
        $total = (clone $query)->count();
        $page = min(max(1, $this->historyPage), max(1, (int) ceil($total / 10)));
        $rows = $query->orderByDesc('occurred_at')->orderBy('source')->orderByDesc('id')->forPage($page, 10)->get();
        $assessmentModels = Assessment::query()->whereIn('id', $rows->where('source', 'assessment')->pluck('id'))
            ->whereHas('enrollment', fn ($query) => $query->where('student_profile_id', $this->record->id))
            ->with('enrollment')->get()->keyBy('id');
        $lifecycleModels = StudentLifecycleChange::query()->where('student_profile_id', $this->record->id)
            ->whereIn('id', $rows->where('source', 'lifecycle')->pluck('id'))->get()->keyBy('id');
        $entries = $rows->map(function (object $row) use ($assessmentModels, $lifecycleModels, $terms): array {
            $assessment = $row->source === 'assessment';
            $record = $assessment ? $assessmentModels->get($row->id) : $lifecycleModels->get($row->id);
            $resource = $assessment ? EnrollmentResource::class : StudentLifecycleChangeResource::class;
            $source = $assessment ? $record->enrollment : $record;

            return ['id' => (int) $row->id, 'source' => $row->source, 'record' => $record,
                'date' => $assessment ? $record->created_at : $record->effective_on,
                'dateLabel' => $assessment ? 'Assessment created' : 'Effective date',
                'termLabel' => $this->termLabel($terms->get($row->term_id)),
                'title' => $assessment ? ($record->state === Assessment::StateActive ? 'Current assessment' : 'Earlier assessment') : (StudentLifecycleChange::typeOptions()[$record->type] ?? Str::headline($record->type)),
                'status' => Str::headline($record->state),
                'sourceUrl' => $source && $resource::canView($source) ? $resource::getUrl('view', ['record' => $source, ...$this->oversightQueryParameters()]) : null];
        });

        return ['entries' => new LengthAwarePaginator($entries, $total, 10, $page, ['pageName' => 'historyPage']),
            'termOptions' => $terms->mapWithKeys(fn (Term $term): array => [$term->id => $this->termLabel($term)])->all()];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function paginateRows(Collection $rows, int $page, string $pageName): LengthAwarePaginator
    {
        $page = min(max(1, $page), max(1, (int) ceil($rows->count() / 10)));

        return new LengthAwarePaginator($rows->forPage($page, 10)->values(), $rows->count(), 10, $page, ['pageName' => $pageName]);
    }

    public function recordStudentSectionAction(): Action
    {
        return Action::make('recordStudentSection')
            ->label('Student section')->icon('heroicon-o-user-group')->color('gray')
            ->modalHeading('Record student section')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Record the student’s group for one term. Their enrolled subjects, grades and timetable stay separate.')
            ->modalSubmitActionLabel('Save section')
            ->visible(fn (): bool => $this->isRegistrarActor()
                && ($this->viewTab === 'academic-progress'
                    ? $this->enrollmentProfile['selected']?->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
                    : $this->record->enrollments()->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)->exists()))
            ->authorize(fn (): bool => $this->isRegistrarActor())
            ->fillForm(function (): array {
                $enrollment = $this->viewTab === 'academic-progress' ? $this->enrollmentProfile['selected'] : null;

                return ['enrollment_id' => $enrollment?->id, 'student_section_id' => $enrollment?->student_section_id,
                    'expected_lock_version' => $enrollment?->lock_version];
            })
            ->schema([
                Select::make('enrollment_id')->label('Enrollment term')->required()->searchable()
                    ->visible(fn (): bool => $this->viewTab !== 'academic-progress')
                    ->dehydratedWhenHidden()
                    ->options(fn (): array => $this->record->enrollments()
                        ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
                        ->with('term.academicYear')->orderByDesc('id')->get()
                        ->mapWithKeys(fn (Enrollment $enrollment): array => [$enrollment->id => collect([$enrollment->term?->academicYear?->label, $enrollment->term?->label])->filter()->implode(' · ')])->all())
                    ->live()->afterStateUpdated(function (Set $set, mixed $state): void {
                        $enrollment = $this->record->enrollments()->find($state);
                        $set('student_section_id', $enrollment?->student_section_id);
                        $set('expected_lock_version', $enrollment?->lock_version);
                    }),
                Select::make('student_section_id')->label('Student section')->placeholder('Not assigned')->searchable()
                    ->disabled(fn (Get $get): bool => blank($get('enrollment_id')))
                    ->options(function (Get $get): array {
                        $enrollmentId = $this->viewTab === 'academic-progress'
                            ? $this->enrollmentProfile['selected']?->id : $get('enrollment_id');
                        $enrollment = $this->record->enrollments()->with('currentProposalVersion.curriculumVersion')->find($enrollmentId);
                        if (! $enrollment instanceof Enrollment) {
                            return [];
                        }

                        return TermCohort::query()->where('term_id', $enrollment->term_id)
                            ->where('program_id', $enrollment->currentProposalVersion?->curriculumVersion?->program_id)
                            ->where('state', 'Confirmed')->orderBy('reference')->pluck('reference', 'id')->all();
                    })
                    ->helperText('Leave unassigned when no student section has been recorded. Class attendance does not determine this group.'),
                Hidden::make('expected_lock_version'),
            ])
            ->action(function (array $data): void {
                if ($this->viewTab === 'academic-progress'
                    && (int) ($data['enrollment_id'] ?? 0) !== (int) $this->enrollmentProfile['selected']?->id) {
                    throw ValidationException::withMessages(['enrollment_id' => 'The enrollment term changed. Reopen Student section for the displayed term.']);
                }
                $enrollment = $this->record->enrollments()->findOrFail($data['enrollment_id']);
                app(RecordStudentSection::class)->execute($enrollment, auth()->user(),
                    filled($data['student_section_id'] ?? null) ? (int) $data['student_section_id'] : null,
                    (int) ($data['expected_lock_version'] ?? -1));
                $this->record->unsetRelation('enrollments');
                unset($this->enrollmentProfile, $this->academicProfile);
                Notification::make()->title('Student section saved')->success()->send();
            });
    }
}
