<?php

namespace App\Filament\Resources\FacultyAvailabilityPeriods\Tables;

use App\Models\FacultyAvailabilityPeriod;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FacultyAvailabilityPeriodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['term', 'creator'])->withCount('submissions'))
            ->columns([
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => FacultyAvailabilityPeriod::statusOptions()[$state] ?? '-')
                    ->color(fn (?string $state): string => match ($state) {
                        FacultyAvailabilityPeriod::StatusOpen => 'success',
                        FacultyAvailabilityPeriod::StatusLocked => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('opens_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('closes_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('submissions_count')
                    ->label('Submissions')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->placeholder('-'),
                TextColumn::make('locked_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(FacultyAvailabilityPeriod::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (FacultyAvailabilityPeriod $record): bool => ! $record->isLocked()),
            ])
            ->toolbarActions([])
            ->defaultSort('opens_at', 'desc');
    }
}
