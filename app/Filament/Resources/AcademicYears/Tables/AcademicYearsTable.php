<?php

namespace App\Filament\Resources\AcademicYears\Tables;

use App\Models\AcademicYear;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AcademicYearsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('academic_year')
                    ->label('Academic Year')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('education_level')
                    ->label('Education Level')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, AcademicYear $record): string => $record->educationLevelLabel())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, AcademicYear $record): string => $record->statusLabel())
                    ->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'closed' => 'warning',
                        'archived' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('school_year_start_date')->date()->sortable(),
                TextColumn::make('school_year_end_date')->date()->sortable(),
                TextColumn::make('reference_note')->limit(40)->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('education_level')
                    ->label('Education Level')
                    ->options(AcademicYear::educationLevelOptions()),
                SelectFilter::make('status')
                    ->options(AcademicYear::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('school_year_start_date', 'desc');
    }
}
