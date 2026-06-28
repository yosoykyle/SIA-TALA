<?php

namespace App\Filament\Resources\StudentProfiles\RelationManagers;

use App\Models\ChecklistItem;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                        ChecklistItem::STATUS_ACCEPTED => 'success',
                        ChecklistItem::STATUS_REJECTED => 'danger',
                        ChecklistItem::STATUS_PENDING => 'warning',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('blocking_level')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'blocks_handover' => 'danger',
                        'blocks_enrollment' => 'warning',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('verification_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ChecklistItem::VERIFICATION_STATUS_VERIFIED => 'success',
                        ChecklistItem::VERIFICATION_STATUS_REJECTED => 'danger',
                        ChecklistItem::VERIFICATION_STATUS_NOT_REVIEWED => 'warning',
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
                TextColumn::make('notes')
                    ->placeholder('-')
                    ->limit(50),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->actions([
                Action::make('verifyDocument')
                    ->label('Verify Document')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (ChecklistItem $record): void {
                        $record->update([
                            'status' => ChecklistItem::STATUS_ACCEPTED,
                            'verification_status' => ChecklistItem::VERIFICATION_STATUS_VERIFIED,
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => Carbon::now(),
                        ]);

                        Notification::make()
                            ->title('Document verified successfully')
                            ->success()
                            ->send();
                    }),
                Action::make('rejectDocument')
                    ->label('Reject Document')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Rejection Notes')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (ChecklistItem $record, array $data): void {
                        $record->update([
                            'status' => ChecklistItem::STATUS_REJECTED,
                            'verification_status' => ChecklistItem::VERIFICATION_STATUS_REJECTED,
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => Carbon::now(),
                            'notes' => $data['notes'],
                        ]);

                        Notification::make()
                            ->title('Document rejected successfully')
                            ->danger()
                            ->send();
                    }),
            ]);
    }
}
