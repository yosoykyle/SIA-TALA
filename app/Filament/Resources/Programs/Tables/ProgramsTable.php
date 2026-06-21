<?php

namespace App\Filament\Resources\Programs\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProgramsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->weight('bold'),
                TextColumn::make('name')->label('Program')->searchable()->sortable(),
                TextColumn::make('department')->label('Level')->badge()->formatStateUsing(fn (?string $state): string => match ($state) {
                    'college' => 'College',
                    default => str((string) $state)->headline()->toString(),
                })->searchable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('department')->label('Level')->options([
                    'college' => 'College',
                ]),
                SelectFilter::make('is_active')->label('Status')->options([
                    '1' => 'Active',
                    '0' => 'Inactive',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('code');
    }
}
