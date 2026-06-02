<?php

namespace App\Filament\Resources\GradeCorrections\Tables;

use App\Actions\Grades\GradeCorrectionService;
use App\Enums\GradeCorrectionStatus;
use App\Models\GradeCorrection;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class GradeCorrectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['student', 'grade', 'subject', 'term', 'assignedTo', 'creator']))
            ->columns([
                TextColumn::make('student.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject.code')
                    ->label('Subject')
                    ->searchable(),
                TextColumn::make('subject.description')
                    ->label('Description')
                    ->limit(35)
                    ->toggleable(),
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('assessment_component')
                    ->label('Component')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('current_grade')
                    ->label('Current Grade')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('requested_action')
                    ->label('Requested Action')
                    ->limit(45)
                    ->searchable(),
                TextColumn::make('reason')
                    ->limit(45)
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (GradeCorrectionStatus|string $state): string => str($state instanceof GradeCorrectionStatus ? $state->value : $state)->replace('_', ' ')->headline()->toString())
                    ->color(fn (GradeCorrectionStatus|string $state): string => match ($state instanceof GradeCorrectionStatus ? $state->value : $state) {
                        GradeCorrectionStatus::Submitted->value => 'warning',
                        GradeCorrectionStatus::UnderReview->value => 'info',
                        GradeCorrectionStatus::Resolved->value => 'success',
                        GradeCorrectionStatus::Rejected->value => 'danger',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('assignedTo.name')
                    ->label('Assigned To')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('resolved_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        GradeCorrectionStatus::Submitted->value => 'Submitted',
                        GradeCorrectionStatus::UnderReview->value => 'Under Review',
                        GradeCorrectionStatus::Resolved->value => 'Resolved',
                        GradeCorrectionStatus::Rejected->value => 'Rejected',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                self::startReviewAction(),
                self::rejectAction(),
                self::resolveWithoutGradeChangeAction(),
                self::resolveWithGradeChangeAction(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    private static function startReviewAction(): Action
    {
        return Action::make('startReview')
            ->label('Start Review')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (GradeCorrection $record): bool => auth()->user()?->can('startReview', $record) ?? false)
            ->action(function (GradeCorrection $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handleCorrectionAction(function () use ($record, $actor): void {
                    app(GradeCorrectionService::class)->startReview($record, $actor);
                }, 'Grade correction moved under review');
            });
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->schema([
                Textarea::make('reason')
                    ->label('Rejection Reason')
                    ->required()
                    ->maxLength(250),
            ])
            ->modalSubmitActionLabel('Reject Correction')
            ->visible(fn (GradeCorrection $record): bool => auth()->user()?->can('reject', $record) ?? false)
            ->action(function (GradeCorrection $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handleCorrectionAction(function () use ($record, $data, $actor): void {
                    app(GradeCorrectionService::class)->reject(
                        correction: $record,
                        registrar: $actor,
                        reason: (string) $data['reason'],
                    );
                }, 'Grade correction rejected');
            });
    }

    private static function resolveWithoutGradeChangeAction(): Action
    {
        return Action::make('resolveWithoutGradeChange')
            ->label('Resolve - No Grade Change')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->schema([
                Textarea::make('resolution_notes')
                    ->label('Resolution Notes')
                    ->required()
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel('Resolve Correction')
            ->visible(fn (GradeCorrection $record): bool => auth()->user()?->can('resolveWithoutGradeChange', $record) ?? false)
            ->action(function (GradeCorrection $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handleCorrectionAction(function () use ($record, $data, $actor): void {
                    app(GradeCorrectionService::class)->resolveWithoutGradeChange(
                        correction: $record,
                        registrar: $actor,
                        resolutionNotes: (string) $data['resolution_notes'],
                    );
                }, 'Grade correction resolved');
            });
    }

    private static function resolveWithGradeChangeAction(): Action
    {
        return Action::make('resolveWithGradeChange')
            ->label('Resolve - Grade Override')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('warning')
            ->schema([
                Select::make('academic_head_id')
                    ->label('Academic Head Authorizer')
                    ->options(fn (): array => User::role('academic-head')->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('prelim_grade')
                    ->label('Prelim/Q1')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100),
                TextInput::make('midterm_grade')
                    ->label('Midterm/Q2')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100),
                TextInput::make('final_grade')
                    ->label('Final Raw')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100),
                TextInput::make('grade')
                    ->label('Final Grade')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100),
                TextInput::make('remarks')
                    ->maxLength(255),
                Textarea::make('approval_reason')
                    ->label('Academic Head Approval Reason')
                    ->required()
                    ->maxLength(500),
                Textarea::make('resolution_notes')
                    ->label('Resolution Notes')
                    ->required()
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel('Resolve With Override')
            ->visible(fn (GradeCorrection $record): bool => auth()->user()?->can('resolveWithGradeChange', $record) ?? false)
            ->action(function (GradeCorrection $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handleCorrectionAction(function () use ($record, $data, $actor): void {
                    $academicHead = User::query()->findOrFail((int) $data['academic_head_id']);

                    app(GradeCorrectionService::class)->resolveWithGradeChange(
                        correction: $record,
                        registrar: $actor,
                        academicHead: $academicHead,
                        gradeAttributes: self::gradeOverridePayload($data),
                        approvalReason: (string) $data['approval_reason'],
                        resolutionNotes: (string) $data['resolution_notes'],
                    );
                }, 'Grade correction resolved with Academic Head override');
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool|float|int|string|null>
     */
    private static function gradeOverridePayload(array $data): array
    {
        $payload = [];

        foreach (['prelim_grade', 'midterm_grade', 'final_grade', 'grade', 'remarks'] as $field) {
            if (($data[$field] ?? null) === null || $data[$field] === '') {
                continue;
            }

            $payload[$field] = $data[$field];
        }

        return $payload;
    }

    /**
     * @param  callable(): void  $callback
     */
    private static function handleCorrectionAction(callable $callback, string $successTitle): void
    {
        try {
            $callback();

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Grade correction action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
