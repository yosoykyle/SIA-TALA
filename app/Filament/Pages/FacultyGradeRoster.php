<?php

namespace App\Filament\Pages;

use App\Actions\Grades\GradeWindowService;
use App\Actions\Grades\SaveGradeRosterControlledOutcome;
use App\Actions\Grades\SaveGradeRosterPeriodEquivalent;
use App\Actions\Grades\SubmitGradeRoster;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
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

    protected static ?string $navigationLabel = 'Grade Roster';

    protected static ?string $title = 'Grade Roster';

    protected string $view = 'filament.student.pages.generic-table';

    public ?int $rosterId = null;

    /**
     * @var array<string, bool>
     */
    private array $gradeWindowCache = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole(User::StaffRoleFaculty);
    }

    public function mount(): void
    {
        $this->rosterId = GradeRoster::query()
            ->where('faculty_user_id', Auth::id())
            ->orderByRaw(
                'CASE WHEN state IN (?, ?, ?) THEN 0 ELSE 1 END',
                self::EditableStates,
            )
            ->orderByDesc('id')
            ->value('id');
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
                    ])->filter()->implode(' · '))
                    ->description(fn (GradeRosterRow $record): string => $this->rowResultSummary($record))
                    ->searchable([
                        'courseEnrollment.enrollment.studentProfile.student_number',
                        'courseEnrollment.enrollment.studentProfile.last_name',
                    ]),
                $this->periodEquivalentColumn('prelim', 'Prelim'),
                $this->periodEquivalentColumn('midterm', 'Midterm'),
                $this->periodEquivalentColumn('final', 'Final'),
            ])
            ->headerActions([
                Action::make('selectRoster')
                    ->label('Select Roster')
                    ->schema([
                        Select::make('rosterId')
                            ->label('Roster')
                            ->options(fn (): array => $this->assignedRosterOptions())
                            ->required(),
                    ])
                    ->fillForm(fn (): array => ['rosterId' => $this->rosterId])
                    ->visible(fn (): bool => count($this->assignedRosterOptions()) > 1)
                    ->action(function (array $data): void {
                        $rosterId = (int) $data['rosterId'];

                        if (! array_key_exists($rosterId, $this->assignedRosterOptions())) {
                            return;
                        }

                        $this->rosterId = $rosterId;
                        $this->resetTable();

                        Notification::make()->title('Grade roster selected')->success()->send();
                    }),
                Action::make('submit')
                    ->label('Submit for Registrar Review')
                    ->modalHeading('Submit this grade roster?')
                    ->modalDescription(fn (): string => $this->selectedRosterSubmissionSummary())
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->selectedRosterIsEditable())
                    ->action(function (): void {
                        $roster = $this->selectedRoster();

                        if (! $roster instanceof GradeRoster || ! $this->selectedRosterIsReadyForSubmission()) {
                            Notification::make()
                                ->title('Roster is not ready')
                                ->body($this->selectedRosterSubmissionSummary())
                                ->danger()
                                ->send();

                            return;
                        }

                        app(SubmitGradeRoster::class)->execute($roster, $this->authenticatedUser());
                        Notification::make()->title('Grade roster submitted')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('setControlledOutcome')
                    ->label(fn (GradeRosterRow $record): string => $this->rowCanEditPeriod($record, 'final')
                        ? 'Set P / INC'
                        : 'Final locked')
                    ->color(fn (GradeRosterRow $record): string => $this->rowCanEditPeriod($record, 'final')
                        ? 'primary'
                        : 'gray')
                    ->tooltip(fn (GradeRosterRow $record): ?string => $this->rowCanEditPeriod($record, 'final')
                        ? null
                        : 'The Final window is closed. Request late authorization before changing this mark.')
                    ->modalHeading('Set controlled final mark')
                    ->modalDescription('Use P or INC only when the institutional rule applies. This replaces the numeric Final value for this draft row.')
                    ->schema([
                        Select::make('controlled_outcome')
                            ->label('Controlled final mark')
                            ->options([
                                'P' => 'Pending (P)',
                                'INC' => 'Incomplete (INC)',
                            ])
                            ->placeholder('Use numeric Final')
                            ->rules(['nullable', 'in:P,INC']),
                    ])
                    ->fillForm(fn (GradeRosterRow $record): array => [
                        'controlled_outcome' => in_array($record->current_outcome_code, ['P', 'INC'], true)
                            ? $record->current_outcome_code
                            : null,
                    ])
                    ->visible(fn (): bool => $this->selectedRosterIsEditable())
                    ->disabled(fn (GradeRosterRow $record): bool => ! $this->rowCanEditPeriod($record, 'final'))
                    ->action(function (GradeRosterRow $record, array $data): void {
                        if (! $this->saveControlledOutcome($record, $data['controlled_outcome'] ?? null)) {
                            return;
                        }

                        Notification::make()
                            ->title('Controlled final mark saved')
                            ->success()
                            ->send();
                    }),
            ])
            ->stackedOnMobile()
            ->emptyStateHeading('No assigned grade roster')
            ->emptyStateDescription('Assigned grade rosters and their submission history appear here.');
    }

    /**
     * @return Builder<GradeRosterRow>
     */
    private function rowsQuery(): Builder
    {
        return GradeRosterRow::query()
            ->with(['courseEnrollment.enrollment.studentProfile', 'roster'])
            ->whereHas('roster', fn (Builder $query) => $query->where('faculty_user_id', Auth::id()))
            ->when($this->rosterId !== null, fn (Builder $query) => $query->where('grade_roster_id', $this->rosterId))
            ->whereRaw($this->rosterId === null ? '1 = 0' : '1 = 1');
    }

    /**
     * @return array<int, string>
     */
    private function assignedRosterOptions(): array
    {
        return GradeRoster::query()
            ->with(['termOffering.term', 'termOffering.curriculumEntry.courseSpecification.course', 'section'])
            ->where('faculty_user_id', Auth::id())
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (GradeRoster $roster): array => [
                $roster->id => collect([
                    $roster->termOffering?->term?->label,
                    $roster->section?->code,
                    $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
                    $this->formatRosterState($roster->state),
                ])->filter()->implode(' / '),
            ])
            ->all();
    }

    private function selectedRosterHeading(): string
    {
        $roster = $this->selectedRoster();

        if (! $roster instanceof GradeRoster) {
            return 'Grade encoding workspace';
        }

        $specification = $roster->termOffering?->curriculumEntry?->courseSpecification;
        $course = $specification?->course;

        return collect([$course?->code, $specification?->title, $roster->section?->code])
            ->filter()
            ->implode(' — ');
    }

    private function selectedRosterDescription(): string
    {
        $roster = $this->selectedRoster();

        if (! $roster instanceof GradeRoster) {
            return 'Select an assigned roster to encode grades.';
        }

        $readiness = $this->selectedRosterReadiness();

        $nextAction = match (true) {
            ! $this->selectedRosterIsEditable() => 'Encoding is locked. This roster remains available as read-only submission history.',
            $readiness['total'] === 0 => 'No students are currently assigned to this roster.',
            $readiness['missing'] === 0 => 'All rows are ready. Review and submit for Registrar review.',
            default => sprintf(
                'Complete %d remaining %s. Enter all three numeric period equivalents or choose P/INC as the controlled final mark.',
                $readiness['missing'],
                $readiness['missing'] === 1 ? 'row' : 'rows',
            ),
        };

        $windowNotice = $this->selectedRosterIsEditable()
            ? ' '.$this->selectedRosterWindowSummary($roster)
            : '';

        return sprintf(
            '%s · %s · %d of %d rows ready. %s',
            $roster->termOffering?->term->label ?? 'Term not recorded',
            $this->formatRosterState($roster->state),
            $readiness['ready'],
            $readiness['total'],
            $nextAction.$windowNotice,
        );
    }

    private function selectedRoster(): ?GradeRoster
    {
        if ($this->rosterId === null) {
            return null;
        }

        return GradeRoster::query()
            ->with(['termOffering.term', 'termOffering.curriculumEntry.courseSpecification.course', 'section'])
            ->where('faculty_user_id', Auth::id())
            ->find($this->rosterId);
    }

    private function selectedRosterIsEditable(): bool
    {
        $roster = $this->selectedRoster();

        return $roster instanceof GradeRoster
            && in_array($roster->state, self::EditableStates, true);
    }

    private function rowCanEditPeriod(GradeRosterRow $row, string $period): bool
    {
        $row->loadMissing('roster');

        if (
            (int) $row->roster->faculty_user_id !== (int) Auth::id()
            || ! in_array($row->roster->state, self::EditableStates, true)
        ) {
            return false;
        }

        return $this->gradeWindowIsOpen($row->roster, $period);
    }

    /**
     * @return array{total:int,ready:int,missing:int}
     */
    private function selectedRosterReadiness(): array
    {
        $roster = $this->selectedRoster();

        if (! $roster instanceof GradeRoster) {
            return ['total' => 0, 'ready' => 0, 'missing' => 0];
        }

        $total = $roster->rows()->count();
        $ready = $roster->rows()
            ->where(function (Builder $query): void {
                $query->whereNotNull('computed_average')
                    ->orWhereIn('current_outcome_code', ['P', 'INC']);
            })
            ->count();

        return [
            'total' => $total,
            'ready' => $ready,
            'missing' => $total - $ready,
        ];
    }

    private function selectedRosterIsReadyForSubmission(): bool
    {
        $readiness = $this->selectedRosterReadiness();

        return $readiness['total'] > 0 && $readiness['missing'] === 0;
    }

    private function selectedRosterSubmissionSummary(): string
    {
        $readiness = $this->selectedRosterReadiness();

        if ($readiness['total'] === 0) {
            return 'This roster has no students and cannot be submitted.';
        }

        if ($readiness['missing'] > 0) {
            return sprintf(
                '%d of %d rows are ready. Complete %d remaining %s before submission.',
                $readiness['ready'],
                $readiness['total'],
                $readiness['missing'],
                $readiness['missing'] === 1 ? 'row' : 'rows',
            );
        }

        return sprintf(
            'All %d rows are ready. The Registrar will review this roster before grades are posted and released to students.',
            $readiness['total'],
        );
    }

    private function formatRosterState(string $state): string
    {
        return str($state)
            ->lower()
            ->replace('_', ' ')
            ->headline()
            ->toString();
    }

    private function rowResultSummary(GradeRosterRow $row): string
    {
        return match (true) {
            in_array($row->current_outcome_code, ['P', 'INC'], true) => "{$row->current_outcome_code} · Controlled mark ready",
            $row->computed_average !== null => number_format((float) $row->computed_average, 2).' · Numeric result ready',
            default => 'Missing final result',
        };
    }

    private function gradeWindowIsOpen(GradeRoster $roster, string $period): bool
    {
        $cacheKey = "{$roster->id}:{$period}";

        return $this->gradeWindowCache[$cacheKey] ??= app(GradeWindowService::class)->isOpen($roster, $period);
    }

    private function selectedRosterWindowSummary(GradeRoster $roster): string
    {
        $windows = collect(['prelim', 'midterm', 'final'])
            ->map(fn (string $period): string => sprintf(
                '%s %s',
                str($period)->headline()->toString(),
                $this->gradeWindowIsOpen($roster, $period) ? 'open' : 'closed',
            ))
            ->implode(', ');

        return "Encoding windows: {$windows}. Request late authorization before changing a closed period.";
    }

    private function periodEquivalentColumn(string $period, string $label): TextInputColumn
    {
        $column = "{$period}_equivalent";

        return TextInputColumn::make($column)
            ->label($label)
            ->type('number')
            ->step(0.01)
            ->extraInputAttributes(['style' => 'min-width: 5.5rem; width: 5.5rem;'])
            ->state(function (GradeRosterRow $record) use ($column): ?string {
                $value = $record->getAttribute($column);

                return $value === null ? null : number_format((float) $value, 2, '.', '');
            })
            ->rules(['nullable', 'numeric', 'min:0', 'max:100'])
            ->disabled(fn (GradeRosterRow $record): bool => ! $this->rowCanEditPeriod($record, $period))
            ->updateStateUsing(fn (GradeRosterRow $record, mixed $state): mixed => $this->savePeriodEquivalent($record, $period, $state));
    }

    private function savePeriodEquivalent(GradeRosterRow $row, string $period, mixed $state): mixed
    {
        $column = "{$period}_equivalent";

        try {
            return app(SaveGradeRosterPeriodEquivalent::class)
                ->execute($row, $period, $state, $this->authenticatedUser())
                ->getAttribute($column);
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Grade was not saved')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return $row->fresh()->getAttribute($column);
        }
    }

    private function saveControlledOutcome(GradeRosterRow $row, ?string $state): bool
    {
        try {
            app(SaveGradeRosterControlledOutcome::class)->execute($row, $state, $this->authenticatedUser());

            return true;
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Final mark was not saved')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }
    }

    private function authenticatedUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthorizationException('Authentication is required to manage a grade roster.');
        }

        return $user;
    }
}
