<?php

namespace App\Filament\Resources\CurriculumVersions\Tables;

use App\Models\CurriculumVersion;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CurriculumVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('program.code')->label('Program')->searchable()->sortable(),
                TextColumn::make('version_code')->label('Code')->searchable()->sortable()->weight('bold'),
                TextColumn::make('name')->label('Version')->searchable()->sortable(),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CurriculumVersion::stateOptions()[$state] ?? str((string) $state)->headline()->toString())
                    ->sortable(),
                TextColumn::make('entries_count')->counts('entries')->label('Entries'),
                TextColumn::make('effectiveEntryTerm.label')->label('Effective Term')->placeholder('-'),
                TextColumn::make('approved_at')->dateTime()->placeholder('-')->sortable(),
            ])
            ->filters([
                SelectFilter::make('program_id')->label('Program')->relationship('program', 'name'),
                SelectFilter::make('state')->options(CurriculumVersion::stateOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }
}
