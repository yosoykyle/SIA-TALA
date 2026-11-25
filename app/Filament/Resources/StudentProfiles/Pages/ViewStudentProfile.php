<?php

namespace App\Filament\Resources\StudentProfiles\Pages;

use App\Actions\Academics\AcademicEnrollmentGuidance;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\IrregularStudentReasons;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Academics\TermWeightedAverageProjection;
use App\Actions\Enrollment\CorrectRegistrationCreditEvaluation;
use App\Actions\Enrollment\EnrollmentAcademicContextResolver;
use App\Actions\Enrollment\RecordStudentSection;
use App\Actions\StudentProfiles\RecordStudentProfileCorrection;
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
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\ExternalCompetencyRequirement;
use App\Models\Hold;
use App\Models\RegistrationCreditEvaluation;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
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
    public string $academicSearch = '';

    #[Url]
    public int $academicPage = 1;

    #[Url]
    public ?int $academicResult = null;

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
        $selectedTerm = $terms->firstWhere('id', $this->academicTerm) ?? $terms->first();
        $termResults = $results->filter(fn (array $result): bool => $selectedTerm !== null
            && (int) $result['term']?->id === (int) $selectedTerm->id);
        (new EloquentCollection($termResults->pluck('course_enrollment')->all()))
            ->loadMissing(['gradeRosterRow.roster', 'gradeRosterRow.outcomeEvents.completionSubmissions']);
        $released = $termResults->filter(fn (array $result): bool => $result['outcome_source'] !== null)
            ->map(fn (array $result): array => $this->resultLinks($result));
        $search = Str::lower(trim($this->academicSearch));
        $filtered = $released->filter(fn (array $result): bool => $search === '' || Str::contains(
            Str::lower(collect([$result['course_specification']?->course?->code, $result['course_specification']?->title])->filter()->implode(' ')), $search,
        ))->values();
        $pagedResults = $this->paginateRows($filtered, $this->academicPage, 'academicPage');
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

        return [
            'student' => $student, 'enrollment' => $enrollment, 'context' => $context, 'handoff' => $handoff,
            'termOptions' => $terms->mapWithKeys(fn (Term $term): array => [$term->id => $this->termLabel($term)])->all(),
            'selectedTerm' => $selectedTerm,
            'results' => $pagedResults,
            'selectedResult' => $this->academicResult === null
                ? $pagedResults->getCollection()->first()
                : $filtered->first(fn (array $result): bool => (int) $result['course_enrollment']->id === $this->academicResult),
            'termAverage' => $selectedTerm && $this->activeRecordTab() === 'academic'
                ? app(TermWeightedAverageProjection::class)->forResults($results, $selectedTerm) : null,
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

    public function updatedAcademicTerm(): void
    {
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

    public function selectAcademicResult(?int $result): void
    {
        $this->academicResult = $result;
        unset($this->academicProfile);
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function resultLinks(array $result): array
    {
        $roster = $result['course_enrollment']->gradeRosterRow?->roster;
        $canView = $roster && GradeRosterResource::canView($roster);
        $parameters = ['record' => $roster, 'result' => $result['course_enrollment']->gradeRosterRow?->id, ...$this->oversightQueryParameters()];
        $enrollment = $result['course_enrollment']->enrollment;
        $sourceUrl = $canView
            ? GradeRosterResource::getUrl('view', [...$parameters, 'viewTab' => 'grade-review', 'history' => true]).'#course-result-'.$result['course_enrollment']->id
            : ($enrollment && EnrollmentResource::canView($enrollment)
                ? EnrollmentResource::getUrl('view', ['record' => $enrollment, ...$this->oversightQueryParameters()]) : null);

        return [...$result,
            'sourceUrl' => $sourceUrl,
            'followUpUrl' => $canView ? GradeRosterResource::getUrl('view', [...$parameters, 'viewTab' => 'inc-corrections']) : null,
            'followUp' => $result['result'] === 'INC' && $result['inc_deadline'] !== null
                && $result['course_enrollment']->gradeRosterRow !== null
                    ? ReleasedResultsTable::followUp($result['course_enrollment']->gradeRosterRow) : null,
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
