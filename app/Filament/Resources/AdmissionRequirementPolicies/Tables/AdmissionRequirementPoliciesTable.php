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
            ->modifyQueryUsing(fn ($query) => $query->with(['admissionOffering.term', 'admissionOffering.program', 'approver'])->withCount('documentRequirementItems'))
            ->columns([
                TextColumn::make('admissionOffering.name')
                    ->label('Offering')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('admissionOffering.term.term_name')
                    ->label('Term')
                    ->searchable(),
                TextColumn::make('version')
                    ->badge()
                    ->sortable(),
                TextColumn::make('source_label')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AdmissionRequirementPolicy::StatusActive => 'success',
                        AdmissionRequirementPolicy::StatusRetired => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('document_requirement_items_count')
                    ->label('Items')
                    ->counts('documentRequirementItems')
                    ->badge(),
                TextColumn::make('effective_from')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('approved_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Not approved'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(AdmissionRequirementPolicy::statusOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
