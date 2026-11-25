<?php

namespace App\Filament\Resources\PublicNotices\Tables;

use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use App\Filament\Support\PublicContentActions;
use App\Models\PublicNotice;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PublicNoticesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('publisher'))
            ->extraAttributes(['class' => 'tala-inline-sortable-table'])
            ->columns([
                ViewColumn::make('drag_handle')
                    ->label('')
                    ->view('filament.admin.public-content.drag-handle')
                    ->width('2.25rem'),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(44)
                    ->tooltip(fn (PublicNotice $record): string => $record->title)
                    ->description(function (PublicNotice $record): ?string {
                        $parts = [];
                        if ($record->updated_at) {
                            $parts[] = 'Updated '.$record->updated_at->timezone('Asia/Manila')->format('M j, Y');
                        }
                        if ($record->publisher?->name) {
                            $parts[] = 'By '.$record->publisher->name;
                        }

                        return $parts === [] ? null : implode(' · ', $parts);
                    }),
                PublicContentActions::statusColumn(),
                PublicContentActions::scheduleColumn(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw("CASE WHEN state = 'Published' AND (visible_from IS NULL OR visible_from <= ?) AND (visible_until IS NULL OR visible_until > ?) THEN 0 WHEN state = 'Published' AND visible_from > ? THEN 1 ELSE 2 END", [now(), now(), now()])->orderBy('display_order')->orderByDesc('version')->orderBy('id'))
            ->filters([
                SelectFilter::make('state')->options([
                    'Draft' => 'Draft',
                    'Published' => 'Published (including scheduled / expired)',
                    'Unpublished' => 'Unpublished',
                ]),
            ], layout: FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->size('sm'))
            ->deferFilters(false)
            ->columnManager(false)
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add announcement')
                    ->color('primary')
                    ->size('sm')
                    ->url(fn (): string => PublicNoticeResource::getUrl('create')),
            ])
            ->recordActions(PublicContentActions::tableActions())
            ->emptyStateHeading('No announcements yet')
            ->emptyStateDescription('Add a draft announcement to preview it before publishing to the public landing page, or clear your filters to see saved announcements.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Add announcement')
                    ->color('primary')
                    ->url(fn (): string => PublicNoticeResource::getUrl('create')),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'));
    }
}
