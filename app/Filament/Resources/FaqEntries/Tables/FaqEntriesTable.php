<?php

namespace App\Filament\Resources\FaqEntries\Tables;

use App\Filament\Support\PublicContentActions;
use App\Models\FaqEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FaqEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('question')
                    ->searchable()->wrap()->description(fn (FaqEntry $record): string => 'Version '.$record->version),
                TextColumn::make('category')
                    ->formatStateUsing(fn (?string $state): string => FaqEntry::categoryLabel($state))
                    ->searchable(),
                PublicContentActions::statusColumn(),
                TextColumn::make('sort_order')->label('Position')->sortable(),
                TextColumn::make('visible_from')->label('From (Asia/Manila)')->dateTime()->placeholder('On publication'),
                TextColumn::make('visible_until')->label('Until (Asia/Manila)')->dateTime()->placeholder('Until unpublished'),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updater.name')
                    ->label('Updated by')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                SelectFilter::make('category')
                    ->options(FaqEntry::categoryOptions()),
            ])
            ->defaultSort('sort_order')
            ->recordActions(PublicContentActions::tableActions())
            ->toolbarActions([]);
    }
}
