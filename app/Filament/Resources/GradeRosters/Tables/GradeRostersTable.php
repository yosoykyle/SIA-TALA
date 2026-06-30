<?php

namespace App\Filament\Resources\GradeRosters\Tables;

use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Models\GradeRoster;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GradeRostersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('termOffering.term.label')->label('Term')->searchable(),
                TextColumn::make('section.code')->label('Section')->searchable(),
                TextColumn::make('faculty.name')->label('Faculty')->searchable(),
                TextColumn::make('state')->badge(),
                TextColumn::make('rows_count')->counts('rows')->label('Rows'),
                TextColumn::make('submitted_at')->dateTime()->sortable(),
                TextColumn::make('released_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->options([
                    GradeRoster::StateDraft => 'Draft',
                    GradeRoster::StateSubmitted => 'Submitted',
                    GradeRoster::StateReturned => 'Returned',
                    GradeRoster::StateReleased => 'Posted & Released',
                    GradeRoster::StateLateNotSubmitted => 'Late / Not Submitted',
                ]),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (GradeRoster $record): string => GradeRosterResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('id', 'desc');
    }
}
