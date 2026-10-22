<?php

namespace App\Filament\Pages;

use App\Actions\Academics\ExaminationPeriodProjection;
use App\Actions\Grades\CurrentClassRosterOutput;
use App\Actions\Grades\FinalResultPolicy;
use App\Actions\Grades\GradeWindowService;
use App\Actions\Grades\SaveGradeRosterDraft;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SubmitIncCompletion;
use App\Filament\Resources\GradeRosters\Actions\ExportClassRosterAction;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
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

    public bool $rosterEditable = false;

    /** @var list<int> */
    public array $editableRowIds = [];

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

        return $roster === null ? null : collect([$roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code, $roster->section?->code, $roster->termOffering?->term?->label])->filter()->implode(' · ');
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
        $classes = $this->rosterId === null
            ? $this->accessibleRosters()->with(['termOffering.term.academicYear', 'termOffering.curriculumEntry.courseSpecification.course', 'section'])->orderByDesc('grade_rosters.id')->get()
            : collect();
        $termFilter = request()->integer('term');
        $search = trim((string) request()->query('q', ''));
        $terms = $classes->pluck('termOffering.term')->filter()->unique('id');
        $classRows = $classes->filter(fn (GradeRoster $roster): bool => ($termFilter === 0 || (int) $roster->termOffering?->term_id === $termFilter)
            && ($search === '' || str_contains(mb_strtolower(implode(' ', [$roster->termOffering?->curriculumEntry?->courseSpecification?->title, $roster->section?->code, $roster->termOffering?->term?->label])), mb_strtolower($search))))
            ->map(function (GradeRoster $roster): array {
                $snapshot = app(CurrentClassRosterOutput::class)->membershipSnapshot($roster, $this->authenticatedUser());

                return ['roster' => $roster, 'current' => $snapshot['isCurrent'], 'count' => $snapshot['isCurrent'] ? $snapshot['count'] : null];
            });

        return ['membership' => $this->classMembership(), 'period' => $this->examinationPeriod(), 'classRows' => $classRows, 'classTerms' => $terms, 'classTermFilter' => $termFilter, 'classSearch' => $search];
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
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Final results')
                    ->contained(false)
                    ->schema([
                        Hidden::make('lock_version'),
                        Hidden::make('membership_signature'),
                        Repeater::make('rows')
                            ->label('Current official roster')
                            ->hiddenLabel()
                            ->columns(['default' => 1, 'md' => 3])
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('student_number'),
                                Hidden::make('legal_name'),
                                Hidden::make('enrollment_state'),
                                TextEntry::make('student_number_display')->label('Student number')->state(fn (Get $get): ?string => $get('student_number')),
                                TextEntry::make('legal_name_display')->label('Legal name')->state(fn (Get $get): ?string => $get('legal_name')),
                                Select::make('final_result')
                                    ->label('Final result')
                                    ->placeholder('Not recorded')
                                    ->options(fn (): array => app(FinalResultPolicy::class)->options())
                                    ->disabled(fn (Get $get): bool => ! in_array((int) $get('id'), $this->editableRowIds, true))
                                    ->live(),
                                Textarea::make('inc_completion_note')
                                    ->label('INC completion note')->columnSpanFull()
                                    ->placeholder('Required only for INC')
                                    ->rows(2)
                                    ->maxLength(1000)
                                    ->required(fn (Get $get): bool => $get('final_result') === 'INC')
                                    ->visible(fn (Get $get): bool => $get('final_result') === 'INC')
                                    ->disabled(fn (Get $get): bool => ! in_array((int) $get('id'), $this->editableRowIds, true)),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('selectRoster')
                ->label('All classes')->color('gray')->icon(Heroicon::OutlinedArrowLeft)
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
                ->icon(Heroicon::OutlinedCheckCircle)
                ->visible(fn (): bool => $this->incCompletionOptions() !== [])
                ->schema([
                    Select::make('row_id')->label('Student and course')->options(fn (): array => $this->incCompletionOptions())->required(),
                    Select::make('proposed_result')->label('Completed final result')->options(fn (): array => collect(app(FinalResultPolicy::class)->options())->except('INC')->all())->required(),
                    Textarea::make('completion_note')->label('Completion evidence note')->helperText('Describe the completed requirement without entering sensitive details.')->required()->rows(3)->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $row = $this->selectedRosterOrFail()->rows()->whereKey((int) $data['row_id'])->firstOrFail();
                    $inc = $row->outcomeEvents()->where('result_code', 'INC')->latest('id')->firstOrFail();
                    app(SubmitIncCompletion::class)->execute($inc, (string) $data['proposed_result'], (string) $data['completion_note'], $this->authenticatedUser());
                    Notification::make()->title('INC completion submitted for Registrar review')->success()->send();
                }),
            Action::make('submit')
                ->label('Submit complete roster')
                ->modalHeading('Submit this class roster?')
                ->modalDescription(fn (): string => $this->submissionSummary())
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->canEditSelectedRoster())
                ->action(function (): void {
                    $saved = $this->persistDraft();
                    app(SubmitGradeRoster::class)->execute($saved, $this->authenticatedUser());
                    $this->fillRosterForm();
                    Notification::make()->title('Roster submitted for Registrar review')->success()->send();
                }),
        ];
    }

    public function saveDraft(): void
    {
        try {
            $this->persistDraft();
            $this->fillRosterForm();
            Notification::make()->title('Roster draft saved')->success()->send();
        } catch (RuntimeException $exception) {
            Notification::make()->title('Roster draft was not saved')->body($exception->getMessage())->danger()->send();
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

    /** @return array<string, mixed> */
    public function examinationPeriod(): array
    {
        return app(ExaminationPeriodProjection::class)->forTerm($this->selectedRoster()?->termOffering?->term);
    }

    private function fillRosterForm(): void
    {
        $this->membershipSnapshots = [];
        $roster = $this->selectedRoster();

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
            ])->filter()->implode('|'))->map(function (GradeRosterRow $row): array {
                $student = $row->courseEnrollment?->enrollment?->studentProfile;

                return [
                    'id' => $row->id,
                    'student_number' => $student?->student_number,
                    'legal_name' => collect([$student?->last_name, collect([$student?->first_name, $student?->middle_name])->filter()->implode(' ')])->filter()->implode(', '),
                    'enrollment_state' => str($row->courseEnrollment?->enrollment->canonical_outcome ?? $row->courseEnrollment?->enrollment->status)->headline()->toString(),
                    'final_result' => $row->final_result,
                    'inc_completion_note' => $row->inc_completion_note,
                ];
            })->values()->all(),
        ] : ['rows' => []]);
    }

    private function persistDraft(): GradeRoster
    {
        if (! ($this->classMembership()['isCurrent'] ?? false)) {
            Notification::make()->title('Class membership changed')->body('Ask the Registrar to refresh this class roster, then reload before saving.')->danger()->send();
            throw ValidationException::withMessages(['rows' => 'Current official class membership must be refreshed before saving.']);
        }
        $state = $this->form->getState();

        return app(SaveGradeRosterDraft::class)->execute(
            $this->selectedRosterOrFail(),
            array_values($state['rows'] ?? []),
            (int) ($state['lock_version'] ?? -1),
            filled($state['membership_signature'] ?? null) ? (string) $state['membership_signature'] : null,
            $this->authenticatedUser(),
        );
    }

    /** @return array<int, string> */
    private function incCompletionOptions(): array
    {
        $roster = $this->selectedRoster();

        if (! $roster instanceof GradeRoster || (int) $roster->teachingAssignment?->faculty_user_id !== (int) Auth::id()) {
            return [];
        }

        return $roster->rows->filter(fn (GradeRosterRow $row): bool => $row->current_outcome_code === 'INC')
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
