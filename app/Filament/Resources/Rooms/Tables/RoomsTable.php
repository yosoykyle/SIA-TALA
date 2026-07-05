<?php

namespace App\Filament\Resources\Rooms\Tables;

use App\Models\Room;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RoomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->weight('bold'),
                TextColumn::make('name')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('building')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('room_type')
                    ->label('Room Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Room::typeOptions()[$state] ?? str($state)->headline()->toString()))
                    ->sortable(),
                TextColumn::make('capacity')->numeric()->suffix(' seats')->sortable()->placeholder('-'),
                TextColumn::make('features.feature_key')
                    ->label('Features')
                    ->badge()
                    ->separator(',')
                    ->limitList(3)
                    ->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
                TextColumn::make('notes')
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('room_type')
                    ->label('Room Type')
                    ->options(Room::typeOptions()),
                SelectFilter::make('is_active')->label('Active')->options([
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
