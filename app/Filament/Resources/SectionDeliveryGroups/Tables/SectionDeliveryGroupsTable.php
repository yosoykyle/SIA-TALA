<?php

namespace App\Filament\Resources\SectionDeliveryGroups\Tables;

use App\Models\SectionDeliveryGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SectionDeliveryGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['section.termOffering.term', 'section.termOffering.curriculumEntry.courseSpecification.course']))
            ->columns([
                TextColumn::make('section.termOffering.term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('section.code')
                    ->label('Section Code')
                    ->searchable(),
                TextColumn::make('section.termOffering.curriculumEntry.courseSpecification.course.code')
                    ->label('Course')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Group')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('expected_count')
                    ->label('Expected')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionDeliveryGroup::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionDeliveryGroup::stateOptions()[$state] ?? str($state)->headline()->toString())),
            ])
            ->filters([
                SelectFilter::make('modality')
                    ->options(SectionDeliveryGroup::modalityOptions()),
                SelectFilter::make('state')
                    ->options(SectionDeliveryGroup::stateOptions()),
                SelectFilter::make('section_id')
                    ->label('Section')
                    ->relationship('section', 'code')
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
