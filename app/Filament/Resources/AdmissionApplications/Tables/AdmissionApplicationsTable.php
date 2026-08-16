<?php

namespace App\Filament\Resources\AdmissionApplications\Tables;

use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\Program;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdmissionApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('applicant')
                    ->state(fn (AdmissionApplication $record): string => collect([
                        $record->first_name,
                        $record->middle_name,
                        $record->last_name,
                        $record->extension_name,
                    ])->filter()->implode(' '))
                    ->description(fn (AdmissionApplication $record): string => $record->application_reference ?? 'Draft')
                    ->searchable(['application_reference', 'first_name', 'middle_name', 'last_name', 'email']),
                TextColumn::make('scope')
                    ->label('Program / cycle')
                    ->state(function (AdmissionApplication $record): string {
                        $program = $record->getRelation('program');

                        return $program instanceof Program ? $program->name : 'Program unavailable';
                    })
                    ->description(function (AdmissionApplication $record): string {
                        $cycle = $record->getRelation('admissionCycle');

                        return $cycle instanceof AdmissionCycle ? $cycle->code : 'Cycle unavailable';
                    })
                    ->wrap(),
                TextColumn::make('application_state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                    ->color(fn (string $state): string => match ($state) {
                        AdmissionApplication::StateAdmitted => 'success',
                        AdmissionApplication::StateNotAdmitted,
                        AdmissionApplication::StateWithdrawn => 'gray',
                        AdmissionApplication::StateActionNeeded => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('owner_next_action')
                    ->label('Owner / next action')
                    ->state(fn (AdmissionApplication $record): string => match ($record->application_state) {
                        AdmissionApplication::StateActionNeeded => 'Applicant — respond to correction',
                        AdmissionApplication::StateSubmitted => 'Registrar — review application',
                        AdmissionApplication::StateAdmitted => 'Registrar — verify official credentials',
                        default => 'Registrar — review retained history',
                    })
                    ->wrap(),
                TextColumn::make('application_path')
                    ->label('Path')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('admissionCycle.closes_at')
                    ->label('Cycle closes')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last activity')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('admission_cycle_id')
                    ->label('Admission cycle')
                    ->relationship('admissionCycle', 'label')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('program_id')
                    ->label('Program')
                    ->relationship('program', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('application_path')
                    ->label('Path')
                    ->options([
                        AdmissionApplication::PathFirstYear => 'First year',
                        AdmissionApplication::PathTransferee => 'Transferee',
                    ]),
                SelectFilter::make('application_state')
                    ->label('State')
                    ->options([
                        AdmissionApplication::StateSubmitted => 'Submitted',
                        AdmissionApplication::StateActionNeeded => 'Action needed',
                        AdmissionApplication::StateAdmitted => 'Admitted',
                        AdmissionApplication::StateNotAdmitted => 'Not admitted',
                        AdmissionApplication::StateWithdrawn => 'Withdrawn',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()->label('Open applicant record'),
            ]);
    }
}
