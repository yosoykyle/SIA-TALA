<?php

namespace App\Filament\Pages;

use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Completion\CorrectDegreeConferral;
use App\Actions\Completion\OfficialOutputRestrictionService;
use App\Actions\Completion\RecordDegreeConferral;
use App\Actions\Completion\RecordTranscriptRequest;
use App\Actions\Completion\TranscriptLifecycleProjection;
use App\Actions\Completion\TranscriptPreview;
use App\Actions\Completion\TranscriptSignatory;
use App\Filament\Resources\TranscriptRequests\Concerns\InteractsWithTranscriptActions;
use App\Models\DegreeConferral;
use App\Models\StudentProfile;
use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class CompletionAndTor extends Page implements HasTable
{
    use InteractsWithTable;
    use InteractsWithTranscriptActions;

    #[Url(as: 'work')]
    public string $completionView = 'tor';

    #[Url(as: 'request')]
    public ?int $torRequestId = null;

    #[Url(as: 'find')]
    public string $torSearch = '';

    #[Url(as: 'status')]
    public string $torScope = '';

    #[Url(as: 'student')]
    public ?int $graduationStudentId = null;

    #[Url(as: 'confirm')]
    public string $torConfirmation = '';

    #[Locked]
    public ?int $graduationActionStudentId = null;

    #[Locked]
    public ?int $graduationActionConferralId = null;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Completion & TOR';

    protected string $view = 'filament.pages.completion-and-tor';

    protected array $extraBodyAttributes = ['class' => 'tala-completion-and-tor-page'];

    /** @var array<int, array<string, mixed>> */
    private array $readinessCache = [];

    public function mount(): void
    {
        $this->completionView = auth()->user()?->hasRole(User::StaffRoleRegistrar) && $this->completionView !== 'graduation' ? 'tor' : 'graduation';
    }

    public function bootInteractsWithTranscriptActions(): void
    {
        $this->cacheAction($this->setTorSignatoryAction());
        $this->cacheAction($this->reviewTorAction());
        foreach ($this->transcriptActions() as $action) {
            $this->cacheAction($action);
        }
    }

    #[Computed]
    public function torRequests(): LengthAwarePaginator
    {
        abort_unless(auth()->user()?->hasRole(User::StaffRoleRegistrar), 403);

        return TranscriptRequest::query()->with(['studentProfile.program', 'studentProfile.user', 'officialOutputRestrictions', 'events', 'snapshots.events'])
            ->when(filled($this->torSearch), fn (Builder $query) => $query->where(function (Builder $query): void {
                $query->where('external_request_reference', 'like', '%'.$this->torSearch.'%')
                    ->orWhereHas('studentProfile', fn (Builder $student) => $student->where('last_name', 'like', '%'.$this->torSearch.'%')
                        ->orWhere('first_name', 'like', '%'.$this->torSearch.'%')->orWhere('student_number', 'like', '%'.$this->torSearch.'%'));
            }))
            ->when(in_array($this->torScope, [TranscriptRequest::StateOpen, TranscriptRequest::StateIssued], true), fn (Builder $query) => $query->where('state', $this->torScope))
            ->orderBy('due_on')->orderBy('id')->paginate(10, ['*'], 'torPage');
    }

    public function selectedTorRequest(): ?TranscriptRequest
    {
        return $this->torRequestId === null ? $this->torRequests->first() : $this->torRequests->firstWhere('id', $this->torRequestId);
    }

    public function torRequestStatus(TranscriptRequest $request): string
    {
        $status = app(TranscriptLifecycleProjection::class)->statusForRequest($request);
        if ($status !== TranscriptRequest::StateOpen) {
            return $status;
        }
        if ($request->officialOutputRestrictions->whereNull('resolved_at')->isNotEmpty()) {
            return 'Accounting restriction';
        }

        return blank($this->torSignatory['name']) && blank($request->signatory_name)
            ? 'Set TOR signatory' : 'Review sources';
    }

    private function request(): TranscriptRequest
    {
        abort_unless(auth()->user()?->hasRole(User::StaffRoleRegistrar), 403);

        return $this->selectedTorRequest() ?? abort(404);
    }

    public function selectTorRequest(int $requestId): void
    {
        abort_unless($this->torRequests->contains('id', $requestId), 404);
        $this->torRequestId = $requestId;
        $this->resetPage('torHistoryPage');
        unset($this->torHistory);
        $this->resetErrorBag();
        $this->dispatch('record-selected');
    }

    public function updatedCompletionView(): void
    {
        abort_unless(in_array($this->completionView, ['tor', 'graduation'], true), 404);
        abort_if($this->completionView === 'tor' && ! auth()->user()?->hasRole(User::StaffRoleRegistrar), 403);
        $this->resetErrorBag();
    }

    public function selectCompletionView(string $view): void
    {
        abort_unless(in_array($view, ['tor', 'graduation'], true), 404);
        abort_if($view === 'tor' && ! auth()->user()?->hasRole(User::StaffRoleRegistrar), 403);
        $this->completionView = $view;
        $this->resetErrorBag();
    }

    #[Computed]
    public function torHistory(): LengthAwarePaginator
    {
        $request = $this->request();
        $total = $request->events()->count() + 1;
        $page = min(max(1, $this->getPage('torHistoryPage')), (int) ceil($total / 10));
        $entries = $request->events()->with('recorder')->orderByDesc('id')
            ->offset(($page - 1) * 10)->limit(10)->get()
            ->map(fn (TranscriptIssuanceEvent $event): array => ['event' => $event])->toBase();
        if ($page === (int) ceil($total / 10)) {
            $entries->push(['event' => null]);
        }

        return new LengthAwarePaginator($entries, $total, 10, $page, ['pageName' => 'torHistoryPage']);
    }

    public function gotoTorHistoryPage(int $page): void
    {
        $this->request();
        $this->setPage(max(1, $page), 'torHistoryPage');
        unset($this->torHistory);
    }

    public function gotoGraduationHistoryPage(int $page): void
    {
        abort_unless($this->selectedGraduationStudent(), 404);
        $this->setPage(max(1, $page), 'graduationHistoryPage');
    }

    public function confirmReviewedTor(): void
    {
        if (! in_array($this->torConfirmation, ['issue', 'replaceLatest'], true)) {
            return;
        }
        $this->actor();
        $operation = $this->torConfirmation === 'issue' ? 'issue' : 'replacement';
        if ($this->hasUncompletedTorReview($operation)) {
            $this->mountAction($this->torConfirmation);
        } else {
            Notification::make()->title('Review the TOR first')->body('Open the current preview before confirming issuance.')->warning()->send();
        }
        $this->torConfirmation = '';
    }

    public function clearTorFilters(): void
    {
        $this->torSearch = '';
        $this->torScope = '';
        $this->openTorPage(1);
    }

    public function updatedTorSearch(): void
    {
        $this->openTorPage(1);
    }

    public function updatedTorScope(): void
    {
        $this->openTorPage(1);
    }

    public function gotoTorPage(int $page): void
    {
        $this->openTorPage($page);
    }

    private function openTorPage(int $page): void
    {
        $this->setPage(max(1, $page), 'torPage');
        unset($this->torRequests);
        $this->torRequestId = null;
        $this->resetPage('torHistoryPage');
        unset($this->torHistory);
    }

    /** @return array{name: ?string, title: ?string, source_version: ?int} */
    #[Computed]
    public function torSignatory(): array
    {
        return app(TranscriptSignatory::class)->current();
    }

    public function setTorSignatoryAction(): Action
    {
        return Action::make('setTorSignatory')->label('Set TOR signatory')->color('gray')->icon('heroicon-o-cog-6-tooth')
            ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
            ->modalHeading('Name printed on the TOR')
            ->modalDescription('Enter the school-confirmed signatory. These details are reused on new TORs. Issued documents keep their original certification.')
            ->schema([
                TextInput::make('name')->label('Signatory name')->required()->maxLength(255),
                TextInput::make('title')->label('Position or title')->required()->maxLength(255),
            ])->fillForm(fn (): array => $this->torSignatory)
            ->modalSubmitActionLabel('Save TOR signatory')
            ->action(function (array $data): void {
                app(TranscriptSignatory::class)->save($this->actor(), $data['name'], $data['title']);
                unset($this->torSignatory);
                Notification::make()->title('TOR signatory saved')->body('Review each document before issuance.')->success()->send();
            });
    }

    public function reviewTorAction(): Action
    {
        return Action::make('reviewTor')->label('Review TOR')->icon('heroicon-o-eye')
            ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                && ($this->currentSnapshot() === null))
            ->disabled(fn (): bool => $this->torDetail()['blocker'] !== null)
            ->action(function (): void {
                $request = $this->request();
                $this->actor();
                if ($request->snapshots()->doesntExist()) {
                    $request->update(['template_version' => TranscriptRequest::TemplateServitechV2]);
                }
                $parameters = ['transcriptRequest' => $request];
                if ($this->latestSnapshotIsVoided()) {
                    $parameters += ['operation' => 'replacement', 'predecessor' => $this->latestSnapshotOrFail()->id];
                }
                $this->redirect(route('transcripts.preview', $parameters));
            });
    }

    /** @return array<string, mixed> */
    public function torDetail(): array
    {
        $request = $this->request();
        $request->loadMissing(['studentProfile.curriculumVersion', 'officialOutputRestrictions', 'events.recorder']);
        $current = app(TranscriptLifecycleProjection::class)->currentSnapshot($request);
        $restrictions = app(OfficialOutputRestrictionService::class)->activeFor($request->studentProfile);
        $blocker = null;
        try {
            $previewSource = clone $request;
            if ($request->snapshots->isEmpty()) {
                $previewSource->template_version = TranscriptRequest::TemplateServitechV2;
            }
            app(TranscriptPreview::class)->forRequest($previewSource);
        } catch (ValidationException $exception) {
            $blocker = collect($exception->errors())->flatten()->implode(' ');
        }

        $replacement = $this->latestSnapshotIsVoided();

        return compact('request', 'current', 'restrictions', 'blocker', 'replacement');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead]) ?? false;
    }

    public function getSubheading(): ?string
    {
        return auth()->user()?->hasRole(User::StaffRoleRegistrar)
            ? 'Review graduation requirements or handle transcript requests.'
            : 'Inspect current completion requirements and recorded graduation results. The Registrar records graduation and handles TOR requests.';
    }

    public function getBreadcrumbs(): array
    {
        return auth()->user()?->hasRole(User::StaffRoleRegistrar)
            ? [GradesAndCompletion::getUrl() => 'Grades & Completion', 'Completion & TOR']
            : [AcademicApprovals::getUrl() => 'Academic Oversight', 'Completion & TOR'];
    }

    public function getHeader(): ?View
    {
        return view('filament.pages.partials.completion-header');
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        if (! (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)) {
            return [];
        }

        return [
            Action::make('recordTorRequest')->label('Record TOR request')->icon('heroicon-o-document-plus')
                ->visible(fn (): bool => $this->completionView === 'tor')
                ->modalSubmitActionLabel('Record request')
                ->modalDescription('Choose the student and enter when the school received the request. TALA creates its tracking reference and due date. Graduation is not required.')
                ->schema([
                    Select::make('student_profile_id')->label('Student')->searchable()
                        ->placeholder('Search by student name or number')
                        ->searchPrompt('Type a student name or student number')
                        ->getSearchResultsUsing(fn (string $search): array => $this->studentOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => $this->studentOptionLabel($value))->required(),
                    DatePicker::make('requested_on')->label('Request received on')->maxDate(today())->required(),
                    Hidden::make('request_reference')->default(fn (): string => 'TR-'.now()->format('Y').'-'.strtoupper(Str::random(10)))->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        $request = app(RecordTranscriptRequest::class)->execute(
                            StudentProfile::query()->findOrFail((int) $data['student_profile_id']), $this->actor(),
                            (string) $data['request_reference'], (string) $data['requested_on'],
                        );
                        if ($request->snapshots()->doesntExist()) {
                            $request->update(['template_version' => TranscriptRequest::TemplateServitechV2]);
                        }
                    } catch (ValidationException $exception) {
                        Notification::make()->title('TOR request was not recorded')->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                        throw new Halt;
                    }
                    $this->torSearch = $request->external_request_reference;
                    $this->torScope = '';
                    $this->openTorPage(1);
                    $this->selectTorRequest($request->id);
                    Notification::make()->title('TOR request recorded')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(StudentProfile::query()->with(['program', 'curriculumVersion', 'degreeConferrals' => fn (HasMany $query): HasMany => $query->whereNotNull('active_scope_key')]))
            ->columns([
                TextColumn::make('student_number')->label('Student')
                    ->weight(FontWeight::SemiBold)->size(TextSize::Medium)
                    ->formatStateUsing(fn (StudentProfile $record): string => $this->studentName($record))
                    ->description(fn (StudentProfile $record): string => $record->student_number.' · '.($record->program?->name ?? 'Program not recorded').' · '.($record->degreeConferrals->isEmpty() ? 'Graduation not recorded' : 'Graduation recorded'))
                    ->searchable(['student_number', 'last_name', 'first_name', 'middle_name'])
                    ->sortable()->wrap(),

            ])
            ->filters([
                SelectFilter::make('program')->relationship('program', 'name')->searchable()->preload(),
                SelectFilter::make('graduation')->label('Graduation result')
                    ->options(['recorded' => 'Recorded', 'not_recorded' => 'Not recorded'])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'recorded' => $query->whereHas('degreeConferrals', fn (Builder $conferrals): Builder => $conferrals->whereNotNull('active_scope_key')),
                            'not_recorded' => $query->whereDoesntHave('degreeConferrals', fn (Builder $conferrals): Builder => $conferrals->whereNotNull('active_scope_key')),
                            default => $query,
                        };
                    }),
            ])
            ->recordClasses(fn (StudentProfile $record): ?string => $this->selectedGraduationStudent()?->id === $record->id ? 'tala-record-table-selected' : null)
            ->recordActions([
                Action::make('selectGraduation')->label('Review')->button()->color('gray')->icon('heroicon-o-arrow-right')
                    ->action(fn (StudentProfile $record) => $this->selectGraduationStudent($record->id)),
            ])
            ->defaultSort('last_name')->defaultPaginationPageOption(10)->paginationPageOptions([10, 25, 50])
            ->stackedOnMobile()
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (): string => $this->hasTableSearch() || $this->getTable()->isFiltered() ? 'No matching students' : 'No student records yet')
            ->emptyStateDescription(fn (): string => $this->hasTableSearch() || $this->getTable()->isFiltered()
                ? 'Clear the search or adjust the filters to find a student.'
                : 'Student records appear after the Registrar establishes the official student record.');
    }

    public function gotoGraduationPage(int $page): void
    {
        $this->setPage(max(1, $page), $this->getTablePaginationPageName());
        $this->graduationStudentId = null;
        $this->resetPage('graduationHistoryPage');
        $this->flushCachedTableRecords();
    }

    public function selectedGraduationStudent(): ?StudentProfile
    {
        $records = $this->getTableRecords();

        return $this->graduationStudentId === null ? $records->first() : $records->firstWhere('id', $this->graduationStudentId);
    }

    public function selectGraduationStudent(int $studentId): void
    {
        abort_unless($this->getTableRecords()->contains('id', $studentId), 404);
        $this->graduationStudentId = $studentId;
        $this->resetPage('graduationHistoryPage');
        $this->dispatch('record-selected');
    }

    /** @return array<string, mixed> */
    public function graduationDetail(): array
    {
        $student = $this->selectedGraduationStudent();
        abort_unless($student instanceof StudentProfile, 404);
        $student->loadMissing('degreeConferrals');

        $query = $student->degreeConferrals()->orderByDesc('version');
        $total = $query->count();
        $page = min(max(1, $this->getPage('graduationHistoryPage')), max(1, (int) ceil($total / 10)));
        $history = new LengthAwarePaginator($query->offset(($page - 1) * 10)->limit(10)->get(), $total, 10, $page, ['pageName' => 'graduationHistoryPage']);

        return ['student' => $student, 'projection' => $this->projection($student), 'history' => $history];
    }

    public function recordGraduationAction(): Action
    {
        return Action::make('recordGraduation')->label('Record graduation')->icon('heroicon-o-check-badge')
            ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                && $this->selectedGraduationStudent() !== null && $this->selectedGraduationStudent()->degreeConferrals->isEmpty())
            ->modalHeading(fn (): string => 'Record graduation for '.$this->studentName($this->selectedGraduationStudent()))
            ->modalDescription('Record the actual institutional degree outcome after reviewing the current requirements. This preserves the final evaluation and marks the student Completed. The graduation date is entered only when supplied by the institution. The recorder and recording time are saved automatically.')
            ->fillForm(function (): array {
                $student = $this->selectedGraduationStudent();
                abort_unless($student instanceof StudentProfile, 404);
                $this->graduationActionStudentId = $student->id;

                return ['degree_name' => $student->program?->name];
            })
            ->modalSubmitActionLabel('Record graduation')
            ->disabled(fn (): bool => $this->projection($this->selectedGraduationStudent())['state'] !== CompletionReadinessProjection::ReadyForConferral)
            ->schema(fn (): array => $this->projection($this->selectedGraduationStudent())['state'] !== CompletionReadinessProjection::ReadyForConferral ? [
                TextEntry::make('blocking_requirements')->label('Resolve these requirements first')
                    ->state(collect($this->projection($this->selectedGraduationStudent())['blockers'])->map(fn (array $blocker): string => $blocker['reason'].' '.$blocker['recovery'])->implode(' ')),
            ] : [
                TextInput::make('degree_name')->label('Degree awarded')->default(fn (): ?string => $this->selectedGraduationStudent()?->program?->name)->required()->maxLength(255),
                DatePicker::make('conferred_on')->label('External graduation date')->helperText('Enter only the actual date supplied by the institution. Leave blank when unavailable.'),
                TextInput::make('honor_text')->label('Recorded honor')->maxLength(255)->live(),
                TextInput::make('honor_authority_reference')->label('Honor authority reference')->maxLength(255)
                    ->required(fn (Get $get): bool => filled($get('honor_text')))
                    ->visible(fn (Get $get): bool => filled($get('honor_text'))),
            ])
            ->action(function (array $data): void {
                $record = StudentProfile::query()->findOrFail($this->graduationActionStudentId);
                try {
                    app(RecordDegreeConferral::class)->execute(
                        $record, $this->actor(), (string) $data['degree_name'],
                        filled($data['conferred_on'] ?? null) ? (string) $data['conferred_on'] : null,
                        filled($data['authority_reference'] ?? null) ? (string) $data['authority_reference'] : null,
                        filled($data['honor_text'] ?? null) ? (string) $data['honor_text'] : null,
                        filled($data['honor_authority_reference'] ?? null) ? (string) $data['honor_authority_reference'] : null,
                    );
                } catch (ValidationException $exception) {
                    unset($this->readinessCache[$record->id]);
                    Notification::make()->title('Graduation was not recorded')
                        ->body(collect($exception->errors())->flatten()->implode(' ').' Review readiness before retrying.')->danger()->send();

                    throw new Halt;
                }

                unset($this->readinessCache[$record->id]);
                $this->flushCachedTableRecords();
                Notification::make()->title('Graduation recorded')->success()->send();
            });
    }

    public function correctGraduationAction(): Action
    {
        return Action::make('correctGraduation')->label('Correct graduation record')->color('gray')->icon('heroicon-o-pencil-square')
            ->visible(fn (): bool => (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                && $this->selectedGraduationStudent()?->degreeConferrals->isNotEmpty())
            ->modalDescription('Record the school-confirmed correction. Earlier graduation results and issued TORs remain in history; affected TORs become superseded.')
            ->fillForm(function (): array {
                $student = $this->selectedGraduationStudent();
                $conferral = $student?->degreeConferrals->first();
                abort_unless($conferral instanceof DegreeConferral, 404);
                $this->graduationActionConferralId = $conferral->id;

                return $conferral->only(['degree_name', 'conferred_on', 'honor_text', 'honor_authority_reference']);
            })
            ->schema([
                TextInput::make('degree_name')->label('Degree awarded')->required()->maxLength(255),
                DatePicker::make('conferred_on')->label('Graduation date')->helperText('Leave blank if the school has not supplied a date.'),
                Textarea::make('reason')->label('What was corrected and why?')->required()->maxLength(1000),
                TextInput::make('honor_text')->label('Recorded honor')->maxLength(255)->live(),
                TextInput::make('honor_authority_reference')->label('Honor authority reference')->maxLength(255)
                    ->visible(fn (Get $get): bool => filled($get('honor_text')))->required(fn (Get $get): bool => filled($get('honor_text'))),
            ])->modalSubmitActionLabel('Record correction')
            ->action(function (array $data): void {
                $conferral = DegreeConferral::query()->findOrFail($this->graduationActionConferralId);
                app(CorrectDegreeConferral::class)->execute(
                    $conferral, $this->actor(), $data['degree_name'], $data['conferred_on'] ?? null, null, $data['reason'],
                    $data['honor_text'] ?? null, $data['honor_authority_reference'] ?? null,
                );
                unset($this->readinessCache[$conferral->student_profile_id]);
                $this->flushCachedTableRecords();
                Notification::make()->title('Graduation correction recorded')->success()->send();
            });
    }

    /** @return array<string, mixed> */
    private function projection(StudentProfile $student): array
    {
        return $this->readinessCache[$student->id] ??= app(CompletionReadinessProjection::class)->forStudent($student);
    }

    private static function readinessLabel(string $state): string
    {
        return match ($state) {
            CompletionReadinessProjection::NotEligible => 'Requirements outstanding',
            CompletionReadinessProjection::AwaitingResultsOrClearance => 'Awaiting results',
            CompletionReadinessProjection::ReadyForConferral => 'Ready to record graduation',
            CompletionReadinessProjection::Conferred => 'Graduation recorded',
            default => 'Review current requirements',
        };
    }

    /** @return array<int, string> */
    public function studentOptions(?string $search = null): array
    {
        return StudentProfile::query()->with('program')
            ->when(filled($search), fn (Builder $query): Builder => $query->where(fn (Builder $identity): Builder => $identity
                ->where('student_number', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%')->orWhere('first_name', 'like', '%'.$search.'%')))
            ->orderBy('last_name')->limit(50)->get()
            ->mapWithKeys(fn (StudentProfile $student): array => [$student->id => $this->studentLabel($student)])->all();
    }

    public function studentOptionLabel(mixed $value): ?string
    {
        $student = filled($value) ? StudentProfile::query()->find((int) $value) : null;

        return $student instanceof StudentProfile ? $this->studentLabel($student) : null;
    }

    private function studentName(StudentProfile $student): string
    {
        return collect([$student->last_name, collect([$student->first_name, $student->middle_name])->filter()->implode(' ')])->filter()->implode(', ');
    }

    private function studentLabel(StudentProfile $student): string
    {
        return $this->studentName($student).' · '.$student->student_number;
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);

        return $actor;
    }
}
