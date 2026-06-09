<?php

namespace App\Filament\Resources\FacultySubjectEligibilities\Tables;

use App\Models\FacultySubjectEligibility;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FacultySubjectEligibilitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('faculty.name')
                    ->label('Faculty')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject.code')
                    ->label('Subject')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject.description')
                    ->label('Description')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->placeholder('Reusable')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => FacultySubjectEligibility::statusColors()[$state] ?? 'gray')
                    ->formatStateUsing(fn (?string $state): string => FacultySubjectEligibility::statusOptions()[$state] ?? '-'),
                TextColumn::make('priority')
                    ->numeric()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('max_weekly_hours')
                    ->label('Max weekly hours')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('approver.name')
                    ->label('Approved by')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('approved_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(FacultySubjectEligibility::statusOptions()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (FacultySubjectEligibility $record): bool => auth()->user()?->can('update', $record) ?? false),
            ])
            ->toolbarActions([]);
    }
}
