<?php

namespace App\Filament\Resources\FaqEntries\Tables;

use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Support\PublicContentActions;
use App\Models\FaqEntry;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FaqEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => 'tala-inline-sortable-table'])
            ->columns([
                ViewColumn::make('drag_handle')
                    ->label('')
                    ->view('filament.admin.public-content.drag-handle')
                    ->width('2.25rem'),
                TextColumn::make('question')
                    ->searchable(['question', 'category'])
                    ->limit(44)
                    ->tooltip(fn (FaqEntry $record): string => $record->question)
                    ->description(function (FaqEntry $record): string {
                        $parts = [FaqEntry::categoryLabel($record->category)];
                        if ($record->updated_at) {
                            $parts[] = 'Updated '.$record->updated_at->timezone('Asia/Manila')->format('M j, Y');
                        }

                        return implode(' · ', $parts);
                    }),
                PublicContentActions::statusColumn(),
                PublicContentActions::scheduleColumn(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(FaqEntry::categoryOptions()),
                SelectFilter::make('state')
                    ->label('Publication state')
                    ->options([
                        'Draft' => 'Draft',
                        'Published' => 'Published (including scheduled / expired)',
                        'Unpublished' => 'Unpublished',
                    ]),
            ], layout: FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->size('sm'))
            ->deferFilters(false)
            ->columnManager(false)
            ->defaultSort('sort_order')
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add FAQ')
                    ->color('primary')
                    ->size('sm')
                    ->url(fn (): string => FaqEntryResource::getUrl('create')),
            ])
            ->recordActions(PublicContentActions::tableActions())
            ->emptyStateHeading('No FAQ entries yet')
            ->emptyStateDescription('Add a draft FAQ to preview the answer before publishing to the landing page, or clear your filters to see saved entries.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Add FAQ')
                    ->color('primary')
                    ->url(fn (): string => FaqEntryResource::getUrl('create')),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'));
    }
}
