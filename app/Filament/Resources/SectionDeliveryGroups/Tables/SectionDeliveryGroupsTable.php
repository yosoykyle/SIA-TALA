<?php

namespace App\Filament\Resources\SectionDeliveryGroups\Tables;

use App\Models\SectionDeliveryGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SectionDeliveryGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['section.term', 'section.program', 'deliveryPattern']))
            ->columns([
                TextColumn::make('section.term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('section.name')
                    ->label('Section')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Group')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('deliveryPattern.code')
                    ->label('Pattern')
                    ->searchable(),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionDeliveryGroup::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextColumn::make('capacity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('assigned_count')
                    ->label('Assigned')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('available_seats')
                    ->label('Available')
                    ->state(fn (SectionDeliveryGroup $record): int => $record->availableSeats())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger'),
                IconColumn::make('room_required')
                    ->boolean(),
                TextColumn::make('room')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionDeliveryGroup::statusOptions()[$state] ?? str($state)->headline()->toString())),
            ])
            ->filters([
                SelectFilter::make('modality')
                    ->options(SectionDeliveryGroup::modalityOptions()),
                SelectFilter::make('status')
                    ->options(SectionDeliveryGroup::statusOptions()),
                SelectFilter::make('section_id')
                    ->label('Section')
                    ->relationship('section', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
