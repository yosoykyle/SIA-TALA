<?php

namespace App\Filament\Resources\Rooms\Tables;

use App\Filament\Resources\Rooms\Schemas\RoomInfolist;
use App\Models\Room;
use App\Support\RoomEquipment;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
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
                    ->label('Room type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Room::typeOptions()[$state] ?? str($state)->headline()->toString()))
                    ->sortable(),
                TextColumn::make('capacity')->numeric()->suffix(' seats')->sortable()->placeholder('-'),
                TextColumn::make('features.feature_key')
                    ->label('Equipment')->formatStateUsing(fn (?string $state): string => RoomEquipment::label($state))
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
                    ->label('Room type')
                    ->options(Room::typeOptions()),
                SelectFilter::make('is_active')->label('Active')->options([
                    '1' => 'Active',
                    '0' => 'Inactive',
                ]),
            ])
            ->recordActions([
                ViewAction::make()->url(null)->modalHeading(fn (Room $record): string => $record->code.' · '.$record->name)->schema(fn (Schema $schema): Schema => RoomInfolist::configure($schema)),
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('code');
    }
}
