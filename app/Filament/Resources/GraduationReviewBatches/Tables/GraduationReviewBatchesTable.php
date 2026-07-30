<?php

namespace App\Filament\Resources\GraduationReviewBatches\Tables;

use App\Models\GraduationReviewBatch;
use Filament\Actions\ActionGroup;
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
                TextColumn::make('name')
                    ->label('Review')
                    ->description(fn (GraduationReviewBatch $record): string => "{$record->academicYear?->label} · {$record->term?->label}")
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('state')->badge()->sortable(),
                TextColumn::make('active_members_count')->label('Students')->numeric()->sortable(),
                TextColumn::make('awaiting_evaluation_count')->label('Awaiting')->numeric()->sortable(),
                TextColumn::make('blocked_members_count')->label('Blocked')->numeric()->sortable(),
                TextColumn::make('ready_members_count')->label('Ready')->numeric()->sortable(),
                TextColumn::make('complete_members_count')->label('Req. complete')->numeric()->sortable(),
                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->placeholder('System')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('closed_at')
                    ->dateTime()
                    ->placeholder('Open')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                ActionGroup::make([
                    ViewAction::make()
                        ->label('View summary'),
                    EditAction::make()
                        ->label('Review students')
                        ->visible(fn (GraduationReviewBatch $record): bool => $record->state === GraduationReviewBatch::StateOpen),
                ])->tooltip('Completion review batch actions'),
            ])
            ->stackedOnMobile();
    }
}
