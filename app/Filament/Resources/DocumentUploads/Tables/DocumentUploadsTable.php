<?php

namespace App\Filament\Resources\DocumentUploads\Tables;

use App\Models\DocumentUpload;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Throwable;

class DocumentUploadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'user', 'term', 'registrarReviewer']))
            ->columns([
                TextColumn::make('studentProfile.student_id')
                    ->label('Student ID')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Uploader')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('document_type')
                    ->label('Document')
                    ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->headline()->toString())
                    ->searchable(),
                TextColumn::make('ocr_review_status')
                    ->label('Review')
                    ->badge()
                    ->colors([
                        'gray' => DocumentUpload::ReviewStatusUploaded,
                        'info' => DocumentUpload::ReviewStatusOcrExtracted,
                        'warning' => DocumentUpload::ReviewStatusPendingRegistrarReview,
                        'success' => DocumentUpload::ReviewStatusRegistrarApproved,
                        'danger' => DocumentUpload::ReviewStatusRejected,
                    ])
                    ->searchable(),
                TextColumn::make('ocr_confidence')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('-')
                    ->sortable(),
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
                SelectFilter::make('ocr_review_status')
                    ->label('Review Status')
                    ->options([
                        DocumentUpload::ReviewStatusUploaded => 'Uploaded',
                        DocumentUpload::ReviewStatusOcrExtracted => 'OCR Extracted',
                        DocumentUpload::ReviewStatusStudentConfirmed => 'Student Confirmed',
                        DocumentUpload::ReviewStatusPendingRegistrarReview => 'Pending Registrar Review',
                        DocumentUpload::ReviewStatusRegistrarApproved => 'Registrar Approved',
                        DocumentUpload::ReviewStatusNeedsCorrection => 'Needs Correction',
                        DocumentUpload::ReviewStatusRejected => 'Rejected',
                        DocumentUpload::ReviewStatusNeedsManualReview => 'Needs Manual Review',
                        DocumentUpload::ReviewStatusManualEntry => 'Manual Entry',
                    ]),
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
                && $record->ocr_review_status !== DocumentUpload::ReviewStatusRegistrarApproved)
            ->action(fn (DocumentUpload $record) => self::transitionReview(
                $record,
                DocumentUpload::ReviewStatusRegistrarApproved,
                'document_upload_approved',
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
                && $record->ocr_review_status !== DocumentUpload::ReviewStatusRegistrarApproved)
            ->action(fn (DocumentUpload $record, array $data) => self::transitionReview(
                $record,
                DocumentUpload::ReviewStatusNeedsCorrection,
                'document_upload_needs_correction',
                'Document marked for correction',
                (string) $data['reason'],
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
                && $record->ocr_review_status !== DocumentUpload::ReviewStatusRejected)
            ->action(fn (DocumentUpload $record, array $data) => self::transitionReview(
                $record,
                DocumentUpload::ReviewStatusRejected,
                'document_upload_rejected',
                'Document rejected',
                (string) $data['reason'],
            ));
    }

    private static function transitionReview(
        DocumentUpload $record,
        string $status,
        string $event,
        string $successTitle,
        ?string $reason = null,
    ): void {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            DB::transaction(function () use ($record, $status, $event, $actor, $reason): void {
                $timestamp = CarbonImmutable::now(config('app.timezone'));

                $record->forceFill([
                    'ocr_review_status' => $status,
                    'registrar_reviewed_by' => $actor->id,
                    'registrar_reviewed_at' => $timestamp,
                    'registrar_approved_payload' => $status === DocumentUpload::ReviewStatusRegistrarApproved
                        ? ($record->student_confirmed_payload ?? [])
                        : $record->registrar_approved_payload,
                ])->save();

                DB::table('activity_log')->insert([
                    'log_name' => 'document_review',
                    'description' => 'Registrar document review transition.',
                    'subject_type' => DocumentUpload::class,
                    'subject_id' => $record->id,
                    'event' => $event,
                    'causer_type' => User::class,
                    'causer_id' => $actor->id,
                    'properties' => json_encode([
                        'status_after' => $status,
                        'reason' => $reason,
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => $timestamp->toDateTimeString(),
                    'updated_at' => $timestamp->toDateTimeString(),
                ]);
            });

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
