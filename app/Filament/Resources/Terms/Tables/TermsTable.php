<?php

namespace App\Filament\Resources\Terms\Tables;

use App\Models\Term;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TermsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Term')->searchable()->sortable()->weight('bold'),
                TextColumn::make('academic_year_label')
                    ->label('Academic Year')
                    ->state(fn (Term $record): string => $record->academicYear?->displayLabel() ?? '-')
                    ->placeholder('-'),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Term::typeOptions()[$state] ?? str((string) $state)->headline()->toString())
                    ->sortable(),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Term::stateOptions()[$state] ?? str((string) $state)->headline()->toString())
                    ->color(fn (?string $state): string => match ($state) {
                        Term::StateActive => 'success',
                        Term::StateClosed => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('starts_on')->date()->sortable()->placeholder('-'),
                TextColumn::make('ends_on')->date()->sortable()->placeholder('-'),
                TextColumn::make('scheduling_slot_minutes')->label('Slot')->suffix(' min')->sortable(),
                TextColumn::make('default_max_units')->label('Faculty Max Units')->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Type')->options(Term::typeOptions()),
                SelectFilter::make('state')->options(Term::stateOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('starts_on', 'desc');
    }
}
