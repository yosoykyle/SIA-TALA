<?php

namespace App\Filament\Resources\FeeTemplates\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeeTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('program'))
            ->columns([
                TextColumn::make('name')
                    ->label('Template')
                    ->searchable(),
                TextColumn::make('education_level')
                    ->label('Level')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => strtoupper((string) $state))
                    ->searchable(),
                TextColumn::make('program.name')
                    ->label('Program')
                    ->placeholder('All programs')
                    ->searchable(),
                TextColumn::make('year_level')
                    ->label('Year/Grade')
                    ->placeholder('All year levels')
                    ->searchable(),
                TextColumn::make('tuition_fee')
                    ->label('Tuition')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('laboratory_fee')
                    ->label('Lab')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('misc_fee')
                    ->label('Misc.')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('other_fee')
                    ->label('Other')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('minimum_downpayment_percentage')
                    ->label('Min Down')
                    ->suffix('%')
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
                SelectFilter::make('education_level')
                    ->label('Level')
                    ->options([
                        'shs' => 'SHS',
                        'college' => 'College',
                    ]),
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
