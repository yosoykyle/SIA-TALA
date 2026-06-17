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
                TextColumn::make('department')->label('Education Level')->badge()->formatStateUsing(fn (?string $state): string => match ($state) {
                    'college' => 'College',
                    'shs' => 'Senior High School',
                    default => str((string) $state)->headline()->toString(),
                })->searchable(),
                TextColumn::make('subject_type')->label('Type')->placeholder('-')->searchable(),
                TextColumn::make('category')->placeholder('-')->searchable(),
            ])
            ->filters([
                SelectFilter::make('department')->label('Education Level')->options([
                    'college' => 'College',
                    'shs' => 'Senior High School',
                ]),
                SelectFilter::make('subject_type')->label('Type')->options([
                    'major' => 'Major / Professional',
                    'general_education' => 'General Education',
                    'core' => 'SHS Core',
                    'applied' => 'SHS Applied',
                    'specialized' => 'SHS Specialized',
                    'tvl' => 'TVL',
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
