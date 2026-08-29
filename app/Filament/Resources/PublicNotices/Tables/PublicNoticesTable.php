<?php

namespace App\Filament\Resources\PublicNotices\Tables;

use App\Filament\Support\PublicContentActions;
use App\Models\PublicNotice;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PublicNoticesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->wrap()->description(fn (PublicNotice $record): string => 'Version '.$record->version),
                PublicContentActions::statusColumn(),
                TextColumn::make('display_order')->label('Position')->sortable(),
                TextColumn::make('visible_from')->label('From (Asia/Manila)')->dateTime()->placeholder('On publication'),
                TextColumn::make('visible_until')->label('Until (Asia/Manila)')->dateTime()->placeholder('Until unpublished'),
                TextColumn::make('publisher.name')->label('Published by')->placeholder('Not published')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw("CASE WHEN state = 'Published' AND (visible_from IS NULL OR visible_from <= ?) AND (visible_until IS NULL OR visible_until > ?) THEN 0 WHEN state = 'Published' AND visible_from > ? THEN 1 ELSE 2 END", [now(), now(), now()])->orderBy('display_order')->orderByDesc('version')->orderBy('id'))
            ->filters([SelectFilter::make('state')->options(['Draft' => 'Draft', 'Published' => 'Published (including scheduled / expired)', 'Unpublished' => 'Unpublished'])])
            ->recordActions(PublicContentActions::tableActions())
            ->emptyStateHeading('No notices in this view')
            ->emptyStateDescription('System Administration can add a draft notice. Clear filters to see other saved versions.');
    }
}
