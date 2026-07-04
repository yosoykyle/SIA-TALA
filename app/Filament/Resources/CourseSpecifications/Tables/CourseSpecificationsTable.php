<?php

namespace App\Filament\Resources\CourseSpecifications\Tables;

use App\Models\CourseSpecification;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CourseSpecificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course.code')->label('Code')->searchable()->sortable()->weight('bold'),
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('revision_code')->label('Revision')->searchable()->sortable(),
                TextColumn::make('credit_units')->label('Units')->sortable(),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CourseSpecification::stateOptions()[$state] ?? str((string) $state)->headline()->toString())
                    ->sortable(),
                TextColumn::make('components_count')->counts('components')->label('Components'),
                TextColumn::make('requirements_count')->counts('requirements')->label('Requirements'),
                IconColumn::make('same_faculty_default')->label('Same Faculty')->boolean(),
            ])
            ->filters([
                SelectFilter::make('course_id')->label('Course')->relationship('course', 'code'),
                SelectFilter::make('state')->options(CourseSpecification::stateOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }
}
