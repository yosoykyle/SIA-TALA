<?php

namespace App\Filament\Resources\Terms\Tables;

use App\Models\Term;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TermsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('term_name')->label('Term')->searchable()->sortable()->weight('bold'),
                TextColumn::make('academic_year_label')
                    ->label('Academic Year')
                    ->state(fn (Term $record): string => $record->academicYear?->displayLabel() ?? '-')
                    ->placeholder('-'),
                TextColumn::make('term_type')->label('Type')->badge()->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
                TextColumn::make('class_start_date')->date()->sortable()->placeholder('-'),
                TextColumn::make('class_end_date')->date()->sortable()->placeholder('-'),
                TextColumn::make('scheduling_starts_at')->dateTime()->sortable()->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('term_type')->label('Type')->options([
                    'quarter' => 'Quarter',
                    'semester' => 'Semester',
                    'summer' => 'Summer',
                ]),
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
            ->defaultSort('term_start_date', 'desc');
    }
}
