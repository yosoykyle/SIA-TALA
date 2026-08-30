<?php

namespace App\Filament\Pages;

use App\Actions\Academics\ExaminationPeriodProjection;
use App\Actions\Grades\FinalResultPolicy;
use App\Actions\Grades\GradeWindowService;
use App\Actions\Grades\SaveGradeRosterDraft;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SubmitIncCompletion;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
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

    public ?int $rosterId = null;

    public bool $rosterEditable = false;

    /** @var list<int> */
    public array $editableRowIds = [];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(User::StaffRoleFaculty) ?? false;
    }

    public function mount(): void
    {
        $this->rosterId = $this->accessibleRosters()->orderByDesc('grade_rosters.id')->value('grade_rosters.id');
        $this->fillRosterForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(fn (): string => $this->selectedRosterHeading())
                    ->description(fn (): string => $this->selectedRosterDescription())
                    ->schema([
                        Hidden::make('lock_version'),
                        Hidden::make('membership_signature'),
                        Repeater::make('rows')
                            ->label('Current official roster')
                            ->table([
                                TableColumn::make('Student number'),
                                TableColumn::make('Legal name'),
                                TableColumn::make('Enrollment'),
                                TableColumn::make('Final result'),
                                TableColumn::make('INC completion note')->wrapHeader(),
                            ])
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('student_number')->hiddenLabel()->disabled()->dehydrated(),
                                TextInput::make('legal_name')->hiddenLabel()->disabled()->dehydrated(),
                                TextInput::make('enrollment_state')->hiddenLabel()->disabled()->dehydrated(),
                                Select::make('final_result')
                                    ->hiddenLabel()
                                    ->placeholder('Not recorded')
                                    ->options(fn (): array => app(FinalResultPolicy::class)->options())
                                    ->disabled(fn (Get $get): bool => ! in_array((int) $get('id'), $this->editableRowIds, true))
                                    ->live(),
                                Textarea::make('inc_completion_note')
                                    ->hiddenLabel()
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
                ->label('Select roster')
                ->schema([
                    Select::make('rosterId')->label('Roster')->options(fn (): array => $this->rosterOptions())->required(),
                ])
                ->fillForm(fn (): array => ['rosterId' => $this->rosterId])
                ->visible(fn (): bool => count($this->rosterOptions()) > 1)
                ->action(function (array $data): void {
                    $rosterId = (int) $data['rosterId'];

                    if (array_key_exists($rosterId, $this->rosterOptions())) {
                        $this->rosterId = $rosterId;
                        $this->fillRosterForm();
                    }
                }),
            Action::make('printRoster')
                ->label('Print class roster')
                ->icon(Heroicon::OutlinedPrinter)
                ->visible(fn (): bool => $this->rosterId !== null)
                ->url(fn (): ?string => $this->rosterId === null ? null : route('grade-rosters.print', $this->rosterId))
                ->openUrlInNewTab(),
            Action::make('downloadRoster')
                ->label('Download CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => $this->rosterId !== null)
                ->url(fn (): ?string => $this->rosterId === null ? null : route('grade-rosters.csv', $this->rosterId)),
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
                    $row = GradeRosterRow::query()->whereKey((int) $data['row_id'])->where('grade_roster_id', $this->rosterId)->firstOrFail();
                    $inc = $row->outcomeEvents()->where('result_code', 'INC')->latest('id')->firstOrFail();
                    app(SubmitIncCompletion::class)->execute($inc, (string) $data['proposed_result'], (string) $data['completion_note'], $this->authenticatedUser());
                    Notification::make()->title('INC completion submitted for Registrar review')->success()->send();
                }),
            Action::make('submit')
                ->label('Submit complete roster')
                ->modalHeading('Submit this immutable roster version?')
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
        return GradeRoster::query()->where(function (Builder $query): void {
            $query->whereHas('teachingAssignment', fn (Builder $assignmentQuery) => $assignmentQuery
                ->where('faculty_user_id', Auth::id())
                ->where('state', ClassOfferingTeachingAssignment::StateActive))
                ->orWhereHas('section', fn (Builder $sectionQuery) => $sectionQuery
                    ->whereHas('termOffering.teachingAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                        ->where('faculty_user_id', Auth::id())
                        ->where('role', ClassOfferingTeachingAssignment::RoleCoFaculty)
                        ->where('state', ClassOfferingTeachingAssignment::StateActive)));
        });
    }

    /** @return array<int, string> */
    private function rosterOptions(): array
    {
        return $this->accessibleRosters()
            ->with(['termOffering.term', 'termOffering.curriculumEntry.courseSpecification.course', 'section'])
            ->orderByDesc('grade_rosters.id')->get()
            ->mapWithKeys(fn (GradeRoster $roster): array => [$roster->id => collect([
                $roster->termOffering?->term?->label,
                $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
                $roster->section?->code,
                str($roster->state)->headline(),
            ])->filter()->implode(' · ')])->all();
    }

    private function selectedRoster(): ?GradeRoster
    {
        return $this->rosterId === null ? null : $this->accessibleRosters()
            ->with(['rows.courseEnrollment.enrollment.studentProfile', 'termOffering.term', 'termOffering.curriculumEntry.courseSpecification.course', 'section', 'teachingAssignment'])
            ->find($this->rosterId);
    }

    private function selectedRosterOrFail(): GradeRoster
    {
        return $this->selectedRoster() ?? throw new RuntimeException('Select an accessible roster first.');
    }

    public function canEditSelectedRoster(): bool
    {
        return $this->rosterEditable;
    }

    /** @return array<string, mixed> */
    public function examinationPeriod(): array
    {
        return app(ExaminationPeriodProjection::class)->forTerm($this->selectedRoster()?->termOffering?->term);
    }

    private function fillRosterForm(): void
    {
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
                $row->courseEnrollment?->enrollment?->studentProfile?->student_number,
            ])->filter()->implode('|'))->map(function (GradeRosterRow $row): array {
                $student = $row->courseEnrollment?->enrollment?->studentProfile;

                return [
                    'id' => $row->id,
                    'student_number' => $student?->student_number,
                    'legal_name' => collect([$student?->last_name, $student?->first_name, $student?->middle_name])->filter()->implode(', '),
                    'enrollment_state' => str($row->courseEnrollment?->enrollment->canonical_outcome ?? $row->courseEnrollment?->enrollment->status)->headline()->toString(),
                    'final_result' => $row->final_result,
                    'inc_completion_note' => $row->inc_completion_note,
                ];
            })->values()->all(),
        ] : ['rows' => []]);
    }

    private function persistDraft(): GradeRoster
    {
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

    private function selectedRosterHeading(): string
    {
        $roster = $this->selectedRoster();

        return $roster instanceof GradeRoster
            ? collect([$roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code, $roster->section?->code])->filter()->implode(' — ')
            : 'Official grade roster';
    }

    private function selectedRosterDescription(): string
    {
        $roster = $this->selectedRoster();

        if (! $roster instanceof GradeRoster) {
            return 'No current roster is assigned. Registrar-recorded assignments will appear here.';
        }

        $role = (int) $roster->teachingAssignment?->faculty_user_id === (int) Auth::id() ? 'Designated submitter' : 'View-only co-Faculty';
        $window = app(GradeWindowService::class)->isOpen($roster, 'final') ? 'Grade Entry open' : 'Grade Entry closed';
        $completed = $roster->rows->where('is_current_membership', true)->whereNotNull('final_result')->count();
        $total = $roster->rows->where('is_current_membership', true)->count();

        return collect([$roster->termOffering?->term?->label, $role, str($roster->state)->headline(), $window, "{$completed} of {$total} rows complete"])->filter()->implode(' · ');
    }

    private function submissionSummary(): string
    {
        $rows = collect($this->form->getRawState()['rows'] ?? []);
        $missing = $rows->where(fn (array $row): bool => blank($row['final_result'] ?? null))->count();

        return $missing === 0
            ? "All {$rows->count()} current rows will be saved and frozen as a new attributable version for Registrar review."
            : "Complete {$missing} of {$rows->count()} current rows before submission.";
    }

    private function authenticatedUser(): User
    {
        return Auth::user() instanceof User ? Auth::user() : throw new AuthorizationException('Authentication is required.');
    }
}
