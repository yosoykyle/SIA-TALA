<?php

namespace App\Filament\Resources\AdmissionCycles\Tables;

use App\Models\AdmissionCycle;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdmissionCyclesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('label')->searchable()->wrap(),
                TextColumn::make('term.label')->label('Target term'),
                TextColumn::make('state')->badge()->sortable(),
                TextColumn::make('opens_at')->dateTime()->sortable(),
                TextColumn::make('closes_at')->dateTime()->sortable(),
                TextColumn::make('programs_count')->counts('programs')->label('Programs'),
            ])
            ->filters([
                SelectFilter::make('state')->options([
                    AdmissionCycle::StateDraft => 'Draft',
                    AdmissionCycle::StatePublished => 'Published',
                    AdmissionCycle::StateCancelled => 'Cancelled',
                ]),
                SelectFilter::make('term_id')
                    ->label('Target term')
                    ->relationship('term', 'label')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (AdmissionCycle $record): bool => $record->state === AdmissionCycle::StateDraft),
            ]);
    }
}
