<?php

namespace App\Filament\Resources\StudentProfiles\RelationManagers;

use App\Actions\Applicants\ApplicantEvidenceService;
use App\Models\ChecklistItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class ChecklistItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'checklistItems';

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('requirement_type')
            ->columns([
                TextColumn::make('requirement_type')
                    ->label('Requirement Type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ChecklistItem::StatusAccepted => 'success',
                        ChecklistItem::StatusRejected => 'danger',
                        ChecklistItem::StatusPending => 'warning',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('blocking_level')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ChecklistItem::BlockingHandover => 'danger',
                        ChecklistItem::BlockingEnrollment => 'warning',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('verification_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ChecklistItem::VerificationVerified => 'success',
                        ChecklistItem::VerificationRejected => 'danger',
                        ChecklistItem::VerificationNotReviewed => 'warning',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('deadline')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reviewer.name')
                    ->label('Reviewed By')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reviewed_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('waiver_reason')
                    ->label('Review Notes')
                    ->placeholder('-')
                    ->limit(50),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                Action::make('recordPhysicalReceipt')
                    ->label('Record Physical Receipt')
                    ->icon(Heroicon::OutlinedInboxArrowDown)
                    ->color('info')
                    ->visible(fn (ChecklistItem $record): bool => $this->canRecordPhysicalReceipt($record))
                    ->schema([
                        TextInput::make('receipt_reference')
                            ->label('Receipt or Reference Number')
                            ->helperText('Optional institutional reference for the physical submission.')
                            ->maxLength(120),
                    ])
                    ->action(fn (ChecklistItem $record, array $data): mixed => $this->recordPhysicalReceipt(
                        item: $record,
                        reference: $data['receipt_reference'] ?? null,
                    )),
                Action::make('verifyDocument')
                    ->label('Verify Document')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ChecklistItem $record): bool => $this->canReview($record))
                    ->action(fn (ChecklistItem $record): mixed => $this->runReview(
                        item: $record,
                        decision: ApplicantEvidenceService::DecisionAccept,
                        successTitle: 'Document verified successfully',
                    )),
                Action::make('downloadEvidence')
                    ->label('Download Evidence')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (ChecklistItem $record): bool => $this->canDownload($record))
                    ->action(fn (ChecklistItem $record): mixed => $this->downloadEvidence($record)),
                Action::make('rejectDocument')
                    ->label('Reject Document')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (ChecklistItem $record): bool => $this->canReview($record))
                    ->schema([
                        Textarea::make('notes')
                            ->label('Rejection Notes')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(fn (ChecklistItem $record, array $data): mixed => $this->runReview(
                        item: $record,
                        decision: ApplicantEvidenceService::DecisionReject,
                        successTitle: 'Document rejected successfully',
                        reason: (string) $data['notes'],
                    )),
            ]);
    }

    private function canReview(ChecklistItem $item): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasRole(User::StaffRoleRegistrar)
            && $user->can('approve-documents')
            && ! $item->isResolved()
            && $item->verification_status !== ChecklistItem::VerificationRejected
            && ($item->evidence_method !== ChecklistItem::EvidenceMethodPhysicalCopy
                || $item->status === ChecklistItem::StatusReceivedPhysical)
            && ($item->owner_type !== ChecklistItem::OwnerApplicant
                || ($item->applicantIntake !== null && $user->can('review', $item->applicantIntake)));
    }

    private function canRecordPhysicalReceipt(ChecklistItem $item): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasRole(User::StaffRoleRegistrar)
            && $user->can('approve-documents')
            && $item->evidence_method === ChecklistItem::EvidenceMethodPhysicalCopy
            && ! $item->isResolved()
            && $item->status !== ChecklistItem::StatusReceivedPhysical
            && ($item->owner_type !== ChecklistItem::OwnerApplicant
                || ($item->applicantIntake !== null && $user->can('review', $item->applicantIntake)));
    }

    private function canDownload(ChecklistItem $item): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasRole(User::StaffRoleRegistrar)
            && $user->can('approve-documents')
            && $item->documentEvidence()->exists()
            && ($item->owner_type !== ChecklistItem::OwnerApplicant
                || ($item->applicantIntake !== null && $user->can('downloadEvidence', $item->applicantIntake)));
    }

    private function runReview(
        ChecklistItem $item,
        string $decision,
        string $successTitle,
        ?string $reason = null,
    ): mixed {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            app(ApplicantEvidenceService::class)->review(
                checklistItem: $item,
                actor: $actor,
                decision: $decision,
                reason: $reason,
            );

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Document review blocked')
                ->body($exception->validator->errors()->first())
                ->danger()
                ->send();
        }

        return null;
    }

    private function recordPhysicalReceipt(ChecklistItem $item, mixed $reference): mixed
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            app(ApplicantEvidenceService::class)->recordPhysicalReceipt(
                checklistItem: $item,
                actor: $actor,
                reference: is_string($reference) ? $reference : null,
            );

            Notification::make()
                ->title('Physical requirement recorded as received')
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Physical receipt cannot be recorded')
                ->body($exception->validator->errors()->first())
                ->danger()
                ->send();
        }

        return null;
    }

    private function downloadEvidence(ChecklistItem $item): mixed
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            return app(ApplicantEvidenceService::class)->downloadChecklistEvidence($item, $actor);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Evidence file unavailable')
                ->body($exception->validator->errors()->first())
                ->danger()
                ->send();

            return null;
        }
    }
}
