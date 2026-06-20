<?php

namespace App\Filament\Resources\DocumentRequirementItems\Tables;

use App\Models\DocumentRequirementItem;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentRequirementItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('admissionRequirementPolicy.admissionOffering'))
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('admissionRequirementPolicy.admissionOffering.name')
                    ->label('Offering')
                    ->searchable(),
                TextColumn::make('admissionRequirementPolicy.version')
                    ->label('Policy v')
                    ->badge(),
                TextColumn::make('gate_type')
                    ->badge()
                    ->color(fn (string $state): string => $state === DocumentRequirementItem::GateTypeAdmission ? 'danger' : 'warning')
                    ->formatStateUsing(fn (string $state): string => DocumentRequirementItem::gateTypeOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('storage_class')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DocumentRequirementItem::storageClassOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('sensitivity_class')
                    ->badge()
                    ->color(fn (string $state): string => $state === DocumentRequirementItem::SensitivityRestricted ? 'danger' : 'gray'),
                TextColumn::make('sort_order')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('gate_type')
                    ->options(DocumentRequirementItem::gateTypeOptions()),
                SelectFilter::make('storage_class')
                    ->options(DocumentRequirementItem::storageClassOptions()),
                SelectFilter::make('sensitivity_class')
                    ->options(DocumentRequirementItem::sensitivityClassOptions()),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
