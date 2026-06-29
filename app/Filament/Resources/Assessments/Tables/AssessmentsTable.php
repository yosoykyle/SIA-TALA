<?php

namespace App\Filament\Resources\Assessments\Tables;

use App\Models\Assessment;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AssessmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['enrollment.studentProfile.program', 'enrollment.term']))
            ->columns([
                TextColumn::make('enrollment.studentProfile.student_number')
                    ->label('Student No.')
                    ->searchable(),
                TextColumn::make('enrollment.studentProfile.last_name')
                    ->label('Student')
                    ->searchable(),
                TextColumn::make('enrollment.studentProfile.program.code')
                    ->label('Program')
                    ->placeholder('-'),
                TextColumn::make('enrollment.term.label')
                    ->label('Term')
                    ->searchable(),
                TextColumn::make('version')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('state')
                    ->badge()
                    ->searchable(),
                TextColumn::make('total')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('required_downpayment')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('activated_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options([
                        Assessment::StateDraft => 'Draft',
                        Assessment::StateActive => 'Active',
                        Assessment::StateSuperseded => 'Superseded',
                        Assessment::StateCancelled => 'Cancelled',
                        Assessment::StateLocked => 'Locked',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
