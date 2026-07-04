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
                TextColumn::make('label')
                    ->label('Academic Year')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, AcademicYear $record): string => $record->statusLabel())
                    ->color(fn (?string $state): string => match ($state) {
                        AcademicYear::StateActive => 'success',
                        AcademicYear::StateClosed => 'warning',
                        AcademicYear::StateArchived => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('starts_on')->date()->sortable(),
                TextColumn::make('ends_on')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options(AcademicYear::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('starts_on', 'desc');
    }
}
