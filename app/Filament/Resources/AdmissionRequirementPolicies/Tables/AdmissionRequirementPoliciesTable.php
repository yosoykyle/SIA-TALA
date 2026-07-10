<?php

namespace App\Filament\Resources\AdmissionRequirementPolicies\Tables;

use App\Models\AdmissionRequirementPolicy;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdmissionRequirementPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('admission_category')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('credential_basis')
                    ->badge()
                    ->searchable(),
                TextColumn::make('requirement_type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('evidence_method')
                    ->badge(),
                TextColumn::make('blocking_level')
                    ->badge(),
                TextColumn::make('state')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AdmissionRequirementPolicy::StateDraft => 'warning',
                        AdmissionRequirementPolicy::StateActive => 'success',
                        AdmissionRequirementPolicy::StateSuperseded => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('effective_from')
                    ->date()
                    ->sortable(),
                TextColumn::make('effective_until')
                    ->date()
                    ->placeholder('-'),
            ])
            ->defaultSort('effective_from', 'desc')
            ->filters([
                SelectFilter::make('admission_category')
                    ->options(AdmissionRequirementPolicy::admissionCategoryOptions()),
                SelectFilter::make('credential_basis')
                    ->options(AdmissionRequirementPolicy::credentialBasisOptions()),
                SelectFilter::make('state')
                    ->options(AdmissionRequirementPolicy::statusOptions()),
                SelectFilter::make('blocking_level')
                    ->options(AdmissionRequirementPolicy::blockingLevelOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
