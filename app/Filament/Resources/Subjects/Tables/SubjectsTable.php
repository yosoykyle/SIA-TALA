<?php

namespace App\Filament\Resources\Subjects\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->weight('bold'),
                TextColumn::make('description')->label('Subject Title')->searchable()->sortable()->wrap(),
                TextColumn::make('units')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('lec_hours')->label('Legacy Lec Hours')->numeric(decimalPlaces: 2)->sortable()->placeholder('-'),
                TextColumn::make('department')->label('Level')->badge()->formatStateUsing(fn (?string $state): string => match ($state) {
                    'college' => 'College',
                    default => str((string) $state)->headline()->toString(),
                })->searchable(),
                TextColumn::make('subject_type')->label('Type')->placeholder('-')->searchable(),
                TextColumn::make('category')->placeholder('-')->searchable(),
            ])
            ->filters([
                SelectFilter::make('department')->label('Level')->options([
                    'college' => 'College',
                ]),
                SelectFilter::make('subject_type')->label('Type')->options([
                    'major' => 'Major / Professional',
                    'general_education' => 'General Education',
                    'professional_tesda' => 'Professional / TESDA',
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
