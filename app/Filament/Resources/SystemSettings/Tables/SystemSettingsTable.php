<?php

namespace App\Filament\Resources\SystemSettings\Tables;

use App\Models\SystemSetting;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SystemSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Setting')
                    ->formatStateUsing(fn (?string $state): string => SystemSetting::labelFor($state))
                    ->description(fn (SystemSetting $record): string => $record->key)
                    ->searchable(),
                TextColumn::make('category')
                    ->label('Category')
                    ->state(fn (SystemSetting $record): string => SystemSetting::categoryFor($record->key))
                    ->badge(),
                TextColumn::make('operational_status')
                    ->label('Operational status')
                    ->state(fn (SystemSetting $record): string => SystemSetting::operationalStatusFor($record->key))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        SystemSetting::OperationalStatusOperational => 'success',
                        SystemSetting::OperationalStatusSuperseded => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('owner')
                    ->label('Owner')
                    ->state(fn (SystemSetting $record): string => SystemSetting::ownerFor($record->key))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('purpose')
                    ->label('Purpose')
                    ->state(fn (SystemSetting $record): string => SystemSetting::descriptionFor($record->key))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('runtime_consumer')
                    ->label('Runtime consumer / effect')
                    ->state(fn (SystemSetting $record): string => SystemSetting::runtimeConsumerFor($record->key))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('value')
                    ->label('Current value')
                    ->formatStateUsing(fn (SystemSetting $record): string => $record->formattedValue())
                    ->limit(80)
                    ->wrap()
                    ->toggleable(),
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
                //
            ])
            ->emptyStateHeading('No governed setting records')
            ->emptyStateDescription(
                'An empty registry is not a configuration failure. Fixed policies may be controlled by their owning workflows, application code, or the deployment environment.',
            )
            ->recordActions([])
            ->toolbarActions([]);
    }
}
