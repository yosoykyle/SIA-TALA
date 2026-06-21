<?php

namespace App\Filament\Resources\Sections\Tables;

use App\Models\Section;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['term', 'program', 'curriculum']))
            ->columns([
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Section')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('program.code')
                    ->label('Program')
                    ->searchable(),
                TextColumn::make('year_level')
                    ->label('Year Level')
                    ->searchable(),
                TextColumn::make('curriculum_period')
                    ->label('Period')
                    ->searchable(),
                TextColumn::make('curriculum.version_name')
                    ->label('Curriculum')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Section::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextColumn::make('room')
                    ->label('Fixed Room')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('max_seats')
                    ->label('Max')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('enrolled_count')
                    ->label('Enrolled')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('term_id')
                    ->label('Term')
                    ->relationship('term', 'term_name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('program_id')
                    ->label('Program')
                    ->relationship('program', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('modality')
                    ->options(Section::modalityOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
