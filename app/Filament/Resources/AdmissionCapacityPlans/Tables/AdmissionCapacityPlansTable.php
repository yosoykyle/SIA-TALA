<?php

namespace App\Filament\Resources\AdmissionCapacityPlans\Tables;

use App\Models\AdmissionCapacityPlan;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdmissionCapacityPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['term', 'program', 'approver']))
            ->columns([
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('scope_type')
                    ->label('Scope')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdmissionCapacityPlan::scopeTypeOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('education_level')
                    ->label('Level')
                    ->placeholder('All')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'All' : (AdmissionCapacityPlan::educationLevelOptions()[$state] ?? strtoupper($state))),
                TextColumn::make('program.code')
                    ->label('Program')
                    ->placeholder('All'),
                TextColumn::make('year_level')
                    ->label('Year/Grade')
                    ->placeholder('All'),
                TextColumn::make('delivery_setup')
                    ->label('Delivery')
                    ->placeholder('All')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'All' : str($state)->replace('_', ' ')->headline()->toString()),
                TextColumn::make('capacity_limit')
                    ->label('Limit')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reserved_count')
                    ->label('Reserved')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AdmissionCapacityPlan::StatusApproved => 'success',
                        AdmissionCapacityPlan::StatusRetired => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('effective_from')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('scope_type')
                    ->options(AdmissionCapacityPlan::scopeTypeOptions()),
                SelectFilter::make('education_level')
                    ->label('Level')
                    ->options(AdmissionCapacityPlan::educationLevelOptions()),
                SelectFilter::make('status')
                    ->options(AdmissionCapacityPlan::statusOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
