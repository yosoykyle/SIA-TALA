<?php

namespace App\Filament\Resources\Activities\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('log_name')
                    ->label('Audit area')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? str((string) $state)->headline()->toString()
                        : 'General')
                    ->badge()
                    ->searchable(),
                TextColumn::make('event')
                    ->label('Change')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? str((string) $state)->headline()->toString()
                        : 'Recorded')
                    ->badge()
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Recorded action')
                    ->searchable()
                    ->limit(80),
                TextColumn::make('subject_type')
                    ->label('Record type')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? str(class_basename((string) $state))->headline()->toString()
                        : 'Not linked')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('causer.email')
                    ->label('Actor')
                    ->searchable()
                    ->placeholder('System'),
                TextColumn::make('created_at')
                    ->label('Recorded at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->options(fn (): array => self::eventOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->stackedOnMobile()
            ->emptyStateHeading('No audit records')
            ->emptyStateDescription('Recorded staff and system changes appear here.')
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<string, string>
     */
    private static function eventOptions(): array
    {
        return [
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',
            'staff_account_archived' => 'Staff Account Archived',
            'staff_account_restored' => 'Staff Account Restored',
        ];
    }
}
