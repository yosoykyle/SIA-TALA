<?php

namespace App\Filament\Resources\GraduationReviewBatches\Tables;

use App\Models\GraduationReviewBatch;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GraduationReviewBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('academicYear.label')->label('Academic Year')->sortable(),
                TextColumn::make('term.label')->label('Term')->sortable(),
                TextColumn::make('state')->badge()->sortable(),
                TextColumn::make('members_count')->counts('members')->label('Members')->sortable(),
                TextColumn::make('creator.name')->label('Created By')->placeholder('System'),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('closed_at')->dateTime()->placeholder('Open')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('academic_year_id')->relationship('academicYear', 'label')->label('Academic Year')->searchable()->preload(),
                SelectFilter::make('term_id')->relationship('term', 'label')->label('Term')->searchable()->preload(),
                SelectFilter::make('state')->options([
                    GraduationReviewBatch::StateOpen => 'Open',
                    GraduationReviewBatch::StateClosed => 'Closed',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
