<?php

namespace App\Filament\Resources\TranscriptRequests\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TranscriptRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('external_request_reference')->label('Request')->searchable()->sortable(),
                TextColumn::make('studentProfile.student_number')->label('Student no.')->searchable(),
                TextColumn::make('student_name')->label('Student')->state(fn ($record): string => collect([
                    $record->studentProfile?->last_name,
                    $record->studentProfile?->first_name,
                ])->filter()->implode(', ')),
                TextColumn::make('requested_on')->date()->sortable(),
                TextColumn::make('due_on')->label('30-day target')->date()->sortable(),
                TextColumn::make('clearance')->state(fn ($record): string => $record->clearanceState())->badge(),
                TextColumn::make('state')->badge(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('recorded_at', 'desc');
    }
}
