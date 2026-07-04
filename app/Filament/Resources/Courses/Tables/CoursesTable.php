<?php

namespace App\Filament\Resources\Courses\Tables;

use App\Models\Course;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Subject Code')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Course::stateOptions()[$state] ?? str((string) $state)->headline()->toString())
                    ->sortable(),
                TextColumn::make('specifications_count')
                    ->label('Revisions')
                    ->counts('specifications')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')->options(Course::stateOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('code');
    }
}
