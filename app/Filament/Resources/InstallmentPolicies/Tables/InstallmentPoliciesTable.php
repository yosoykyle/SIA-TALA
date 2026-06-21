<?php

namespace App\Filament\Resources\InstallmentPolicies\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InstallmentPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('program')->withCount('milestones'))
            ->columns([
                TextColumn::make('name')
                    ->label('Policy')
                    ->searchable(),
                TextColumn::make('program.name')
                    ->label('Program')
                    ->placeholder('All programs')
                    ->searchable(),
                TextColumn::make('year_level')
                    ->label('Year Level')
                    ->placeholder('All year levels')
                    ->searchable(),
                TextColumn::make('max_months')
                    ->label('Months')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('due_day_rule')
                    ->label('Due Rule')
                    ->searchable(),
                TextColumn::make('grace_days')
                    ->label('Grace')
                    ->suffix(' days')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('penalty_rate')
                    ->label('Penalty')
                    ->suffix('%')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('penalty_frequency')
                    ->label('Frequency')
                    ->searchable(),
                IconColumn::make('allow_partial_payments')
                    ->label('Partial')
                    ->boolean(),
                IconColumn::make('promissory_is_non_clearing')
                    ->label('PN Non-Clearing')
                    ->boolean(),
                TextColumn::make('milestones_count')
                    ->label('Milestones')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Status')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
