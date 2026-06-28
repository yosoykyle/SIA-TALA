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
                TextColumn::make('term.label')->searchable()->sortable(),
                TextColumn::make('curriculumEntry.courseSpecification.course.code')
                    ->label('Subject Code')
                    ->searchable(),
                TextColumn::make('curriculumEntry.courseSpecification.title')
                    ->label('Subject Title')
                    ->searchable(),
                TextColumn::make('category')->badge(),
                TextColumn::make('delivery_variant')->label('Delivery')->badge(),
                TextColumn::make('modality')->badge(),
                TextColumn::make('expected_count')->label('Expected')->numeric(),
                TextColumn::make('sections_count')->counts('sections')->label('Sections'),
                TextColumn::make('state')->badge(),
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
            ]);
    }
}
