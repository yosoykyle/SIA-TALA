<?php

namespace App\Filament\Pages;

use App\Actions\Grades\FinalResultPolicy;
use App\Actions\Grades\GradeWindowService;
use App\Actions\Grades\SaveFinalGradeResult;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SubmitIncCompletion;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class FacultyGradeRoster extends Page implements HasTable
{
    use InteractsWithTable;

    private const EditableStates = [
        GradeRoster::StateDraft,
        GradeRoster::StateReturned,
        GradeRoster::StateLateNotSubmitted,
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|\UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'Grade Rosters';

    protected static ?string $title = 'Grade Rosters';

    protected string $view = 'filament.student.pages.generic-table';

    public ?int $rosterId = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(User::StaffRoleFaculty) ?? false;
    }

    public function mount(): void
    {
        $this->rosterId = $this->accessibleRosters()->orderByDesc('grade_rosters.id')->value('grade_rosters.id');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->rowsQuery())
            ->heading(fn (): string => $this->selectedRosterHeading())
            ->description(fn (): string => $this->selectedRosterDescription())
            ->columns([
                TextColumn::make('student_identity')
                    ->label('Student')
                    ->state(fn (GradeRosterRow $record): string => collect([
                        $record->courseEnrollment?->enrollment?->studentProfile?->student_number,
                        $record->courseEnrollment?->enrollment?->studentProfile?->last_name,
                        $record->courseEnrollment?->enrollment?->studentProfile?->first_name,
                    ])->filter()->implode(' · '))
                    ->description(fn (GradeRosterRow $record): string => $record->return_reason ?? 'Current official roster membership')
                    ->wrap(),
                TextColumn::make('final_result')
                    ->label('Final result')
                    ->placeholder('Not recorded')
                    ->badge()
                    ->description(fn (GradeRosterRow $record): ?string => $record->final_result === 'INC'
                        ? $record->inc_completion_note
                        : null),
                TextColumn::make('row_status')
                    ->label('Status')
                    ->state(fn (GradeRosterRow $record): string => match (true) {
                        ! $record->is_current_membership => 'No longer current',
                        $record->released_at !== null => 'Released',
                        $record->returned_at !== null => 'Returned for correction',
                        $record->final_result !== null => 'Ready',
                        default => 'Final result required',
                    })
                    ->badge()
                    ->wrap(),
            ])
            ->headerActions([
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
                            $this->resetTable();
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
                Action::make('submit')
                    ->label('Submit complete roster')
                    ->modalHeading('Submit this immutable roster version?')
                    ->modalDescription(fn (): string => $this->submissionSummary())
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->canEditSelectedRoster())
                    ->disabled(fn (): bool => ! $this->selectedRosterIsReady())
                    ->action(function (): void {
                        app(SubmitGradeRoster::class)->execute($this->selectedRosterOrFail(), $this->authenticatedUser());
                        Notification::make()->title('Roster submitted for Registrar review')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('recordFinalResult')
                    ->label(fn (GradeRosterRow $record): string => $record->final_result === null ? 'Record final result' : 'Edit returned result')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([
                        Select::make('final_result')
                            ->label('Final result')
                            ->options(fn (): array => app(FinalResultPolicy::class)->options())
                            ->required()
                            ->live(),
                        Textarea::make('inc_completion_note')
                            ->label('INC completion note')
                            ->helperText('Required only for INC. State the work still needed without entering sensitive details.')
                            ->required(fn ($get): bool => $get('final_result') === 'INC')
                            ->visible(fn ($get): bool => $get('final_result') === 'INC')
                            ->rows(3),
                    ])
                    ->fillForm(fn (GradeRosterRow $record): array => [
                        'final_result' => $record->final_result,
                        'inc_completion_note' => $record->inc_completion_note,
                    ])
                    ->visible(fn (GradeRosterRow $record): bool => $this->canEditRow($record))
                    ->action(function (GradeRosterRow $record, array $data): void {
                        try {
                            app(SaveFinalGradeResult::class)->execute(
                                $record,
                                (string) $data['final_result'],
                                $data['inc_completion_note'] ?? null,
                                $this->authenticatedUser(),
                            );
                            Notification::make()->title('Final result saved')->success()->send();
                        } catch (RuntimeException $exception) {
                            Notification::make()->title('Final result was not saved')->body($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('submitIncCompletion')
                    ->label('Submit INC completion')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (GradeRosterRow $record): bool => $record->current_outcome_code === 'INC'
                        && (int) $record->roster->teachingAssignment?->faculty_user_id === (int) Auth::id())
                    ->schema([
                        Select::make('proposed_result')
                            ->label('Completed final result')
                            ->options(fn (): array => collect(app(FinalResultPolicy::class)->options())->except('INC')->all())
                            ->required(),
                        Textarea::make('completion_note')
                            ->label('Completion evidence note')
                            ->helperText('Describe the completed requirement without entering sensitive details.')
                            ->required()->rows(3),
                    ])
                    ->action(function (GradeRosterRow $record, array $data): void {
                        $inc = $record->outcomeEvents()->where('result_code', 'INC')->latest('id')->firstOrFail();

                        try {
                            app(SubmitIncCompletion::class)->execute(
                                $inc,
                                (string) $data['proposed_result'],
                                (string) $data['completion_note'],
                                $this->authenticatedUser(),
                            );
                            Notification::make()->title('INC completion submitted for Registrar review')->success()->send();
                        } catch (RuntimeException $exception) {
                            Notification::make()->title('INC completion was not submitted')->body($exception->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->stackedOnMobile()
            ->emptyStateHeading('No assigned roster')
            ->emptyStateDescription('Current designated and co-Faculty assignments appear here after the Registrar records them.');
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

    /** @return Builder<GradeRosterRow> */
    private function rowsQuery(): Builder
    {
        return GradeRosterRow::query()
            ->with(['courseEnrollment.enrollment.studentProfile', 'roster.teachingAssignment'])
            ->where('is_current_membership', true)
            ->when($this->rosterId, fn (Builder $query) => $query->where('grade_roster_id', $this->rosterId))
            ->whereHas('roster', fn (Builder $query) => $query->whereIn('id', $this->accessibleRosters()->select('grade_rosters.id')));
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
            ->with(['termOffering.term', 'termOffering.curriculumEntry.courseSpecification.course', 'section', 'teachingAssignment'])
            ->find($this->rosterId);
    }

    private function selectedRosterOrFail(): GradeRoster
    {
        return $this->selectedRoster() ?? throw new RuntimeException('Select an accessible roster first.');
    }

    private function canEditSelectedRoster(): bool
    {
        $roster = $this->selectedRoster();

        return $roster instanceof GradeRoster
            && (int) $roster->teachingAssignment?->faculty_user_id === (int) Auth::id()
            && in_array($roster->state, self::EditableStates, true);
    }

    private function canEditRow(GradeRosterRow $row): bool
    {
        return $this->canEditSelectedRoster()
            && (bool) $row->is_current_membership
            && ($row->roster->state !== GradeRoster::StateReturned || $row->returned_at !== null)
            && app(GradeWindowService::class)->isOpen($row->roster, 'final');
    }

    private function selectedRosterIsReady(): bool
    {
        $roster = $this->selectedRoster();

        return $roster instanceof GradeRoster
            && $roster->rows()->where('is_current_membership', true)->exists()
            && ! $roster->rows()->where('is_current_membership', true)->whereNull('final_result')->exists();
    }

    private function selectedRosterHeading(): string
    {
        $roster = $this->selectedRoster();

        return $roster instanceof GradeRoster
            ? collect([
                $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
                $roster->section?->code,
            ])->filter()->implode(' — ')
            : 'Official grade roster';
    }

    private function selectedRosterDescription(): string
    {
        $roster = $this->selectedRoster();

        if (! $roster instanceof GradeRoster) {
            return 'Select a roster assigned by the Registrar.';
        }

        $role = (int) $roster->teachingAssignment?->faculty_user_id === (int) Auth::id()
            ? 'Designated submitter'
            : 'View-only co-Faculty';
        $window = app(GradeWindowService::class)->isOpen($roster, 'final') ? 'Grade Entry open' : 'Grade Entry closed';

        return collect([$roster->termOffering?->term?->label, $role, str($roster->state)->headline(), $window])
            ->filter()->implode(' · ');
    }

    private function submissionSummary(): string
    {
        $roster = $this->selectedRosterOrFail();
        $total = $roster->rows()->where('is_current_membership', true)->count();
        $missing = $roster->rows()->where('is_current_membership', true)->whereNull('final_result')->count();

        return $missing === 0
            ? "All {$total} current rows will be frozen as a new attributable version for Registrar review."
            : "Complete {$missing} of {$total} current rows before submission.";
    }

    private function authenticatedUser(): User
    {
        return Auth::user() instanceof User
            ? Auth::user()
            : throw new AuthorizationException('Authentication is required.');
    }
}
