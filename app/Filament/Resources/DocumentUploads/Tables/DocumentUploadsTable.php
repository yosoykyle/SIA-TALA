<?php

namespace App\Filament\Resources\DocumentUploads\Tables;

use App\Actions\Registrar\DocumentUploadReviewService;
use App\Models\DocumentUpload;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class DocumentUploadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'applicantIntake.user', 'user', 'term', 'registrarReviewer']))
            ->columns([
                TextColumn::make('studentProfile.student_id')
                    ->label('Student ID')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('applicantIntake.user.name')
                    ->label('Applicant')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('user.name')
                    ->label('Uploader')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('document_type')
                    ->label('Document')
                    ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->headline()->toString())
                    ->searchable(),
                TextColumn::make('review_status')
                    ->label('Review')
                    ->badge()
                    ->color(fn (DocumentUpload $record): string => DocumentUpload::reviewStatusColor($record->review_status))
                    ->searchable(),
                TextColumn::make('file_name')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('registrarReviewer.name')
                    ->label('Reviewed By')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('registrar_reviewed_at')
                    ->label('Reviewed At')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('review_status')
                    ->label('Review Status')
                    ->options(DocumentUpload::reviewStatusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::approveAction(),
                self::needsCorrectionAction(),
                self::rejectAction(),
            ])
            ->toolbarActions([]);
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (DocumentUpload $record): bool => self::registrarCanReview()
                && $record->isRegistrarReviewable())
            ->action(fn (DocumentUpload $record) => self::runAction(
                fn (DocumentUploadReviewService $service, User $actor): DocumentUpload => $service->approve($record, $actor),
                'Document approved',
            ));
    }

    private static function needsCorrectionAction(): Action
    {
        return Action::make('needsCorrection')
            ->label('Needs Correction')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('warning')
            ->schema([
                Textarea::make('reason')
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (DocumentUpload $record): bool => self::registrarCanReview()
                && $record->isRegistrarReviewable())
            ->action(fn (DocumentUpload $record, array $data) => self::runAction(
                fn (DocumentUploadReviewService $service, User $actor): DocumentUpload => $service->needsCorrection($record, $actor, (string) $data['reason']),
                'Document marked for correction',
            ));
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->schema([
                Textarea::make('reason')
                    ->required()
                    ->maxLength(500),
            ])
            ->requiresConfirmation()
            ->visible(fn (DocumentUpload $record): bool => self::registrarCanReview()
                && $record->isRegistrarReviewable())
            ->action(fn (DocumentUpload $record, array $data) => self::runAction(
                fn (DocumentUploadReviewService $service, User $actor): DocumentUpload => $service->reject($record, $actor, (string) $data['reason']),
                'Document rejected',
            ));
    }

    /**
     * @param  callable(DocumentUploadReviewService, User): DocumentUpload  $callback
     */
    private static function runAction(callable $callback, string $successTitle): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            $callback(app(DocumentUploadReviewService::class), $actor);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Document review failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private static function registrarCanReview(): bool
    {
        return auth()->user()?->can('approve-documents') ?? false;
    }
}
