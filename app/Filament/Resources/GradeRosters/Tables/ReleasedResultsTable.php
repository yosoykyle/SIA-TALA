<?php

namespace App\Filament\Resources\GradeRosters\Tables;

use App\Actions\Grades\AmendIncDeadline;
use App\Actions\Grades\FinalResultPolicy;
use App\Actions\Grades\IncDeadlineService;
use App\Actions\Grades\RecordApprovedGradeCorrection;
use App\Actions\Grades\ReleaseIncCompletion;
use App\Actions\Grades\ReturnIncCompletion;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\IncCompletionSubmission;
use App\Models\IncDeadlineAmendment;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class ReleasedResultsTable
{
    public static function configure(Table $table, bool $includeClass = false, ?Closure $recordUrl = null): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('is_current_membership', true)->withMax('outcomeEvents', 'id')->with([
                'courseEnrollment.enrollment.studentProfile.user', 'courseEnrollment.enrollment.studentProfile.program',
                'outcomeEvents' => fn (Relation $events): Builder => $events->getQuery()->whereNotNull('released_at')->latest('id')->limit(1),
                'outcomeEvents.completionSubmissions', 'outcomeEvents.deadlineAmendments',
                ...($includeClass ? ['roster.section', 'roster.termOffering.term.academicYear', 'roster.termOffering.curriculumEntry.courseSpecification.course'] : []),
            ]))
            ->contentGrid($includeClass ? ['default' => 1] : null)
            ->columns($includeClass ? [
                Split::make([
                    Stack::make([
                        TextColumn::make('courseEnrollment.enrollment.studentProfile.last_name')->label('Student')->weight('semibold')->size('lg')
                            ->searchable(['last_name', 'first_name', 'student_number'])->wrap()
                            ->formatStateUsing(fn (GradeRosterRow $record): string => collect([$record->courseEnrollment?->enrollment?->studentProfile?->last_name, $record->courseEnrollment?->enrollment?->studentProfile?->first_name])->filter()->implode(', '))
                            ->description(fn (GradeRosterRow $record): ?string => $record->courseEnrollment?->enrollment?->studentProfile?->student_number),
                        TextColumn::make('roster.termOffering.curriculumEntry.courseSpecification.title')->label('Course / class')->wrap()
                            ->description(fn (GradeRosterRow $record): string => collect([$record->roster?->section?->code, $record->roster?->termOffering?->term?->label, $record->roster?->termOffering?->term?->academicYear?->label])->filter()->implode(' · '))
                            ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('roster', fn (Builder $rosters): Builder => $rosters
                                ->whereHas('section', fn (Builder $classes): Builder => $classes->where('code', 'like', '%'.$search.'%'))
                                ->orWhereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $courses): Builder => $courses->where('title', 'like', '%'.$search.'%')
                                    ->orWhereHas('course', fn (Builder $codes): Builder => $codes->where('code', 'like', '%'.$search.'%'))))),
                    ])->space(2),
                    Stack::make([
                        TextColumn::make('current_outcome_code')->label('Result')->weight('semibold')->size('lg')
                            ->description(fn (GradeRosterRow $record): string => $record->current_outcome_code === 'INC' ? 'Incomplete grade' : 'Released grade'),
                        TextColumn::make('inc_state')->label('INC status')
                            ->state(fn (GradeRosterRow $record): ?string => self::followUp($record)['completion_status'] ?? null)
                            ->description(fn (GradeRosterRow $record): ?string => ($followUp = self::followUp($record)) ? $followUp['state'].' · Until '.$followUp['deadline'].' · Manila' : null)
                            ->wrap(),
                    ])->grow(false)->space(2),
                ])->from('sm'),
            ] : [
                TextColumn::make('courseEnrollment.enrollment.studentProfile.student_number')->label('Student no.')->searchable(),
                TextColumn::make('courseEnrollment.enrollment.studentProfile.last_name')->label('Student')->searchable(['last_name', 'first_name'])
                    ->formatStateUsing(fn (GradeRosterRow $record): string => collect([$record->courseEnrollment?->enrollment?->studentProfile?->last_name, $record->courseEnrollment?->enrollment?->studentProfile?->first_name])->filter()->implode(', '))->wrap(),
                TextColumn::make('final_result')->label('Submitted result')->badge()->visible(! $includeClass),
                TextColumn::make('current_outcome_code')->label('Result')->weight('semibold')->size('lg'),
                TextColumn::make('inc_state')
                    ->label('INC status')
                    ->state(fn (GradeRosterRow $record): ?string => self::followUp($record)['completion_status'] ?? null)
                    ->description(fn (GradeRosterRow $record): ?string => ($followUp = self::followUp($record)) ? $followUp['state'].' · Until '.$followUp['deadline'].' · Manila' : null)
                    ->placeholder('Not applicable'),
                TextColumn::make('released_at')->label('Released')->dateTime()->visible(! $includeClass),
            ])
            ->recordActions([
                ViewAction::make('viewClass')->label('View class')->color('gray')->link()->visible($includeClass)
                    ->url($recordUrl ?? fn (GradeRosterRow $record): string => GradeRosterResource::getUrl('view', ['record' => $record->grade_roster_id, 'viewTab' => 'inc-corrections', 'result' => $record->id])),
                Action::make('releaseIncCompletion')
                    ->label('Release INC completion')
                    ->modalHeading('Review INC completion')
                    ->modalDescription('Release the Faculty completion result to the student. Academic averages and curriculum readiness will recalculate; affected active enrollment cases go to Registrar review. Subjects stay unchanged, and the original INC remains in history.')
                    ->modalSubmitActionLabel('Release INC completion')
                    ->visible(fn (GradeRosterRow $record): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar)
                        && (self::followUp($record)['can_release'] ?? false))
                    ->schema([
                        Hidden::make('expected_submission_id')->required()
                            ->default(fn (GradeRosterRow $record): ?int => self::submittedCompletion($record)?->id),
                        TextEntry::make('completion_student')->label('Student')
                            ->state(fn (GradeRosterRow $record): string => collect([
                                $record->courseEnrollment?->enrollment?->studentProfile?->student_number,
                                $record->courseEnrollment?->enrollment?->studentProfile?->last_name,
                                $record->courseEnrollment?->enrollment?->studentProfile?->first_name,
                            ])->filter()->implode(' · ')),
                        TextEntry::make('completion_class')->label('Course and class')
                            ->state(fn (GradeRosterRow $record): string => collect([
                                $record->roster?->termOffering?->curriculumEntry?->courseSpecification?->title,
                                $record->roster?->section?->code,
                                $record->roster?->termOffering?->term?->label,
                            ])->filter()->implode(' · ')),
                        TextEntry::make('completion_result')->label('Official result after release')
                            ->state(fn (GradeRosterRow $record): string => 'INC → '.self::submittedCompletion($record)?->proposed_result),
                        TextEntry::make('completion_note')->label('Faculty completion note')
                            ->state(fn (GradeRosterRow $record): ?string => self::submittedCompletion($record)?->completion_note),
                        TextEntry::make('completion_submitted_at')->label('Faculty submitted (Asia/Manila)')
                            ->state(fn (GradeRosterRow $record): ?string => self::submittedCompletion($record)?->submitted_at?->timezone('Asia/Manila')->format('M j, Y · g:i A')),
                    ])
                    ->requiresConfirmation()
                    ->action(function (GradeRosterRow $record, array $data): void {
                        $submission = self::submittedCompletion($record);
                        $actor = auth()->user();

                        if ($submission instanceof IncCompletionSubmission && $actor instanceof User) {
                            try {
                                if ((int) $submission->id !== (int) $data['expected_submission_id']) {
                                    throw new RuntimeException('The submitted completion changed. Reload and review the current submission.');
                                }

                                app(ReleaseIncCompletion::class)->execute($submission, $actor);
                            } catch (RuntimeException $exception) {
                                Notification::make()->title('Change was not recorded')->body($exception->getMessage())->danger()->send();
                                throw new Halt;
                            }
                            Notification::make()->title('INC completion released')->success()->send();
                        }
                    }),
                Action::make('returnIncCompletion')
                    ->label('Return completion for changes')->color('gray')
                    ->modalHeading('Return INC completion for changes')
                    ->modalDescription('Return the Faculty proposal with one explanation. The submitted result and its source history stay in the record. Faculty may submit a replacement only while the current INC deadline is open.')
                    ->modalSubmitActionLabel('Return completion for changes')
                    ->visible(fn (GradeRosterRow $record): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar)
                        && self::submittedCompletion($record) instanceof IncCompletionSubmission)
                    ->schema([
                        Hidden::make('expected_submission_id')->required()
                            ->default(fn (GradeRosterRow $record): ?int => self::submittedCompletion($record)?->id),
                        Textarea::make('reason')->label('Reason for return')->required()->minLength(10)->maxLength(1000),
                    ])
                    ->action(function (GradeRosterRow $record, array $data): void {
                        $submission = self::submittedCompletion($record);
                        $actor = auth()->user();

                        try {
                            if (! $submission instanceof IncCompletionSubmission || (int) $submission->id !== (int) $data['expected_submission_id']) {
                                throw new RuntimeException('The submitted completion changed. Reload and review the current submission.');
                            }

                            if ($actor instanceof User) {
                                app(ReturnIncCompletion::class)->execute($submission, $actor, (string) $data['reason']);
                            }
                        } catch (RuntimeException $exception) {
                            Notification::make()->title('Completion was not returned')->body($exception->getMessage())->danger()->send();
                            throw new Halt;
                        }

                        Notification::make()->title('INC completion returned for changes')->warning()->send();
                    }),
                Action::make('amendIncDeadline')
                    ->label('Change INC deadline')->color('gray')->button($includeClass)->icon('heroicon-o-calendar-days')
                    ->modalHeading(fn (GradeRosterRow $record): string => 'Change INC deadline · '.($record->courseEnrollment?->enrollment?->studentProfile?->student_number ?? 'Student result'))
                    ->modalDescription(fn (GradeRosterRow $record): ?string => ($inc = self::unresolvedInc($record)) ? 'Current deadline: '.app(IncDeadlineService::class)->currentDeadline($inc)->format('M j, Y').' (Asia/Manila). Choose the new deadline and explain the change. Your name and time are recorded; earlier deadlines remain in history.' : null)
                    ->modalSubmitActionLabel('Record deadline change')
                    ->visible(fn (GradeRosterRow $record): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar)
                        && self::unresolvedInc($record) instanceof GradeOutcomeEvent)
                    ->schema([
                        Hidden::make('expected_inc_event_id')->required()->default(fn (GradeRosterRow $record): ?int => self::unresolvedInc($record)?->id),
                        Hidden::make('expected_amendment_id')->default(fn (GradeRosterRow $record): ?int => ($inc = self::unresolvedInc($record)) ? IncDeadlineAmendment::query()->where('grade_outcome_event_id', $inc->id)->latest('recorded_at')->latest('id')->value('id') : null),
                        Hidden::make('expected_deadline')->required()->default(fn (GradeRosterRow $record): ?string => ($inc = self::unresolvedInc($record)) ? app(IncDeadlineService::class)->currentDeadline($inc)->toDateString() : null),
                        DatePicker::make('new_deadline')->required(),
                        Textarea::make('reason')->required()->minLength(10)->maxLength(1000),
                    ])
                    ->action(function (GradeRosterRow $record, array $data): void {
                        $inc = self::unresolvedInc($record);
                        $actor = auth()->user();

                        if ($inc instanceof GradeOutcomeEvent && $actor instanceof User) {
                            try {
                                app(AmendIncDeadline::class)->execute(
                                    $inc,
                                    Carbon::parse($data['new_deadline']),
                                    (string) $data['reason'],
                                    $actor,
                                    expectedDeadline: (string) $data['expected_deadline'],
                                    expectedIncEventId: (int) $data['expected_inc_event_id'],
                                    expectedAmendmentId: filled($data['expected_amendment_id'] ?? null) ? (int) $data['expected_amendment_id'] : null,
                                );
                            } catch (RuntimeException $exception) {
                                Notification::make()->title('Change was not recorded')->body($exception->getMessage())->danger()->send();
                                throw new Halt;
                            }
                            Notification::make()->title('INC deadline amendment recorded')->success()->send();
                        }
                    }),
                Action::make('recordCorrection')
                    ->label('Record authorized correction')->color('gray')
                    ->button($includeClass)->icon('heroicon-o-pencil-square')
                    ->modalHeading(fn (GradeRosterRow $record): string => 'Record correction · '.($record->courseEnrollment?->enrollment?->studentProfile?->student_number ?? 'Student result'))
                    ->modalDescription(fn (GradeRosterRow $record): string => 'Current official result: '.$record->current_outcome_code.'. The corrected result becomes a new result. Your name, time and reason are recorded; the previous result stays in history.')
                    ->modalSubmitActionLabel('Record correction')
                    ->visible(fn (GradeRosterRow $record): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) && $record->released_at !== null)
                    ->schema([
                        Hidden::make('command_key')->default(fn (): string => (string) Str::uuid()),
                        Hidden::make('expected_event_id')->required()->default(fn (GradeRosterRow $record): ?int => $record->outcomeEvents->whereNotNull('released_at')->max('id')),
                        Select::make('corrected_code')->label('Corrected final result')->options(fn (): array => app(FinalResultPolicy::class)->options())->required(),
                        Textarea::make('reason')->label('Reason for correction')->helperText('Explain what was wrong and why this is the correct result.')->required()->maxLength(2000),
                    ])
                    ->action(function (array $data, GradeRosterRow $record): void {
                        $actor = auth()->user();

                        if ($actor instanceof User) {
                            try {
                                app(RecordApprovedGradeCorrection::class)->execute(
                                    $record,
                                    (string) $data['corrected_code'],
                                    (string) $data['reason'],
                                    $actor,
                                    (string) $data['command_key'],
                                    expectedEventId: (int) $data['expected_event_id'],
                                );
                            } catch (RuntimeException $exception) {
                                Notification::make()->title('Change was not recorded')->body($exception->getMessage())->danger()->send();
                                throw new Halt;
                            }
                            Notification::make()->title('Authorized correction recorded')->success()->send();
                        }
                    }),
            ])
            ->toolbarActions([])
            ->stackedOnMobile();
    }

    /** @return array{state: string, deadline: string, note: ?string, owner: string, guidance: string, completion_status: string, can_release: bool, return_reason: ?string}|null */
    public static function followUp(GradeRosterRow $row): ?array
    {
        $inc = self::unresolvedInc($row);

        if (! $inc instanceof GradeOutcomeEvent) {
            return null;
        }

        $amendments = $inc->deadlineAmendments;
        $latestAmendment = $amendments->sortByDesc('id')->sortByDesc('recorded_at')->first();
        $deadline = ($latestAmendment?->new_deadline ?? $inc->deadline)->copy()->startOfDay();
        $open = now('Asia/Manila')->toDateString() <= $deadline->toDateString();
        $submission = self::submittedCompletion($row);
        $returned = $inc->completionSubmissions->where('state', IncCompletionSubmission::StateReturned)->sortByDesc('id')->first();
        $canRelease = $submission instanceof IncCompletionSubmission
            && $open
            && $submission->controlling_result_event_id !== null
            && (int) $submission->controlling_result_event_id === (int) ($row->outcome_events_max_id ?? $row->outcomeEvents->max('id'))
            && (int) ($submission->controlling_deadline_amendment_id ?? 0) === (int) ($amendments->max('id') ?? 0)
            && $submission->controlling_deadline?->toDateString() === $deadline->toDateString();

        return [
            'state' => str($open ? IncDeadlineService::StateCompletionOpen : IncDeadlineService::StateCompletionOverdue)->headline()->toString(),
            'deadline' => $deadline->format('M j, Y'),
            'note' => $inc->inc_completion_note,
            'owner' => $submission instanceof IncCompletionSubmission || ! $open ? 'Registrar' : 'Faculty',
            'guidance' => match (true) {
                ! $open => 'Ordinary completion is closed. Registrar may record an authorized future deadline extension; otherwise the student must retake the course. The original INC remains without credit.',
                $canRelease => 'Review the Faculty result and completion note, then release the completion.',
                $submission instanceof IncCompletionSubmission => 'Return the stale completion with a reason, then review the current result and deadline before requesting a replacement.',
                default => 'The designated Faculty submits the authorized completion result and note before Registrar review.',
            },
            'completion_status' => match (true) {
                $canRelease => 'Ready for Registrar review',
                $submission instanceof IncCompletionSubmission => 'Submitted completion is stale',
                $returned instanceof IncCompletionSubmission => 'Changes requested by Registrar',
                default => 'Awaiting Faculty completion',
            },
            'can_release' => $canRelease,
            'return_reason' => $submission === null ? $returned?->return_reason : null,
        ];
    }

    private static function unresolvedInc(GradeRosterRow $row): ?GradeOutcomeEvent
    {
        $events = $row->outcomeEvents->whereNotNull('released_at')->sortByDesc('id');
        $latest = $events->first();

        return $latest?->result_code === 'INC' ? $latest : null;
    }

    private static function submittedCompletion(GradeRosterRow $row): ?IncCompletionSubmission
    {
        return self::unresolvedInc($row)?->completionSubmissions
            ->where('state', IncCompletionSubmission::StateSubmitted)
            ->sortByDesc('id')->first();
    }
}
