<?php

namespace App\Filament\Resources\GradeCorrections\Tables;

use App\Actions\Grades\GradeCorrectionService;
use App\Enums\GradeCorrectionStatus;
use App\Models\GradeCorrection;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
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
            ->modifyQueryUsing(fn ($query) => $query->with(['student', 'grade', 'subject', 'term', 'assignedTo', 'academicHeadReviewer', 'creator']))
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
                TextColumn::make('academic_head_review_status')
                    ->label('Academic Head Review')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => blank($state) ? 'Pending' : str($state)->headline()->toString())
                    ->color(fn (?string $state): string => match ($state) {
                        GradeCorrection::AcademicHeadReviewApproved => 'success',
                        GradeCorrection::AcademicHeadReviewRejected => 'danger',
                        GradeCorrection::AcademicHeadReviewPending, null => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('Pending')
                    ->toggleable(),
                TextColumn::make('academicHeadReviewer.name')
                    ->label('Academic Head Reviewer')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                self::approveOfficialGradeChangeAction(),
                self::rejectOfficialGradeChangeAction(),
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
            ->label('Resolve - Apply Approved Grade Change')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('warning')
            ->schema([
                TextInput::make('college_prelim')
                    ->label('College Prelim Raw Score')
                    ->visible(fn (?GradeCorrection $record): bool => self::usesCollegeGrading($record))
                    ->required(fn (?GradeCorrection $record): bool => self::usesCollegeGrading($record))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100),
                TextInput::make('college_midterm')
                    ->label('College Midterm Raw Score')
                    ->visible(fn (?GradeCorrection $record): bool => self::usesCollegeGrading($record))
                    ->required(fn (?GradeCorrection $record): bool => self::usesCollegeGrading($record))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100),
                TextInput::make('college_final')
                    ->label('College Final Raw Score')
                    ->visible(fn (?GradeCorrection $record): bool => self::usesCollegeGrading($record))
                    ->required(fn (?GradeCorrection $record): bool => self::usesCollegeGrading($record))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->helperText('The final raw average, equivalent grade, and remarks are calculated by the same College grading service used by Faculty grade encoding.'),
                TextInput::make('shs_q1')
                    ->label('SHS Quarter 1 Grade')
                    ->visible(fn (?GradeCorrection $record): bool => self::usesShsGrading($record))
                    ->required(fn (?GradeCorrection $record): bool => self::usesShsGrading($record))
                    ->numeric()
                    ->minValue(60)
                    ->maxValue(100),
                TextInput::make('shs_q2')
                    ->label('SHS Quarter 2 Grade')
                    ->visible(fn (?GradeCorrection $record): bool => self::usesShsGrading($record))
                    ->required(fn (?GradeCorrection $record): bool => self::usesShsGrading($record))
                    ->numeric()
                    ->minValue(60)
                    ->maxValue(100)
                    ->helperText('The final SHS grade and remarks are calculated by the same SHS grading service used by Faculty grade encoding.'),
                Textarea::make('resolution_notes')
                    ->label('Resolution Notes')
                    ->helperText('The Academic Head approval reason is captured from the in-system approval action; this field records the Registrar resolution note.')
                    ->required()
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel('Apply Approved Change')
            ->visible(fn (GradeCorrection $record): bool => auth()->user()?->can('resolveWithGradeChange', $record) ?? false)
            ->action(function (GradeCorrection $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handleCorrectionAction(function () use ($record, $data, $actor): void {
                    app(GradeCorrectionService::class)->resolveWithGradeChange(
                        correction: $record,
                        registrar: $actor,
                        gradeAttributes: self::gradeOverridePayload($data),
                        resolutionNotes: (string) $data['resolution_notes'],
                    );
                }, 'Approved grade correction applied');
            });
    }

    private static function approveOfficialGradeChangeAction(): Action
    {
        return Action::make('approveOfficialGradeChange')
            ->label('Approve Official Grade Change')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('success')
            ->schema([
                Textarea::make('approval_reason')
                    ->label('Academic Head Approval Reason')
                    ->required()
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel('Approve Grade Change')
            ->visible(fn (GradeCorrection $record): bool => auth()->user()?->can('approveOfficialGradeChange', $record) ?? false)
            ->action(function (GradeCorrection $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handleCorrectionAction(function () use ($record, $data, $actor): void {
                    app(GradeCorrectionService::class)->approveOfficialGradeChange(
                        correction: $record,
                        academicHead: $actor,
                        approvalReason: (string) $data['approval_reason'],
                    );
                }, 'Official grade change approved');
            });
    }

    private static function rejectOfficialGradeChangeAction(): Action
    {
        return Action::make('rejectOfficialGradeChange')
            ->label('Reject Official Grade Change')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->schema([
                Textarea::make('rejection_reason')
                    ->label('Academic Head Rejection Reason')
                    ->required()
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel('Reject Grade Change')
            ->visible(fn (GradeCorrection $record): bool => auth()->user()?->can('rejectOfficialGradeChange', $record) ?? false)
            ->action(function (GradeCorrection $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handleCorrectionAction(function () use ($record, $data, $actor): void {
                    app(GradeCorrectionService::class)->rejectOfficialGradeChange(
                        correction: $record,
                        academicHead: $actor,
                        rejectionReason: (string) $data['rejection_reason'],
                    );
                }, 'Official grade change rejected');
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool|float|int|string|null>
     */
    private static function gradeOverridePayload(array $data): array
    {
        $payload = [];

        foreach (['college_prelim', 'college_midterm', 'college_final', 'shs_q1', 'shs_q2'] as $field) {
            if (($data[$field] ?? null) === null || $data[$field] === '') {
                continue;
            }

            $payload[$field] = $data[$field];
        }

        return $payload;
    }

    private static function usesCollegeGrading(?GradeCorrection $record): bool
    {
        return $record?->grade !== null && ! self::usesShsGrading($record);
    }

    private static function usesShsGrading(?GradeCorrection $record): bool
    {
        $record?->loadMissing('grade');

        return $record?->grade?->usesShsGrading() ?? false;
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
