<?php

namespace App\Filament\Applicant\Pages;

use App\Actions\Applicants\AdmissionWindowService;
use App\Actions\Applicants\WithdrawApplicantIntake;
use App\Models\ApplicantIntake;
use App\Models\User;
use App\Support\DisplayDateTime;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Dashboard extends BaseDashboard implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.applicant.pages.dashboard';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('withdrawApplication')
                ->label('Withdraw Application')
                ->icon('heroicon-o-archive-box-x-mark')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Withdraw this application?')
                ->modalDescription('The application will remain in the audit record, but it can no longer continue through online review. Contact the Registrar if you need assistance afterward.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason for withdrawal')
                        ->helperText('This reason is recorded for the Registrar and audit history.')
                        ->required()
                        ->maxLength(500)
                        ->rows(3),
                ])
                ->modalSubmitActionLabel('Withdraw Application')
                ->visible(fn (): bool => $this->canWithdraw())
                ->action(function (array $data): void {
                    $applicant = Auth::user();
                    $intake = $this->getIntake();

                    abort_unless($applicant instanceof User && $intake instanceof ApplicantIntake, 403);

                    try {
                        app(WithdrawApplicantIntake::class)->execute(
                            $intake,
                            $applicant,
                            (string) $data['reason'],
                        );
                        Notification::make()->title('Application withdrawn')->success()->send();
                        $this->redirect(self::getUrl());
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Application cannot be withdrawn')
                            ->body($exception->validator->errors()->first())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ApplicantIntake::query()
                ->with(['program', 'term', 'withdrawalActivity'])
                ->where('user_id', Auth::id()))
            ->columns([
                TextColumn::make('term.label')
                    ->label('Admission Term')
                    ->placeholder('Not assigned')
                    ->searchable(),
                TextColumn::make('program.name')
                    ->label('Program')
                    ->placeholder('Not assigned')
                    ->wrap(),
                TextColumn::make('admission_category')
                    ->label('Applicant Type')
                    ->formatStateUsing(fn (string $state): string => str($state)
                        ->replace('_', ' ')
                        ->lower()
                        ->title()
                        ->toString())
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $this->statusLabel($state))
                    ->color(fn (string $state): string => $this->statusColor($state)),
                TextColumn::make('activity_date')
                    ->label('Relevant Date')
                    ->state(function (ApplicantIntake $record): string {
                        if ($record->status === ApplicantIntake::StatusWithdrawn && $record->submitted_at === null) {
                            return 'Withdrawn before submission';
                        }

                        $date = $record->archived_at ?? $record->submitted_at ?? $record->updated_at;

                        return DisplayDateTime::format($date);
                    })
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('viewApplication')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->modalHeading('Application record')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        Section::make('Application details')
                            ->schema([
                                TextEntry::make('term.label')
                                    ->label('Admission Term')
                                    ->placeholder('Not assigned'),
                                TextEntry::make('program.name')
                                    ->label('Program')
                                    ->placeholder('Not assigned'),
                                TextEntry::make('admission_category')
                                    ->label('Applicant Type')
                                    ->formatStateUsing(fn (string $state): string => str($state)
                                        ->replace('_', ' ')
                                        ->lower()
                                        ->title()
                                        ->toString()),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => $this->statusLabel($state))
                                    ->color(fn (string $state): string => $this->statusColor($state)),
                                TextEntry::make('submitted_at')
                                    ->label('Submitted')
                                    ->dateTime('F j, Y, g:i A')
                                    ->placeholder('Not submitted'),
                                TextEntry::make('archived_at')
                                    ->label('Withdrawn')
                                    ->dateTime('F j, Y, g:i A')
                                    ->visible(fn (ApplicantIntake $record): bool => $record->status === ApplicantIntake::StatusWithdrawn),
                                TextEntry::make('withdrawal_reason')
                                    ->label('Withdrawal Reason')
                                    ->state(fn (ApplicantIntake $record): string => (string) (
                                        $record->withdrawalActivity?->properties?->get('reason')
                                        ?? 'No reason was recorded.'
                                    ))
                                    ->visible(fn (ApplicantIntake $record): bool => $record->status === ApplicantIntake::StatusWithdrawn)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No application history yet')
            ->emptyStateDescription('Saved and submitted admission applications will appear here.');
    }

    public function getIntake(): ?ApplicantIntake
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        return ApplicantIntake::query()
            ->with([
                'checklistItems.reviewer',
                'checklistItems.documentEvidence.reviewer',
                'program',
                'term',
                'withdrawalActivity.causer',
            ])
            ->where('user_id', $user->id)
            ->where('status', '!=', ApplicantIntake::StatusWithdrawn)
            ->latest('id')
            ->first();
    }

    public function admissionsAreOpen(): bool
    {
        return app(AdmissionWindowService::class)->hasOpenAdmissionsWindow();
    }

    private function canWithdraw(): bool
    {
        $intake = $this->getIntake();

        return $intake instanceof ApplicantIntake
            && in_array($intake->status, [ApplicantIntake::StatusDraft, ApplicantIntake::StatusPending], true)
            && $intake->reviewed_at === null
            && $intake->handed_over_at === null;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            ApplicantIntake::StatusDraft => 'Draft',
            ApplicantIntake::StatusPending => 'Pending Review',
            ApplicantIntake::StatusActionRequired => 'Action Required',
            ApplicantIntake::StatusForEvaluation => 'Awaiting Evaluation',
            ApplicantIntake::StatusApproved => 'Approved for Handover',
            ApplicantIntake::StatusWithdrawn => 'Withdrawn',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            ApplicantIntake::StatusPending => 'warning',
            ApplicantIntake::StatusActionRequired => 'danger',
            ApplicantIntake::StatusForEvaluation => 'info',
            ApplicantIntake::StatusApproved => 'success',
            default => 'gray',
        };
    }
}
