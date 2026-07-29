<?php

namespace App\Filament\Resources\TermOfferings\Tables;

use App\Models\TermOffering;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TermOfferingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('curriculumEntry.courseSpecification.course.code')
                    ->label('Course')
                    ->description(fn (TermOffering $record): string => $record->curriculumEntry?->courseSpecification->title ?? 'Title not recorded')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('modality')
                    ->label('Teaching mode')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (TermOffering::modalityOptions()[$state] ?? str($state)->headline()->toString())),
                TextColumn::make('expected_count')
                    ->label('Planned class size')
                    ->numeric(),
                TextColumn::make('sections_count')
                    ->counts('sections')
                    ->label('Sections'),
                TextColumn::make('state')
                    ->label('Planning state')
                    ->badge(),
                TextColumn::make('category')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('delivery_variant')
                    ->label('Delivery rule')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('term')->relationship('term', 'label'),
                SelectFilter::make('category')->options([
                    TermOffering::CategoryRegular => 'Regular',
                    TermOffering::CategorySpecial => 'Special',
                ]),
                SelectFilter::make('state')->options([
                    TermOffering::StatePendingScheduling => 'Pending Scheduling',
                    TermOffering::StateScheduled => 'Scheduled',
                    TermOffering::StateCancelled => 'Cancelled',
                ]),
            ])
            ->stackedOnMobile()
            ->emptyStateHeading('No term offerings are recorded')
            ->emptyStateDescription('Open Class Planning and create the term offerings before sections or schedule requirements can be prepared.');
    }
}
