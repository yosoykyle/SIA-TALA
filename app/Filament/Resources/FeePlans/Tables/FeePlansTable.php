<?php

namespace App\Filament\Resources\FeePlans\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeePlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('program.code')->label('Program')->searchable()->sortable(),
                TextColumn::make('term.label')->label('Term')->searchable()->sortable(),
                TextColumn::make('version')->badge()->sortable(),
                TextColumn::make('state')->badge()->color(fn (string $state): string => match ($state) {
                    'Published' => 'success', 'Superseded' => 'gray', default => 'warning',
                }),
                TextColumn::make('published_at')->dateTime()->placeholder('Not published')->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->options(['Draft' => 'Draft', 'Published' => 'Published', 'Superseded' => 'Superseded']),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
