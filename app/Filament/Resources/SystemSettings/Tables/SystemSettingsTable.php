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
                TextColumn::make('purpose')
                    ->label('Purpose')
                    ->state(fn (SystemSetting $record): string => SystemSetting::descriptionFor($record->key))
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
            ->recordActions([])
            ->toolbarActions([]);
    }
}
