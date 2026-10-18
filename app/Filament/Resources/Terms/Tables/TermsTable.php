<?php

namespace App\Filament\Resources\Terms\Tables;

use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\Terms\Pages\ListTerms;
use App\Filament\Resources\Terms\TermResource;
use App\Models\Term;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TermsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Term')->searchable()->sortable()->weight('bold')->wrap(),
                TextColumn::make('academic_year_label')
                    ->label('Academic year')
                    ->state(fn (Term $record): string => $record->academicYear?->displayLabel() ?? '-')
                    ->placeholder('Not recorded')->wrap(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Term::typeOptions()[$state] ?? str((string) $state)->headline()->toString())
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Term::stateOptions()[$state] ?? str((string) $state)->headline()->toString())
                    ->color(fn (?string $state): string => match ($state) {
                        Term::StateActive => 'success',
                        Term::StateClosed => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('starts_on')->label('Starts')->date('M j, Y')->sortable()->placeholder('Not recorded'),
                TextColumn::make('ends_on')->label('Ends')->date('M j, Y')->sortable()->placeholder('Not recorded'),
                TextColumn::make('default_max_units')->label('Faculty default')->suffix(' units')->placeholder('Not recorded'),
            ])
            ->filters([
                SelectFilter::make('type')->label('Type')->options(Term::typeOptions()),
                SelectFilter::make('state')->label('Status')->options(Term::stateOptions()),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filter')->color('gray')->labeledFrom(null))
            ->recordActions([
                Action::make('openPlanning')->label('Open planning')->button()->color('gray')
                    ->visible(fn (): bool => TermPlanningWorkbench::canAccess())
                    ->url(fn (Term $record): string => TermPlanningWorkbench::getUrl(['termId' => $record->id])),
                ViewAction::make()->label('View details')->button()->color('gray')
                    ->url(fn (Term $record, ListTerms $livewire): string => TermResource::getUrl('view', ['record' => $record, 'viewTab' => $livewire->viewTab, 'resourceView' => $livewire->resourceView])),
                EditAction::make()->label('Edit settings')->button()->color('gray')
                    ->url(fn (Term $record, ListTerms $livewire): string => TermResource::getUrl('edit', ['record' => $record, 'viewTab' => $livewire->viewTab, 'resourceView' => $livewire->resourceView])),
            ])
            ->recordActionsColumnLabel('Actions')
            ->toolbarActions([])
            ->stackedOnMobile()
            ->emptyStateHeading('No Terms found')
            ->emptyStateDescription('Create a Term to begin planning, or clear search and filters to see recorded Terms.')
            ->defaultSort('starts_on', 'desc');
    }
}
