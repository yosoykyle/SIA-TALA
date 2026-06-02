<?php

namespace App\Filament\Resources\InstallmentPolicyMilestones\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InstallmentPolicyMilestonesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('installmentPolicy'))
            ->columns([
                TextColumn::make('installmentPolicy.name')
                    ->label('Policy')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sequence')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('month_offset')
                    ->label('Month Offset')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('required_percentage')
                    ->label('Required')
                    ->suffix('%')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
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
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
