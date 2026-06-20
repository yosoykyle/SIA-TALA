<?php

namespace App\Filament\Resources\AdmissionOfferings\Tables;

use App\Models\AdmissionOffering;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdmissionOfferingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['term', 'program'])->withCount('requirementPolicies'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('education_level')
                    ->label('Level')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdmissionOffering::educationLevelOptions()[$state] ?? strtoupper($state)),
                TextColumn::make('entry_route')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdmissionOffering::entryRouteOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('prior_credential_pathway')
                    ->label('Pathway')
                    ->placeholder('Regular')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Regular' : (AdmissionOffering::priorCredentialOptions()[$state] ?? str($state)->headline()->toString())),
                TextColumn::make('program.code')
                    ->label('Program')
                    ->placeholder('All programs')
                    ->searchable(),
                TextColumn::make('year_level')
                    ->label('Year/Grade')
                    ->placeholder('All'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AdmissionOffering::StatusPublished => 'success',
                        AdmissionOffering::StatusRetired => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('requirement_policies_count')
                    ->label('Policies')
                    ->counts('requirementPolicies')
                    ->badge(),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('education_level')
                    ->label('Level')
                    ->options(AdmissionOffering::educationLevelOptions()),
                SelectFilter::make('entry_route')
                    ->options(AdmissionOffering::entryRouteOptions()),
                SelectFilter::make('status')
                    ->options(AdmissionOffering::statusOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
