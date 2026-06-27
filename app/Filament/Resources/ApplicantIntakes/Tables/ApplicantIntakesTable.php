<?php

namespace App\Filament\Resources\ApplicantIntakes\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ApplicantIntakesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Applicant')
                    ->description(fn ($record): string => $record->user->email)
                    ->searchable(['name', 'email'])
                    ->sortable(),
                TextColumn::make('program.name')
                    ->label('Program')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('term.term_name')
                    ->label('Admission Term')
                    ->sortable(),
                TextColumn::make('applicant_type')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'action_required' => 'danger',
                        'for_evaluation' => 'info',
                        default => 'warning',
                    }),
                TextColumn::make('submitted_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending Review',
                        'action_required' => 'Action Required',
                        'for_evaluation' => 'For Evaluation',
                        'approved' => 'Approved',
                    ]),
                SelectFilter::make('applicant_type')
                    ->options([
                        'new' => 'First-Time College Applicant',
                        'transferee' => 'Transfer Applicant',
                        'returnee' => 'Returning Student / Readmission',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
