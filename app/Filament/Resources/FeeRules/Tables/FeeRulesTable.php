<?php

namespace App\Filament\Resources\FeeRules\Tables;

use App\Models\FeeRule;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeeRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['program', 'term']))
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('display_category')
                    ->badge()
                    ->searchable(),
                TextColumn::make('calculation_type')
                    ->badge(),
                TextColumn::make('program.code')
                    ->placeholder('All programs')
                    ->searchable(),
                TextColumn::make('term.label')
                    ->placeholder('All terms')
                    ->searchable(),
                TextColumn::make('amount')
                    ->money('PHP')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('rate')
                    ->label('Per-unit rate')
                    ->money('PHP')
                    ->placeholder('-'),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('effective_from')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('display_category')
                    ->options(FeeRule::displayCategoryOptions()),
                SelectFilter::make('calculation_type')
                    ->options(FeeRule::calculationTypeOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
