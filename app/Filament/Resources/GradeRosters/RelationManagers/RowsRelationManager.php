<?php

namespace App\Filament\Resources\GradeRosters\RelationManagers;

use App\Actions\Grades\AmendIncDeadline;
use App\Actions\Grades\FinalResultPolicy;
use App\Actions\Grades\IncDeadlineService;
use App\Actions\Grades\RecordApprovedGradeCorrection;
use App\Actions\Grades\ReleaseIncCompletion;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\IncCompletionSubmission;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'rows';

    protected static ?string $title = 'Official Result Rows';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof GradeRoster
            && $ownerRecord->state === GradeRoster::StateReleased
            && (auth()->user()?->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAcademicHead]) ?? false);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('is_current_membership', true)->with([
                'courseEnrollment.enrollment.studentProfile', 'outcomeEvents.completionSubmissions',
            ]))
            ->columns([
                TextColumn::make('courseEnrollment.enrollment.studentProfile.student_number')->label('Student no.')->searchable(),
                TextColumn::make('courseEnrollment.enrollment.studentProfile.last_name')->label('Student')->searchable(),
                TextColumn::make('final_result')->label('Submitted result')->badge(),
                TextColumn::make('current_outcome_code')->label('Current official result')->badge(),
                TextColumn::make('inc_state')
                    ->label('INC status')
                    ->state(fn (GradeRosterRow $record): string => $this->incState($record))
                    ->placeholder('Not applicable'),
                TextColumn::make('released_at')->label('Released')->dateTime(),
            ])
            ->recordActions([
                Action::make('releaseIncCompletion')
                    ->label('Release INC completion')
                    ->visible(fn (GradeRosterRow $record): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar)
                        && $this->submittedCompletion($record) instanceof IncCompletionSubmission)
                    ->schema([
                        TextInput::make('authority_reference')->label('Release authority')->required()->maxLength(255),
                    ])
                    ->requiresConfirmation()
                    ->action(function (GradeRosterRow $record, array $data): void {
                        $submission = $this->submittedCompletion($record);
                        $actor = auth()->user();

                        if ($submission instanceof IncCompletionSubmission && $actor instanceof User) {
                            app(ReleaseIncCompletion::class)->execute($submission, $actor, (string) $data['authority_reference']);
                            Notification::make()->title('INC completion released')->success()->send();
                        }
                    }),
                Action::make('amendIncDeadline')
                    ->label('Change INC deadline')
                    ->visible(fn (GradeRosterRow $record): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar)
                        && $this->unresolvedInc($record) instanceof GradeOutcomeEvent)
                    ->schema([
                        DatePicker::make('new_deadline')->required(),
                        TextInput::make('authority_reference')->required()->maxLength(255),
                        DatePicker::make('authority_date')->required(),
                        Textarea::make('reason')->required()->maxLength(2000),
                    ])
                    ->action(function (GradeRosterRow $record, array $data): void {
                        $inc = $this->unresolvedInc($record);
                        $actor = auth()->user();

                        if ($inc instanceof GradeOutcomeEvent && $actor instanceof User) {
                            app(AmendIncDeadline::class)->execute(
                                $inc,
                                Carbon::parse($data['new_deadline']),
                                (string) $data['authority_reference'],
                                Carbon::parse($data['authority_date']),
                                (string) $data['reason'],
                                $actor,
                            );
                            Notification::make()->title('INC deadline amendment recorded')->success()->send();
                        }
                    }),
                Action::make('recordCorrection')
                    ->label('Record authorized correction')
                    ->visible(fn (GradeRosterRow $record): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) && $record->released_at !== null)
                    ->schema([
                        Hidden::make('command_key')->default(fn (): string => (string) Str::uuid()),
                        Select::make('corrected_code')->label('Corrected final result')->options(fn (): array => app(FinalResultPolicy::class)->options())->required(),
                        TextInput::make('authority')->label('Approving authority')->required()->maxLength(255),
                        Textarea::make('reason')->required()->maxLength(2000),
                        TextInput::make('evidence_reference')->label('Evidence reference')->maxLength(255),
                    ])
                    ->action(function (array $data, GradeRosterRow $record): void {
                        $actor = auth()->user();

                        if ($actor instanceof User) {
                            app(RecordApprovedGradeCorrection::class)->execute(
                                $record,
                                (string) $data['corrected_code'],
                                (string) $data['authority'],
                                (string) $data['reason'],
                                filled($data['evidence_reference'] ?? null) ? (string) $data['evidence_reference'] : null,
                                $actor,
                                (string) $data['command_key'],
                            );
                            Notification::make()->title('Authorized correction recorded')->success()->send();
                        }
                    }),
            ])
            ->toolbarActions([])
            ->stackedOnMobile();
    }

    private function unresolvedInc(GradeRosterRow $row): ?GradeOutcomeEvent
    {
        $events = $row->outcomeEvents->sortByDesc('id');
        $latest = $events->first();

        return $latest?->result_code === 'INC' ? $latest : null;
    }

    private function submittedCompletion(GradeRosterRow $row): ?IncCompletionSubmission
    {
        return $this->unresolvedInc($row)?->completionSubmissions
            ->where('state', IncCompletionSubmission::StateSubmitted)
            ->sortByDesc('id')->first();
    }

    private function incState(GradeRosterRow $row): string
    {
        $inc = $this->unresolvedInc($row);

        return $inc instanceof GradeOutcomeEvent
            ? app(IncDeadlineService::class)->state($inc)
            : '';
    }
}
