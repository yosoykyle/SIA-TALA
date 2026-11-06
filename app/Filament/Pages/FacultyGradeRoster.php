<?php

namespace App\Filament\Pages;

use App\Actions\Grades\CurrentClassRosterOutput;
use App\Actions\Grades\FinalResultPolicy;
use App\Actions\Grades\GradeWindowService;
use App\Actions\Grades\IncDeadlineService;
use App\Actions\Grades\SaveGradeRosterDraft;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SubmitIncCompletion;
use App\Filament\Resources\GradeRosters\Actions\ExportClassRosterAction;
use App\Filament\Resources\GradeRosters\Tables\ReleasedResultsTable;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\IncCompletionSubmission;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use RuntimeException;

/** @property Schema $form */
class FacultyGradeRoster extends Page
{
    private const EditableStates = [
        GradeRoster::StateDraft,
        GradeRoster::StateReturned,
        GradeRoster::StateLateNotSubmitted,
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|\UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'Grade Rosters';

    protected static ?string $title = 'Grade Rosters';

    protected string $view = 'filament.pages.faculty-grade-roster';

    protected array $extraBodyAttributes = ['class' => 'tala-faculty-membership-page'];

    #[Locked]
    public ?int $rosterId = null;

    #[Locked]
    public bool $rosterEditable = false;

    #[Locked]
    public int $reviewedVersion = 0;

    /** @var list<int> */
    #[Locked]
    public array $editableRowIds = [];

    #[Locked]
    public ?int $selectedStudentId = null;

    public string $studentSearch = '';

    public bool $needsChangesOnly = false;

    public int $studentPage = 1;

    #[Locked]
    public string $savedDraftSignature = '';

    #[Locked]
    public ?string $draftSaveError = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<int,array<string,mixed>> */
    private array $membershipSnapshots = [];

    public function getTitle(): string
    {
        return $this->selectedRoster()?->termOffering?->curriculumEntry?->courseSpecification?->title ?? 'Grade rosters';
    }

    public function getSubheading(): ?string
    {
        $roster = $this->selectedRoster();

        return $roster === null ? null : collect([
            $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
            $roster->section?->code,
            $roster->termOffering?->term?->academicYear?->label,
            $roster->termOffering?->term?->label,
        ])->filter()->implode(' · ');
    }

    /** @return array<string,mixed>|null */
    private function classMembership(): ?array
    {
        if ($this->rosterId === null) {
            return null;
        }
        if (! array_key_exists($this->rosterId, $this->membershipSnapshots)) {
            $roster = $this->accessibleRosters()->find($this->rosterId);
            if (! $roster instanceof GradeRoster) {
                return null;
            }
            $this->authorize('view', $roster);
            $this->membershipSnapshots[$this->rosterId] = app(CurrentClassRosterOutput::class)->membershipSnapshot($roster, $this->authenticatedUser());
            $this->membershipSnapshots[$this->rosterId]['roster']->loadMissing('teachingAssignment');
        }

        return $this->membershipSnapshots[$this->rosterId];
    }

    protected function getViewData(): array
    {
        $termFilter = request()->integer('term');
        $search = trim((string) request()->query('q', ''));
        $terms = collect();
        $classRows = collect();
        $classPaginator = null;

        if ($this->rosterId === null) {
            $terms = Term::query()
                ->whereIn('id', TermOffering::query()->select('term_id')
                    ->whereIn('id', $this->accessibleRosters()->select('term_offering_id')))
                ->with('academicYear')
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get();

            $classes = $this->accessibleRosters()
                ->select('grade_rosters.*')
                ->join('term_offerings', 'term_offerings.id', '=', 'grade_rosters.term_offering_id')
                ->join('terms', 'terms.id', '=', 'term_offerings.term_id')
                ->with(['termOffering.term.academicYear', 'termOffering.curriculumEntry.courseSpecification.course', 'section'])
                ->when($termFilter > 0, fn (Builder $query): Builder => $query->where('term_offerings.term_id', $termFilter))
                ->when($search !== '', function (Builder $query) use ($search): void {
                    $pattern = '%'.addcslashes($search, '\\%_').'%';
                    $query->where(function (Builder $matches) use ($pattern): void {
                        $matches->whereHas('section', fn (Builder $section): Builder => $section->whereLike('code', $pattern))
                            ->orWhereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $course): Builder => $course->whereLike('title', $pattern))
                            ->orWhereHas('termOffering.curriculumEntry.courseSpecification.course', fn (Builder $course): Builder => $course->whereLike('code', $pattern))
                            ->orWhereHas('termOffering.term', fn (Builder $term): Builder => $term->whereLike('label', $pattern))
                            ->orWhereHas('termOffering.term.academicYear', fn (Builder $year): Builder => $year->whereLike('label', $pattern));
                    });
                })
                ->orderByDesc('terms.starts_on')
                ->orderByDesc('terms.id')
                ->orderBy('grade_rosters.id');

            $classPaginator = $classes->paginate(8)->appends([
                'classes' => 1,
                'term' => $termFilter > 0 ? $termFilter : null,
                'q' => $search !== '' ? $search : null,
            ]);
            $classRows = $classPaginator->getCollection()->map(function (GradeRoster $roster): array {
                $snapshot = app(CurrentClassRosterOutput::class)->membershipSnapshot($roster, $this->authenticatedUser());

                return ['roster' => $roster, 'current' => $snapshot['isCurrent'], 'count' => $snapshot['isCurrent'] ? $snapshot['count'] : null];
            });
        }

        $membership = $this->classMembership();

        return [
            'entryWindow' => $membership ? app(GradeWindowService::class)->entryContext($membership['roster']) : null,
            'membership' => $membership,
            'classRows' => $classRows,
            'classPaginator' => $classPaginator,
            'classTerms' => $terms,
            'classTermFilter' => $termFilter,
            'classSearch' => $search,
            'workspace' => $this->studentWorkspace(),
        ];
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(User::StaffRoleFaculty) ?? false;
    }

    public function mount(): void
    {
        $requestedRosterId = request()->integer('roster');
        $this->rosterId = $requestedRosterId > 0
            ? $this->accessibleRosters()->findOrFail($requestedRosterId)->id
            : (! request()->boolean('classes') && $this->accessibleRosters()->count() === 1 ? $this->accessibleRosters()->value('grade_rosters.id') : null);
        $this->fillRosterForm();
        $this->needsChangesOnly = $this->canEditSelectedRoster() && $this->selectedRoster()?->state === GradeRoster::StateReturned;
        $this->selectFirstVisibleStudent();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('lock_version'),
                Hidden::make('membership_signature'),
                Group::make()->schema(function (): array {
                    $selected = $this->studentWorkspace()['selected'];
                    if ($selected === null || $selected['key'] === null) {
                        return [];
                    }
                    $path = 'rows.'.$selected['key'];
                    $editable = $this->canEditSelectedRoster() && in_array($selected['id'], $this->editableRowIds, true);

                    return [
                        Select::make($path.'.final_result')
                            ->label('Final result')
                            ->placeholder('Not recorded')
                            ->options(fn (): array => app(FinalResultPolicy::class)->options())
                            ->disabled(! $editable)
                            ->live(),
                        Textarea::make($path.'.inc_completion_note')
                            ->label('INC completion note')
                            ->helperText('Describe the remaining requirement without sensitive personal details.')
                            ->rows(3)
                            ->maxLength(1000)
                            ->live(debounce: 1000)
                            ->required(fn (Get $get): bool => $get($path.'.final_result') === 'INC')
                            ->visible(fn (Get $get): bool => $get($path.'.final_result') === 'INC')
                            ->disabled(! $editable),
                    ];
                }),
            ])
            ->statePath('data');
    }

    public function updatedStudentSearch(): void
    {
        $this->studentSearch = mb_substr($this->studentSearch, 0, 200);
        $this->studentPage = 1;
        $this->selectFirstVisibleStudent();
    }

    public function updatedNeedsChangesOnly(): void
    {
        $this->studentPage = 1;
        $this->selectFirstVisibleStudent();
    }

    public function selectStudent(int $rowId): void
    {
        $students = $this->filteredStudents();
        $position = $students->search(fn (array $student): bool => $student['id'] === $rowId);
        abort_if($position === false, 404);

        $this->selectedStudentId = $rowId;
        $this->studentPage = intdiv($position, 10) + 1;
        $this->resetErrorBag();
        $this->dispatch('roster-student-selected');
    }

    public function goToStudentPage(int $page): void
    {
        $students = $this->filteredStudents();
        $lastPage = max(1, (int) ceil($students->count() / 10));
        $this->studentPage = max(1, min($page, $lastPage));
        $this->selectedStudentId = $students->forPage($this->studentPage, 10)->first()['id'] ?? null;
        $this->resetErrorBag();
    }

    private function selectFirstVisibleStudent(): void
    {
        $students = $this->filteredStudents();
        $position = $students->search(fn (array $student): bool => $student['id'] === $this->selectedStudentId);
        if ($position === false) {
            $this->selectedStudentId = $students->first()['id'] ?? null;
            $position = 0;
        }
        $this->studentPage = intdiv($position, 10) + 1;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rosterStudents(): Collection
    {
        $roster = $this->selectedRoster();
        $drafts = collect($this->data['rows'] ?? [])->mapWithKeys(fn (array $row, int|string $key): array => [
            (int) ($row['id'] ?? 0) => ['key' => $key, 'result' => $row['final_result'] ?? null],
        ]);

        return collect($roster?->rows ?? [])->map(function (GradeRosterRow $row) use ($drafts): array {
            $student = $row->courseEnrollment?->enrollment?->studentProfile;
            $draft = $drafts->get($row->id);

            return [
                'id' => $row->id,
                'key' => $draft['key'] ?? null,
                'legal_name' => collect([$student?->last_name, collect([$student?->first_name, $student?->middle_name])->filter()->implode(' ')])->filter()->implode(', '),
                'student_number' => $student?->student_number,
                'initials' => str($student?->first_name)->substr(0, 1).str($student?->last_name)->substr(0, 1),
                'final_result' => $draft !== null ? $draft['result'] : $row->final_result,
                'returned' => $row->returned_at !== null,
                'return_reason' => $row->return_reason ?? $this->selectedRoster()?->return_reason,
            ];
        })->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function filteredStudents(): Collection
    {
        $search = mb_strtolower(trim($this->studentSearch));

        return $this->rosterStudents()->filter(fn (array $student): bool => (! $this->needsChangesOnly || $student['returned'])
            && ($search === '' || str_contains(mb_strtolower($student['legal_name'].' '.$student['student_number']), $search)))
            ->values();
    }

    /** @return array<string, mixed> */
    private function studentWorkspace(): array
    {
        $all = $this->rosterStudents();
        $students = $this->filteredStudents();
        $position = $students->search(fn (array $student): bool => $student['id'] === $this->selectedStudentId);
        $page = max(1, min($this->studentPage, max(1, (int) ceil($students->count() / 10))));

        $selected = $position === false ? null : $students->get($position);
        if ($selected !== null) {
            $member = $this->selectedRoster()?->rows->firstWhere('id', $selected['id']);
            $user = $member?->courseEnrollment?->enrollment?->studentProfile?->user;
            $selected['photo_url'] = $user instanceof User && filled($user->avatar_path)
                ? route('grade-rosters.student-photo', ['roster' => $this->rosterId, 'row' => $selected['id']]) : null;
        }

        return [
            'students' => $students->forPage($page, 10),
            'total' => $all->count(),
            'hasUnsavedChanges' => $this->savedDraftSignature !== $this->draftSignature(),
            'filtered' => $students->count(),
            'recorded' => $all->filter(fn (array $student): bool => filled($student['final_result']))->count(),
            'returned' => $all->where('returned', true)->count(),
            'selected' => $selected,
            'position' => $position === false ? null : $position + 1,
            'previous' => $position === false ? null : ($students->get($position - 1)['id'] ?? null),
            'next' => $position === false ? null : ($students->get($position + 1)['id'] ?? null),
            'page' => $page,
            'lastPage' => max(1, (int) ceil($students->count() / 10)),
            'from' => $students->isEmpty() ? 0 : ($page - 1) * 10 + 1,
            'to' => min($page * 10, $students->count()),
        ];
    }

    private function draftSignature(): string
    {
        return hash('sha256', serialize(collect($this->data['rows'] ?? [])->map(fn (array $row): array => [
            $row['id'] ?? null,
            filled($row['final_result'] ?? null) ? $row['final_result'] : null,
            ($row['final_result'] ?? null) === 'INC' && filled($row['inc_completion_note'] ?? null) ? trim($row['inc_completion_note']) : null,
        ])->all()));
    }

    public function getHeader(): ?View
    {
        $roster = $this->selectedRoster();

        if ($roster === null) {
            return null;
        }

        return view('filament.components.faculty-roster-header', [
            'heading' => $this->getHeading(),
            'subheading' => $this->getSubheading(),
            'roster' => $roster,
            'actions' => $this->getCachedHeaderActions(),
        ]);
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('selectRoster')
                ->label('All classes')->color('gray')->icon(Heroicon::OutlinedArrowLeft)->link()
                ->url(static::getUrl(['classes' => 1]))
                ->visible(fn (): bool => $this->rosterId !== null && $this->accessibleRosters()->count() > 1),
            Action::make('printRoster')
                ->label('Print class roster')
                ->color('gray')
                ->icon(Heroicon::OutlinedPrinter)
                ->visible(fn (): bool => $this->hasCurrentRosterLearners())
                ->url(fn (): ?string => $this->rosterId === null ? null : route('grade-rosters.print', $this->rosterId))
                ->openUrlInNewTab(),
            ExportClassRosterAction::make('downloadRoster')
                ->record(fn (): GradeRoster => $this->selectedRosterOrFail())
                ->visible(fn (): bool => $this->hasCurrentRosterLearners()),
            Action::make('submitIncCompletion')
                ->label('Submit INC completion')
                ->modalSubmitActionLabel('Submit completion for review')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->visible(fn (): bool => $this->incCompletionOptions() !== [])
                ->schema([
                    Select::make('row_id')->label('Student and course')->options(fn (): array => $this->incCompletionOptions())->required(),
                    Select::make('proposed_result')->label('Completed final result')->options(fn (): array => collect(app(FinalResultPolicy::class)->options())->except('INC')->all())->required(),
                    Textarea::make('completion_note')->label('Completion evidence note')->helperText('Describe the completed requirement without entering sensitive details.')->required()->rows(3)->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $row = $this->selectedRosterOrFail()->rows()->whereKey((int) $data['row_id'])->firstOrFail();
                    $inc = $row->outcomeEvents()->where('result_code', 'INC')->whereNotNull('released_at')->latest('id')->firstOrFail();
                    try {
                        app(SubmitIncCompletion::class)->execute($inc, (string) $data['proposed_result'], (string) $data['completion_note'], $this->authenticatedUser());
                    } catch (RuntimeException $exception) {
                        Notification::make()->title('Completion was not submitted')->body($exception->getMessage())->danger()->send();
                        throw new Halt;
                    }
                    $this->membershipSnapshots = [];
                    Notification::make()->title('INC completion submitted for Registrar review')->success()->send();
                }),

        ];
    }

    public function submitAction(): Action
    {
        return Action::make('submit')
            ->label('Submit complete roster')
            ->modalHeading('Submit this class roster?')
            ->modalSubmitActionLabel('Submit complete roster')
            ->modalDescription(fn (): string => $this->submissionSummary())
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canEditSelectedRoster())
            ->action(function (): void {
                try {
                    $saved = $this->persistDraft(submitting: true);
                    app(SubmitGradeRoster::class)->execute($saved, $this->authenticatedUser(), $this->reviewedVersion);
                } catch (RuntimeException $exception) {
                    Notification::make()->title('Roster was not submitted')->body($exception->getMessage())->danger()->send();

                    return;
                }
                $this->fillRosterForm();
                Notification::make()->title('Roster submitted for Registrar review')->success()->send();
            });
    }

    public function saveDraft(): void
    {
        try {
            $saved = $this->persistDraft();
            $this->data['lock_version'] = $saved->lock_version;
            $this->savedDraftSignature = $this->draftSignature();
            $this->draftSaveError = null;
            $this->membershipSnapshots = [];
            $this->resetErrorBag();
            $this->dispatch('roster-draft-saved');
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $this->draftSaveError = 'Check the highlighted result. Your latest changes are not saved.';
        } catch (QueryException $exception) {
            report($exception);
            $this->draftSaveError = 'Saving is unavailable. Keep this page open and retry.';
        } catch (RuntimeException $exception) {
            $this->draftSaveError = $exception->getMessage();
        }
    }

    public function updatedData(mixed $value, ?string $key): void
    {
        if ($key !== null && preg_match('/^rows\.\d+\.(final_result|inc_completion_note)$/', $key)) {
            $this->saveDraft();
        }
    }

    /** @return Builder<GradeRoster> */
    private function accessibleRosters(): Builder
    {
        return GradeRoster::query()->whereIn('section_id', ClassOfferingTeachingAssignment::query()
            ->select('section_id')
            ->where('faculty_user_id', Auth::id())
            ->where('state', ClassOfferingTeachingAssignment::StateActive));
    }

    private function selectedRoster(): ?GradeRoster
    {
        return $this->classMembership()['roster'] ?? null;
    }

    private function selectedRosterOrFail(): GradeRoster
    {
        return $this->selectedRoster() ?? throw new RuntimeException('Select an accessible roster first.');
    }

    private function hasCurrentRosterLearners(): bool
    {
        $snapshot = $this->classMembership();

        return $snapshot !== null && $snapshot['isCurrent'] && $snapshot['count'] > 0;
    }

    public function canEditSelectedRoster(): bool
    {
        return $this->rosterEditable && $this->hasCurrentRosterLearners();
    }

    private function fillRosterForm(): void
    {
        $this->membershipSnapshots = [];
        $roster = $this->selectedRoster();

        $this->reviewedVersion = (int) ($roster?->current_version_number ?? 0);
        $this->rosterEditable = $roster instanceof GradeRoster
            && (int) $roster->teachingAssignment?->faculty_user_id === (int) Auth::id()
            && in_array($roster->state, self::EditableStates, true)
            && app(GradeWindowService::class)->isOpen($roster, 'final');
        $this->editableRowIds = $roster instanceof GradeRoster && $this->rosterEditable
            ? $roster->rows->filter(fn (GradeRosterRow $row): bool => (bool) $row->is_current_membership
                && ($roster->state !== GradeRoster::StateReturned || $row->returned_at !== null))->modelKeys()
            : [];

        $this->form->fill($roster instanceof GradeRoster ? [
            'lock_version' => $roster->lock_version,
            'membership_signature' => $roster->membership_signature,
            'rows' => $roster->rows->where('is_current_membership', true)->sortBy(fn (GradeRosterRow $row): string => collect([
                $row->courseEnrollment?->enrollment?->studentProfile?->last_name,
                $row->courseEnrollment?->enrollment?->studentProfile?->first_name,
                $row->courseEnrollment?->enrollment?->studentProfile?->middle_name,
                $row->courseEnrollment?->enrollment?->studentProfile?->student_number,
            ])->filter()->implode('|'))->map(function (GradeRosterRow $row) use ($roster): array {
                $student = $row->courseEnrollment?->enrollment?->studentProfile;

                return [
                    'id' => $row->id,
                    'student_number' => $student?->student_number,
                    'legal_name' => collect([$student?->last_name, collect([$student?->first_name, $student?->middle_name])->filter()->implode(' ')])->filter()->implode(', '),
                    'enrollment_state' => str($row->courseEnrollment?->enrollment->canonical_outcome ?? $row->courseEnrollment?->enrollment->status)->headline()->toString(),
                    'returned_for_review' => $row->returned_at !== null,
                    'final_result' => $roster->state === GradeRoster::StateReleased ? $row->current_outcome_code : $row->final_result,
                    'inc_completion_note' => $row->inc_completion_note,
                ];
            })->values()->all(),
        ] : ['rows' => []]);
        $this->savedDraftSignature = $this->draftSignature();
        $this->selectFirstVisibleStudent();
    }

    private function persistDraft(bool $submitting = false): GradeRoster
    {
        if (! ($this->classMembership()['isCurrent'] ?? false)) {
            Notification::make()->title('Class membership changed')->body('Ask the Registrar to refresh this class roster, then reload before saving.')->danger()->send();
            throw ValidationException::withMessages(['rows' => 'Current official class membership must be refreshed before saving.']);
        }
        $state = $this->form->getRawState();
        $validator = Validator::make(['data' => $state], [
            'data.rows' => ['required', 'array'],
            'data.rows.*.id' => ['required', 'integer'],
            'data.rows.*.final_result' => ['nullable', Rule::in(array_keys(app(FinalResultPolicy::class)->options()))],
            'data.rows.*.inc_completion_note' => ['nullable', 'string', 'max:1000', ...($submitting ? ['required_if:data.rows.*.final_result,INC'] : [])],
        ], attributes: [
            'data.rows.*.final_result' => 'final result',
            'data.rows.*.inc_completion_note' => 'INC completion note',
        ]);
        if ($validator->fails()) {
            $key = explode('.', $validator->errors()->keys()[0])[2] ?? null;
            $rowId = (int) ($state['rows'][$key]['id'] ?? 0);
            if ($this->rosterStudents()->contains('id', $rowId)) {
                $this->studentSearch = '';
                $this->needsChangesOnly = false;
                $this->selectStudent($rowId);
            }
            throw new ValidationException($validator);
        }
        if ($submitting) {
            $this->form->getState();
        }

        return app(SaveGradeRosterDraft::class)->execute(
            $this->selectedRosterOrFail(),
            array_values($state['rows'] ?? []),
            (int) ($state['lock_version'] ?? -1),
            filled($state['membership_signature'] ?? null) ? (string) $state['membership_signature'] : null,
            $this->authenticatedUser(),
        );
    }

    /** @return array<string, mixed>|null */
    public function selectedIncContext(): ?array
    {
        $roster = $this->selectedRoster();
        $row = $roster?->rows->firstWhere('id', $this->selectedStudentId);

        if ($roster?->state !== GradeRoster::StateReleased || $row?->current_outcome_code !== 'INC') {
            return null;
        }

        return ReleasedResultsTable::followUp($row);
    }

    /** @return array<int, string> */
    private function incCompletionOptions(): array
    {
        $roster = $this->selectedRoster();

        if (! $roster instanceof GradeRoster
            || $roster->teachingAssignment?->state !== ClassOfferingTeachingAssignment::StateActive
            || $roster->teachingAssignment?->role !== ClassOfferingTeachingAssignment::RoleDesignated
            || (int) $roster->teachingAssignment?->faculty_user_id !== (int) Auth::id()) {
            return [];
        }

        $roster->rows->loadMissing('latestReleasedOutcome.completionSubmissions');

        return $roster->rows->filter(fn (GradeRosterRow $row): bool => $row->current_outcome_code === 'INC'
            && $row->latestReleasedOutcome?->result_code === 'INC'
            && ! $row->latestReleasedOutcome->completionSubmissions->contains('state', IncCompletionSubmission::StateSubmitted)
            && app(IncDeadlineService::class)->state($row->latestReleasedOutcome) === IncDeadlineService::StateCompletionOpen)
            ->mapWithKeys(function (GradeRosterRow $row): array {
                $student = $row->courseEnrollment?->enrollment?->studentProfile;

                return [$row->id => collect([$student?->student_number, $student?->last_name, $student?->first_name])->filter()->implode(' · ')];
            })->all();
    }

    private function submissionSummary(): string
    {
        $rows = collect($this->form->getRawState()['rows'] ?? []);
        $missing = $rows->where(fn (array $row): bool => blank($row['final_result'] ?? null))->count();

        return $missing === 0
            ? "All {$rows->count()} current results will be saved and submitted to the Registrar for review."
            : "Complete {$missing} of {$rows->count()} current rows before submission.";
    }

    private function authenticatedUser(): User
    {
        return Auth::user() instanceof User ? Auth::user() : throw new AuthorizationException('Authentication is required.');
    }
}
