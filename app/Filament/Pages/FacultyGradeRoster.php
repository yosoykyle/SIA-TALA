<?php

namespace App\Filament\Pages;

use App\Actions\Calendar\CalendarPhaseGateService;
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
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\IncCompletionSubmission;
use App\Models\IncDeadlineAmendment;
use App\Models\LateGradeAuthorization;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\FacultyActiveTermResolver;
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
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
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

    protected static ?string $navigationLabel = 'Class list & grades';

    protected static ?string $title = 'Class list & grades';

    protected string $view = 'filament.pages.faculty-grade-roster';

    protected array $extraBodyAttributes = ['class' => 'tala-faculty-membership-page'];

    #[Url(except: 'all')]
    public string $queue = 'all';

    #[Url(as: 'term', except: '')]
    public string $classTerm = '';

    #[Url(as: 'q', except: '')]
    public string $classSearch = '';

    public int $classPage = 1;

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
        return $this->selectedRoster()?->termOffering?->curriculumEntry?->courseSpecification?->title ?? 'Class list & grades';
    }

    public function getSubheading(): ?string
    {
        $roster = $this->selectedRoster();

        if ($roster === null) {
            return 'Choose a class to view its masterlist or enter grades.';
        }

        return collect([
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
            $this->membershipSnapshots[$this->rosterId]['roster']->loadMissing(['teachingAssignment', 'termOffering.term.academicYear']);
        }

        return $this->membershipSnapshots[$this->rosterId];
    }

    /** @return array<string, mixed> */
    private function masterlistSnapshotFor(int $rosterId): array
    {
        abort_if($rosterId <= 0, 404);

        if (! array_key_exists($rosterId, $this->membershipSnapshots)) {
            $roster = $this->accessibleRosters()->findOrFail($rosterId);
            $this->authorize('view', $roster);
            $this->membershipSnapshots[$rosterId] = app(CurrentClassRosterOutput::class)->membershipSnapshot($roster, $this->authenticatedUser());
            $this->membershipSnapshots[$rosterId]['roster']->loadMissing(['teachingAssignment', 'termOffering.term.academicYear']);
        }

        return $this->membershipSnapshots[$rosterId];
    }

    protected function getViewData(): array
    {
        $this->normalizeQueue();
        $explicitTermFilter = is_numeric($this->classTerm) ? (int) $this->classTerm : 0;
        $search = trim($this->classSearch);
        $terms = collect();
        $classRows = collect();
        $classPaginator = null;
        $activeScopeTerm = null;
        $scopeTitle = null;
        $scopeMeta = null;

        if ($this->rosterId === null) {
            $terms = Term::query()
                ->whereIn('id', TermOffering::query()->select('term_id')
                    ->whereIn('id', $this->accessibleRosters()->select('term_offering_id')))
                ->with('academicYear')
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get();

            $resolver = app(FacultyActiveTermResolver::class);
            $activeScopeTerm = $this->resolveActiveScopeTerm($terms, $resolver);

            $effectiveTermFilter = match (true) {
                $explicitTermFilter > 0 => $explicitTermFilter,
                $this->queue === 'inc' => 0,
                default => $activeScopeTerm?->id ?? 0,
            };

            [$scopeTitle, $scopeMeta] = $this->buildIndexScopeSummary(
                $resolver,
                $terms,
                $activeScopeTerm,
                $explicitTermFilter,
            );

            $classes = $this->accessibleRosters()
                ->select('grade_rosters.*')
                ->join('term_offerings', 'term_offerings.id', '=', 'grade_rosters.term_offering_id')
                ->join('terms', 'terms.id', '=', 'term_offerings.term_id')
                ->with(['termOffering.term.academicYear', 'termOffering.curriculumEntry.courseSpecification.course', 'section', 'teachingAssignment'])
                ->when($effectiveTermFilter > 0, fn (Builder $query): Builder => $query->where('term_offerings.term_id', $effectiveTermFilter))
                ->when($search !== '', function (Builder $query) use ($search): void {
                    $pattern = '%'.addcslashes($search, '\\%_').'%';
                    $query->where(function (Builder $matches) use ($pattern): void {
                        $matches->whereHas('section', fn (Builder $section): Builder => $section->whereLike('code', $pattern))
                            ->orWhereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $course): Builder => $course->whereLike('title', $pattern))
                            ->orWhereHas('termOffering.curriculumEntry.courseSpecification.course', fn (Builder $course): Builder => $course->whereLike('code', $pattern))
                            ->orWhereHas('termOffering.term', fn (Builder $term): Builder => $term->whereLike('label', $pattern))
                            ->orWhereHas('termOffering.term.academicYear', fn (Builder $year): Builder => $year->whereLike('label', $pattern));
                    });
                });

            if ($this->queue !== 'all') {
                $matchingIds = $this->queueMatchingRosterIds($this->queue, $effectiveTermFilter);
                if ($matchingIds === []) {
                    $classes->whereRaw('1 = 0');
                } else {
                    $classes->whereIn('grade_rosters.id', $matchingIds);
                }
            }

            $classes
                ->orderByDesc('terms.starts_on')
                ->orderByDesc('terms.id')
                ->orderBy('grade_rosters.id');

            $classPaginator = $classes->paginate(8, page: $this->classPage)
                ->withPath(static::getUrl())
                ->appends(array_filter([
                    'classes' => 1,
                    'queue' => $this->queue !== 'all' ? $this->queue : null,
                    'term' => $explicitTermFilter > 0 ? $explicitTermFilter : null,
                    'q' => $search !== '' ? $search : null,
                ], fn (mixed $value): bool => $value !== null));

            $classRows = $this->buildClassIndexRows($classPaginator->getCollection());
        }

        $membership = $this->classMembership();

        return [
            'entryWindow' => $membership ? app(GradeWindowService::class)->entryContext($membership['roster']) : null,
            'membership' => $membership,
            'classRows' => $classRows,
            'classPaginator' => $classPaginator,
            'classTerms' => $terms,
            'classTermFilter' => $explicitTermFilter,
            'classSearch' => $search,
            'activeScopeTerm' => $activeScopeTerm,
            'scopeTitle' => $scopeTitle,
            'scopeMeta' => $scopeMeta,
            'hasActiveClassFilters' => $explicitTermFilter > 0 || $search !== '' || $this->queue !== 'all',
            'workspace' => $this->studentWorkspace(),
        ];
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(User::StaffRoleFaculty) ?? false;
    }

    public function mount(): void
    {
        $this->normalizeQueue();

        if ($this->classTerm === '' && request()->query('term') !== null) {
            $this->classTerm = (string) request()->query('term');
        } elseif ($this->classTerm === '' && request()->integer('termId') > 0) {
            $requestedTermId = request()->integer('termId');
            $hasAssignedTerm = $this->accessibleRosters()
                ->whereHas('termOffering', fn (Builder $query): Builder => $query->where('term_id', $requestedTermId))
                ->exists();
            if ($hasAssignedTerm) {
                $this->classTerm = (string) $requestedTermId;
            }
        }
        if ($this->classSearch === '' && request()->query('q') !== null) {
            $this->classSearch = (string) request()->query('q');
        }

        $this->classPage = max(1, request()->integer('page', 1));

        $requestedRosterId = request()->integer('roster');
        $this->rosterId = $requestedRosterId > 0
            ? $this->accessibleRosters()->findOrFail($requestedRosterId)->id
            : (! request()->boolean('classes') && $this->queue === 'all' && $this->accessibleRosters()->count() === 1
                ? $this->accessibleRosters()->value('grade_rosters.id')
                : null);
        $this->fillRosterForm();
        $this->needsChangesOnly = $this->canEditSelectedRoster() && $this->selectedRoster()?->state === GradeRoster::StateReturned;
        $this->selectFirstVisibleStudent();
    }

    public function updatedQueue(): void
    {
        $this->normalizeQueue();
        $this->classPage = 1;
    }

    public function updatedClassTerm(): void
    {
        $this->classPage = 1;
    }

    public function updatedClassSearch(): void
    {
        $this->classSearch = mb_substr($this->classSearch, 0, 200);
        $this->classPage = 1;
    }

    public function clearClassFilters(): void
    {
        $this->queue = 'all';
        $this->classTerm = '';
        $this->classSearch = '';
        $this->classPage = 1;
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
                            ->helperText('Note what the student still needs to complete (e.g., final exam or lab report).')
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
            (int) ($row['id'] ?? 0) => [
                'key' => $key,
                'result' => $row['final_result'] ?? null,
                'inc_completion_note' => $row['inc_completion_note'] ?? null,
            ],
        ]);

        return collect($roster?->rows ?? [])
            ->where('is_current_membership', true)
            ->map(function (GradeRosterRow $row) use ($drafts): array {
                $student = $row->courseEnrollment?->enrollment?->studentProfile;
                $draft = $drafts->get($row->id);

                return [
                    'id' => $row->id,
                    'key' => $draft['key'] ?? null,
                    'legal_name' => collect([$student?->last_name, collect([$student?->first_name, $student?->middle_name])->filter()->implode(' ')])->filter()->implode(', '),
                    'student_number' => $student?->student_number,
                    'initials' => str($student?->first_name)->substr(0, 1).str($student?->last_name)->substr(0, 1),
                    'final_result' => $draft !== null ? $draft['result'] : $row->final_result,
                    'inc_completion_note' => $draft !== null ? $draft['inc_completion_note'] : $row->inc_completion_note,
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

        $missingFinalResults = $all->filter(fn (array $student): bool => blank($student['final_result']))->count();
        $missingIncNotes = $all->filter(
            fn (array $student): bool => $student['final_result'] === 'INC' && blank($student['inc_completion_note'] ?? null),
        )->count();

        return [
            'students' => $students->forPage($page, 10),
            'total' => $all->count(),
            'hasUnsavedChanges' => $this->savedDraftSignature !== $this->draftSignature(),
            'filtered' => $students->count(),
            'recorded' => $all->count() - $missingFinalResults,
            'missingFinalResults' => $missingFinalResults,
            'missingIncNotes' => $missingIncNotes,
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
            'entryWindow' => app(GradeWindowService::class)->entryContext($roster),
            'designated' => (int) $roster->teachingAssignment?->faculty_user_id === (int) Auth::id(),
            'hasMultipleClasses' => $this->accessibleRosters()->count() > 1,
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
                ->label('Print class list')
                ->color('gray')
                ->icon(Heroicon::OutlinedPrinter)
                ->visible(fn (): bool => $this->hasCurrentRosterLearners())
                ->url(fn (): ?string => $this->rosterId === null ? null : route('grade-rosters.print', $this->rosterId))
                ->openUrlInNewTab(),
            ExportClassRosterAction::make('downloadRoster')
                ->label('Export class list')
                ->modalHeading('Export class list')
                ->record(fn (): GradeRoster => $this->selectedRosterOrFail())
                ->visible(fn (): bool => $this->hasCurrentRosterLearners()),
        ];
    }

    public function viewMasterlistAction(): Action
    {
        return Action::make('viewMasterlist')
            ->label('View masterlist')
            ->modalHeading(function (array $arguments): string {
                $rosterId = (int) ($arguments['roster'] ?? 0);
                if ($rosterId <= 0) {
                    return 'Class masterlist';
                }

                $snapshot = $this->masterlistSnapshotFor($rosterId);
                $title = $snapshot['roster']->termOffering?->curriculumEntry?->courseSpecification?->title;

                return filled($title) ? "Class masterlist · {$title}" : 'Class masterlist';
            })
            ->modalDescription(function (array $arguments): ?string {
                $rosterId = (int) ($arguments['roster'] ?? 0);
                if ($rosterId <= 0) {
                    return null;
                }

                $roster = $this->masterlistSnapshotFor($rosterId)['roster'];

                return collect([
                    $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
                    $roster->section?->code,
                    $roster->termOffering?->term?->academicYear?->label,
                    $roster->termOffering?->term?->label,
                ])->filter()->implode(' · ') ?: null;
            })
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->extraModalFooterActions(function (array $arguments): array {
                $rosterId = (int) ($arguments['roster'] ?? 0);
                if ($rosterId <= 0) {
                    return [];
                }

                $snapshot = $this->masterlistSnapshotFor($rosterId);
                $roster = $snapshot['roster'];
                $canOutput = $snapshot['isCurrent'] && $snapshot['count'] > 0;
                $actions = [];

                if ($canOutput) {
                    $actions[] = Action::make('printMasterlist')
                        ->label('Print masterlist')
                        ->color('gray')
                        ->icon(Heroicon::OutlinedPrinter)
                        ->url(route('grade-rosters.print', $roster))
                        ->openUrlInNewTab();

                    $actions[] = Action::make('exportMasterlistCsv')
                        ->label('Export CSV (Excel)')
                        ->color('gray')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->url(route('grade-rosters.csv', $roster));
                }

                $actions[] = Action::make('goToGrading')
                    ->label('Go to grading')
                    ->color('primary')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(static::getUrl(['roster' => $roster->id]));

                return $actions;
            })
            ->modalContent(function (array $arguments): View {
                $snapshot = $this->masterlistSnapshotFor((int) ($arguments['roster'] ?? 0));
                $roster = $snapshot['roster'];

                $students = $snapshot['isCurrent']
                    ? $roster->rows->map(function (GradeRosterRow $row): array {
                        $enrollment = $row->courseEnrollment?->enrollment;
                        $student = $enrollment?->studentProfile;
                        $givenNames = collect([$student?->first_name, $student?->middle_name])->filter()->implode(' ');

                        return [
                            'id' => $row->id,
                            'student_number' => (string) ($student?->student_number ?? ''),
                            'legal_name' => collect([$student?->last_name, $givenNames])->filter()->implode(', '),
                            'program' => $student?->program?->code,
                            'enrollment_state' => str($enrollment?->canonical_outcome ?? $enrollment?->status ?? 'Officially enrolled')->headline()->toString(),
                        ];
                    })->values()->all()
                    : [];

                return view('filament.components.faculty-class-masterlist-modal', [
                    'roster' => $roster,
                    'isCurrent' => $snapshot['isCurrent'],
                    'count' => $snapshot['count'],
                    'students' => $students,
                    'generatedAt' => now('Asia/Manila'),
                ]);
            });
    }

    public function submitIncCompletionAction(): Action
    {
        return Action::make('submitIncCompletion')
            ->label('Submit INC completion')
            ->modalHeading('Submit INC completion')
            ->modalDescription(function (): ?string {
                $row = $this->selectedIncRow();
                if (! $row instanceof GradeRosterRow) {
                    return null;
                }

                $student = $row->courseEnrollment?->enrollment?->studentProfile;
                $studentLabel = collect([
                    collect([$student?->last_name, $student?->first_name])->filter()->implode(', '),
                    $student?->student_number,
                ])->filter()->implode(' · ');

                return $studentLabel !== '' ? "Student: {$studentLabel}" : null;
            })
            ->modalSubmitActionLabel('Submit completion for review')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->visible(fn (): bool => $this->selectedStudentCanSubmitIncCompletion())
            ->schema([
                Select::make('proposed_result')
                    ->label('Completed final result')
                    ->options(fn (): array => collect(app(FinalResultPolicy::class)->options())->except('INC')->all())
                    ->required(),
                Textarea::make('completion_note')
                    ->label('Completion evidence note')
                    ->helperText('Summarize what the student completed (e.g., final exam or lab report) without sensitive details.')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(function (array $data): void {
                $row = $this->selectedIncRow();
                if (! $row instanceof GradeRosterRow || ! $this->selectedStudentCanSubmitIncCompletion()) {
                    Notification::make()->title('Completion was not submitted')->body('Select an eligible INC student before submitting a completion.')->danger()->send();
                    throw new Halt;
                }

                $inc = $row->outcomeEvents()->where('result_code', 'INC')->whereNotNull('released_at')->latest('id')->firstOrFail();
                try {
                    app(SubmitIncCompletion::class)->execute($inc, (string) $data['proposed_result'], (string) $data['completion_note'], $this->authenticatedUser());
                } catch (RuntimeException $exception) {
                    Notification::make()->title('Completion was not submitted')->body($exception->getMessage())->danger()->send();
                    throw new Halt;
                }

                $this->membershipSnapshots = [];
                Notification::make()->title('INC completion submitted for Registrar review')->success()->send();
            });
    }

    public function submitAction(): Action
    {
        return Action::make('submit')
            ->label('Submit class grades')
            ->modalHeading('Submit class grades?')
            ->modalSubmitActionLabel('Submit class grades')
            ->modalDescription(fn (): string => $this->submissionSummary())
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => $this->canEditSelectedRoster())
            ->action(function (): void {
                try {
                    $saved = $this->persistDraft(submitting: true);
                    app(SubmitGradeRoster::class)->execute($saved, $this->authenticatedUser(), $this->reviewedVersion);
                } catch (RuntimeException $exception) {
                    Notification::make()->title('Class grades were not submitted')->body($exception->getMessage())->danger()->send();

                    return;
                }
                $this->fillRosterForm();
                Notification::make()->title('Class grades submitted for Registrar review')->success()->send();
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
        return $this->selectedRoster() ?? throw new RuntimeException('Select an accessible class first.');
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
            Notification::make()->title('Class membership changed')->body('Ask the Registrar to refresh this class list, then reload before saving.')->danger()->send();
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

    public function selectedStudentCanSubmitIncCompletion(): bool
    {
        $roster = $this->selectedRoster();

        if (! $roster instanceof GradeRoster || $this->selectedStudentId === null) {
            return false;
        }

        return array_key_exists($this->selectedStudentId, $this->incCompletionOptionsFor($roster));
    }

    private function selectedIncRow(): ?GradeRosterRow
    {
        if ($this->selectedStudentId === null) {
            return null;
        }

        return $this->selectedRoster()?->rows->firstWhere('id', $this->selectedStudentId);
    }

    /** @return array<int, string> */
    private function incCompletionOptionsFor(GradeRoster $roster): array
    {
        if ($roster->state !== GradeRoster::StateReleased
            || $roster->teachingAssignment?->state !== ClassOfferingTeachingAssignment::StateActive
            || $roster->teachingAssignment?->role !== ClassOfferingTeachingAssignment::RoleDesignated
            || (int) $roster->teachingAssignment?->faculty_user_id !== (int) Auth::id()) {
            return [];
        }

        $roster->rows->loadMissing('latestReleasedOutcome.completionSubmissions');

        return $roster->rows->filter(fn (GradeRosterRow $row): bool => (bool) $row->is_current_membership
            && $row->current_outcome_code === 'INC'
            && $row->latestReleasedOutcome?->result_code === 'INC'
            && ! $row->latestReleasedOutcome->completionSubmissions->contains('state', IncCompletionSubmission::StateSubmitted)
            && app(IncDeadlineService::class)->state($row->latestReleasedOutcome) === IncDeadlineService::StateCompletionOpen)
            ->mapWithKeys(function (GradeRosterRow $row): array {
                $student = $row->courseEnrollment?->enrollment?->studentProfile;

                return [$row->id => collect([$student?->student_number, $student?->last_name, $student?->first_name])->filter()->implode(' · ')];
            })->all();
    }

    /**
     * @param  Collection<int, Term>  $assignedTerms
     */
    private function resolveActiveScopeTerm(Collection $assignedTerms, FacultyActiveTermResolver $resolver): ?Term
    {
        $resolved = $resolver->resolve($this->authenticatedUser());

        if ($resolved instanceof Term && $assignedTerms->contains('id', $resolved->id)) {
            return $resolved;
        }

        $assignedActive = $assignedTerms->first(
            fn (Term $term): bool => $term->state === Term::StateActive,
        );

        if ($assignedActive instanceof Term) {
            return $assignedActive;
        }

        return $resolved;
    }

    /**
     * @param  Collection<int, Term>  $assignedTerms
     * @return array{0: string, 1: string}
     */
    private function buildIndexScopeSummary(
        FacultyActiveTermResolver $resolver,
        Collection $assignedTerms,
        ?Term $activeScopeTerm,
        int $explicitTermFilter,
    ): array {
        if ($explicitTermFilter > 0) {
            $scopedTerm = $assignedTerms->firstWhere('id', $explicitTermFilter);

            if (! $scopedTerm instanceof Term) {
                return [
                    'Term no longer assigned',
                    'Clear filters to return to your active term classes.',
                ];
            }

            return [
                $resolver->scopeTitle($scopedTerm),
                $this->queue === 'inc'
                    ? 'Showing classes with open INC completions in this term.'
                    : 'Filtered term view.',
            ];
        }

        if ($this->queue === 'inc') {
            return [
                'All assigned terms',
                'Showing classes with open INC completions across terms.',
            ];
        }

        if ($activeScopeTerm instanceof Term) {
            $hasActiveCalendar = TermCalendarPackage::query()
                ->where('term_id', $activeScopeTerm->id)
                ->where('state', TermCalendarPackage::StateActive)
                ->exists();

            return [
                $resolver->scopeTitle($activeScopeTerm),
                $resolver->scopeMeta($activeScopeTerm, $hasActiveCalendar),
            ];
        }

        return [
            'No active term',
            'Assigned classes will appear once an active term is configured.',
        ];
    }

    /**
     * @param  Collection<int, GradeRoster>  $pageRosters
     * @return Collection<int, array<string, mixed>>
     */
    private function buildClassIndexRows(Collection $pageRosters): Collection
    {
        if ($pageRosters->isEmpty()) {
            return collect();
        }

        $pageRosters->load([
            'rows' => fn (HasMany $query): HasMany => $query
                ->where('is_current_membership', true)
                ->select(['id', 'grade_roster_id', 'course_enrollment_id', 'final_result', 'current_outcome_code', 'is_current_membership'])
                ->with([
                    'latestReleasedOutcome' => fn ($outcome) => $outcome->with([
                        'completionSubmissions:id,grade_outcome_event_id,state',
                        'deadlineAmendments:id,grade_outcome_event_id,new_deadline,recorded_at',
                    ]),
                ]),
        ]);

        $officialIdsByRosterKey = $this->batchedOfficialEnrollmentIds($pageRosters);
        $openIncCounts = $this->batchedOpenIncCounts($pageRosters);
        $entryContexts = $this->batchedEntryContexts($pageRosters);

        return $pageRosters->map(function (GradeRoster $roster) use ($officialIdsByRosterKey, $openIncCounts, $entryContexts): array {
            $rosterKey = $roster->term_offering_id.':'.$roster->section_id;
            $officialIds = $officialIdsByRosterKey[$rosterKey] ?? [];
            $rosterIds = $roster->rows
                ->pluck('course_enrollment_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $isCurrent = $officialIds === $rosterIds;
            $count = $isCurrent ? $roster->rows->count() : null;
            $gradedCount = null;
            $openIncCount = 0;

            if ($isCurrent) {
                $gradedCount = $roster->rows->filter(
                    fn (GradeRosterRow $row): bool => filled($roster->state === GradeRoster::StateReleased ? $row->current_outcome_code : $row->final_result),
                )->count();

                if ($roster->state === GradeRoster::StateReleased) {
                    $openIncCount = $openIncCounts[$roster->id] ?? 0;
                }
            }

            return [
                'roster' => $roster,
                'current' => $isCurrent,
                'count' => $count,
                'graded' => $gradedCount,
                'openIncCount' => $openIncCount,
                'entryWindow' => $entryContexts[$roster->id] ?? [
                    'is_open' => false,
                    'basis' => 'Grade Entry dates not configured',
                    'opens_at' => null,
                    'closes_at' => null,
                    'reference' => null,
                ],
            ];
        });
    }

    /**
     * @param  Collection<int, GradeRoster>  $rosters
     * @return array<string, list<int>>
     */
    private function batchedOfficialEnrollmentIds(Collection $rosters): array
    {
        $offeringIds = $rosters->pluck('term_offering_id')->filter()->unique()->values();
        $sectionIds = $rosters->pluck('section_id')->filter()->unique()->values();

        if ($offeringIds->isEmpty() || $sectionIds->isEmpty()) {
            return [];
        }

        $enrollments = CourseEnrollment::query()
            ->select(['id', 'term_offering_id', 'section_id'])
            ->whereIn('term_offering_id', $offeringIds)
            ->where('status', CourseEnrollment::StatusActive)
            ->where('is_current', true)
            ->whereHas('enrollment', fn (Builder $query): Builder => $query->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled))
            ->where(function (Builder $query) use ($sectionIds): void {
                $query->whereIn('section_id', $sectionIds)
                    ->orWhereHas('seatReservations', fn (Builder $reservations): Builder => $reservations->whereIn('section_id', $sectionIds)->where('status', 'ACTIVE'));
            })
            ->with(['seatReservations' => fn (HasMany $reservations): HasMany => $reservations
                ->select(['id', 'course_enrollment_id', 'section_id', 'status'])
                ->whereIn('section_id', $sectionIds)
                ->where('status', 'ACTIVE')])
            ->orderBy('id')
            ->get();

        $sectionLookup = array_flip($sectionIds->map(fn (mixed $id): int => (int) $id)->all());
        $grouped = [];

        foreach ($enrollments as $courseEnrollment) {
            $matchedSectionIds = [];

            if ($courseEnrollment->section_id !== null && isset($sectionLookup[(int) $courseEnrollment->section_id])) {
                $matchedSectionIds[(int) $courseEnrollment->section_id] = true;
            }

            foreach ($courseEnrollment->seatReservations as $reservation) {
                if ($reservation->section_id !== null && isset($sectionLookup[(int) $reservation->section_id])) {
                    $matchedSectionIds[(int) $reservation->section_id] = true;
                }
            }

            foreach (array_keys($matchedSectionIds) as $sectionId) {
                $key = $courseEnrollment->term_offering_id.':'.$sectionId;
                $grouped[$key][] = (int) $courseEnrollment->id;
            }
        }

        foreach ($grouped as $key => $ids) {
            $unique = array_values(array_unique($ids));
            sort($unique);
            $grouped[$key] = $unique;
        }

        return $grouped;
    }

    /**
     * @param  Collection<int, GradeRoster>  $rosters
     * @return array<int, int>
     */
    private function batchedOpenIncCounts(Collection $rosters): array
    {
        $userId = (int) Auth::id();
        $eligibleRosters = $rosters->filter(fn (GradeRoster $roster): bool => $roster->state === GradeRoster::StateReleased
            && $roster->teachingAssignment?->state === ClassOfferingTeachingAssignment::StateActive
            && $roster->teachingAssignment?->role === ClassOfferingTeachingAssignment::RoleDesignated
            && (int) $roster->teachingAssignment?->faculty_user_id === $userId);

        if ($eligibleRosters->isEmpty()) {
            return [];
        }

        $incOutcomeIds = $eligibleRosters
            ->flatMap(fn (GradeRoster $roster) => $roster->rows)
            ->filter(fn (GradeRosterRow $row): bool => (bool) $row->is_current_membership
                && $row->current_outcome_code === 'INC'
                && $row->latestReleasedOutcome?->result_code === 'INC'
                && $row->latestReleasedOutcome->deadline !== null)
            ->map(fn (GradeRosterRow $row): int => (int) $row->latestReleasedOutcome->id)
            ->unique()
            ->values();

        if ($incOutcomeIds->isEmpty()) {
            return [];
        }

        $resolvedOutcomeIds = GradeOutcomeEvent::query()
            ->whereIn('predecessor_event_id', $incOutcomeIds)
            ->whereNotNull('released_at')
            ->pluck('predecessor_event_id')
            ->flip();

        $todayManila = now('Asia/Manila')->toDateString();
        $counts = [];

        foreach ($eligibleRosters as $roster) {
            $count = 0;

            foreach ($roster->rows as $row) {
                if (! $row->is_current_membership || $row->current_outcome_code !== 'INC') {
                    continue;
                }

                $outcome = $row->latestReleasedOutcome;
                if (! $outcome instanceof GradeOutcomeEvent || $outcome->result_code !== 'INC' || $outcome->deadline === null) {
                    continue;
                }

                if ($resolvedOutcomeIds->has($outcome->id)) {
                    continue;
                }

                if ($outcome->completionSubmissions->contains('state', IncCompletionSubmission::StateSubmitted)) {
                    continue;
                }

                /** @var IncDeadlineAmendment|null $amended */
                $amended = $outcome->deadlineAmendments
                    ->sort(function (IncDeadlineAmendment $left, IncDeadlineAmendment $right): int {
                        $timeCompare = ($right->recorded_at?->getTimestamp() ?? 0) <=> ($left->recorded_at?->getTimestamp() ?? 0);

                        return $timeCompare !== 0 ? $timeCompare : ($right->id <=> $left->id);
                    })
                    ->first();

                $deadline = $amended instanceof IncDeadlineAmendment
                    ? $amended->new_deadline
                    : $outcome->deadline;

                if ($todayManila <= $deadline->copy()->startOfDay()->toDateString()) {
                    $count++;
                }
            }

            $counts[$roster->id] = $count;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, GradeRoster>  $rosters
     * @return array<int, array{is_open: bool, basis: string, opens_at: mixed, closes_at: mixed, reference: ?string}>
     */
    private function batchedEntryContexts(Collection $rosters): array
    {
        if ($rosters->isEmpty()) {
            return [];
        }

        $nowUtc = now()->copy()->utc();
        $calendarGate = app(CalendarPhaseGateService::class);

        $openTermIds = $rosters
            ->map(fn (GradeRoster $roster): ?Term => $roster->termOffering?->term)
            ->filter(fn (?Term $term): bool => $term instanceof Term && $term->state !== Term::StateClosed)
            ->pluck('id')
            ->unique()
            ->values();

        /** @var Collection<int, TermCalendarWindow> $windowsByTermId */
        $windowsByTermId = collect();

        if ($openTermIds->isNotEmpty()) {
            $windows = TermCalendarWindow::query()
                ->select('term_calendar_windows.*')
                ->join('term_calendar_packages', 'term_calendar_packages.id', '=', 'term_calendar_windows.term_calendar_package_id')
                ->whereIn('term_calendar_packages.term_id', $openTermIds)
                ->where('term_calendar_packages.state', TermCalendarPackage::StateActive)
                ->where('term_calendar_windows.window_type', TermCalendarWindow::TypeGradeEntry)
                ->with('package:id,term_id,version,authority_reference')
                ->orderByDesc('term_calendar_packages.version')
                ->orderBy('term_calendar_windows.id')
                ->get();

            foreach ($windows as $window) {
                $termId = (int) ($window->package?->term_id ?? 0);
                if ($termId > 0 && ! $windowsByTermId->has($termId)) {
                    $windowsByTermId->put($termId, $window);
                }
            }
        }

        $lateAuthsByRosterId = LateGradeAuthorization::query()
            ->whereIn('grade_roster_id', $rosters->pluck('id')->all())
            ->where('grading_period', LateGradeAuthorization::PeriodFinal)
            ->where('state', LateGradeAuthorization::StateActive)
            ->whereHas('approver.roles', fn (Builder $query): Builder => $query
                ->where('name', User::StaffRoleRegistrar)
                ->where('guard_name', 'web'))
            ->where('opens_at', '<=', $nowUtc)
            ->where('closes_at', '>=', $nowUtc)
            ->latest('id')
            ->get()
            ->groupBy('grade_roster_id');

        $contexts = [];

        foreach ($rosters as $roster) {
            $term = $roster->termOffering?->term;

            if ($term instanceof Term && $term->state === Term::StateClosed) {
                $contexts[$roster->id] = [
                    'is_open' => false,
                    'basis' => 'Term is closed',
                    'opens_at' => null,
                    'closes_at' => null,
                    'reference' => null,
                ];

                continue;
            }

            $termId = (int) ($roster->termOffering?->term_id ?? 0);
            $window = $termId > 0 ? $windowsByTermId->get($termId) : null;
            $bounds = $window instanceof TermCalendarWindow ? $calendarGate->windowBounds($window) : null;

            if ($bounds !== null && $nowUtc->betweenIncluded($bounds['opens_at'], $bounds['closes_at'])) {
                $contexts[$roster->id] = [
                    'is_open' => true,
                    'basis' => 'Grade Entry window',
                    ...$bounds,
                    'reference' => $window->package?->authority_reference,
                ];

                continue;
            }

            /** @var LateGradeAuthorization|null $late */
            $late = $lateAuthsByRosterId
                ->get($roster->id, collect())
                ->first(fn (LateGradeAuthorization $auth): bool => (int) $auth->term_offering_id === (int) $roster->term_offering_id
                    && (int) $auth->faculty_user_id === (int) $roster->faculty_user_id);

            if ($late instanceof LateGradeAuthorization) {
                $contexts[$roster->id] = [
                    'is_open' => true,
                    'basis' => 'Authorized late grade entry',
                    'opens_at' => $late->opens_at,
                    'closes_at' => $late->closes_at,
                    'reference' => null,
                ];

                continue;
            }

            $contexts[$roster->id] = [
                'is_open' => false,
                'basis' => $window instanceof TermCalendarWindow ? 'Grade Entry window closed' : 'Grade Entry dates not configured',
                'opens_at' => $bounds['opens_at'] ?? null,
                'closes_at' => $bounds['closes_at'] ?? null,
                'reference' => $window?->package?->authority_reference,
            ];
        }

        return $contexts;
    }

    /** @return list<int> */
    private function queueMatchingRosterIds(string $queue, int $termFilter = 0): array
    {
        if ($queue === 'inc') {
            $rosters = $this->accessibleRosters()
                ->where('state', GradeRoster::StateReleased)
                ->when($termFilter > 0, fn (Builder $query): Builder => $query->whereHas(
                    'termOffering',
                    fn (Builder $offering): Builder => $offering->where('term_id', $termFilter),
                ))
                ->with([
                    'teachingAssignment',
                    'rows' => fn (HasMany $query): HasMany => $query
                        ->where('is_current_membership', true)
                        ->select(['id', 'grade_roster_id', 'current_outcome_code', 'is_current_membership'])
                        ->with([
                            'latestReleasedOutcome' => fn ($outcome) => $outcome->with([
                                'completionSubmissions:id,grade_outcome_event_id,state',
                                'deadlineAmendments:id,grade_outcome_event_id,new_deadline,recorded_at',
                            ]),
                        ]),
                ])
                ->get();

            $counts = $this->batchedOpenIncCounts($rosters);

            return $rosters
                ->filter(fn (GradeRoster $roster): bool => ($counts[$roster->id] ?? 0) > 0)
                ->pluck('id')
                ->all();
        }

        if ($queue === 'needs-action') {
            $userId = (int) Auth::id();

            $rosters = $this->accessibleRosters()
                ->whereIn('state', [
                    GradeRoster::StateReturned,
                    GradeRoster::StateDraft,
                    GradeRoster::StateLateNotSubmitted,
                ])
                ->when($termFilter > 0, fn (Builder $query): Builder => $query->whereHas(
                    'termOffering',
                    fn (Builder $offering): Builder => $offering->where('term_id', $termFilter),
                ))
                ->with(['termOffering.term', 'teachingAssignment'])
                ->get()
                ->filter(fn (GradeRoster $roster): bool => (int) $roster->teachingAssignment?->faculty_user_id === $userId)
                ->values();

            $draftRosters = $rosters->where('state', GradeRoster::StateDraft)->values();
            $entryContexts = $draftRosters->isEmpty() ? [] : $this->batchedEntryContexts($draftRosters);

            return $rosters
                ->filter(fn (GradeRoster $roster): bool => match ($roster->state) {
                    GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted => true,
                    GradeRoster::StateDraft => (bool) ($entryContexts[$roster->id]['is_open'] ?? false),
                    default => false,
                })
                ->pluck('id')
                ->all();
        }

        return [];
    }

    private function normalizeQueue(): void
    {
        if (! in_array($this->queue, ['all', 'needs-action', 'inc'], true)) {
            $this->queue = 'all';
        }
    }

    private function submissionSummary(): string
    {
        $rows = collect($this->form->getRawState()['rows'] ?? []);
        $missingResults = $rows->filter(fn (array $row): bool => blank($row['final_result'] ?? null))->count();
        $missingIncNotes = $rows->filter(
            fn (array $row): bool => ($row['final_result'] ?? null) === 'INC' && blank($row['inc_completion_note'] ?? null),
        )->count();

        if ($missingResults > 0 && $missingIncNotes > 0) {
            return "Complete {$missingResults} missing final ".str('result')->plural($missingResults)." and {$missingIncNotes} missing INC completion ".str('note')->plural($missingIncNotes).' before submission.';
        }

        if ($missingResults > 0) {
            return "Complete {$missingResults} of {$rows->count()} current rows before submission.";
        }

        if ($missingIncNotes > 0) {
            return "Add {$missingIncNotes} missing INC completion ".str('note')->plural($missingIncNotes).' before submission.';
        }

        return "All {$rows->count()} current results will be saved and submitted to the Registrar for review.";
    }

    private function authenticatedUser(): User
    {
        return Auth::user() instanceof User ? Auth::user() : throw new AuthorizationException('Authentication is required.');
    }
}
