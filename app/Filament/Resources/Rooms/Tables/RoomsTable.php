<?php

namespace App\Filament\Resources\Rooms\Tables;

use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\RoomResource;
use App\Models\Room;
use App\Support\RoomEquipment;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Component;

class RoomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Room')
                    ->description(fn (Room $record): string => collect([$record->name, $record->building])->filter()->implode(' · '))
                    ->searchable(['code', 'name', 'building'])
                    ->sortable()
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('room_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Room::typeOptions()[$state] ?? str($state)->headline()->toString()))
                    ->wrap()
                    ->sortable(),
                TextColumn::make('capacity')->numeric()->suffix(' seats')->sortable()->placeholder('-'),
                TextColumn::make('features.feature_key')
                    ->label('Equipment')
                    ->formatStateUsing(fn (?string $state): string => RoomEquipment::label($state))
                    ->separator(',')
                    ->wrap()
                    ->placeholder('None recorded'),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->icon(fn (bool $state): string => $state ? 'heroicon-o-check-circle' : 'heroicon-o-minus-circle')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
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
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filter')->color('gray')->labeledFrom(null))
            ->recordActions([
                ViewAction::make()->label('View details')->button()->color('gray')->authorize('view')->url(fn (Room $record, Component $livewire): string => RoomResource::getUrl('view', [
                    'record' => $record,
                    ...($livewire instanceof ListRooms && $livewire->termId !== null ? ['termId' => $livewire->termId] : []),
                ])),
                EditAction::make()->button()->color('gray')->authorize('update')->url(fn (Room $record, Component $livewire): string => RoomResource::getUrl('edit', [
                    'record' => $record,
                    ...($livewire instanceof ListRooms && $livewire->termId !== null ? ['termId' => $livewire->termId] : []),
                ])),
            ])
            ->recordActionsColumnLabel('Actions')
            ->toolbarActions([])
            ->stackedOnMobile()
            ->emptyStateHeading('No rooms recorded')
            ->emptyStateDescription('Add a room for class assignments, or clear search and filters to see recorded rooms.')
            ->defaultSort('code');
    }
}
