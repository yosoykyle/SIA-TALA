<?php

namespace App\Filament\Resources\CalendarEvents\Tables;

use App\Models\CalendarEvent;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CalendarEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('term.label')
                    ->label('Term')->visible(fn ($livewire): bool => $livewire->termId === null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('scope_type')
                    ->label('Applies to')
                    ->formatStateUsing(fn (string $state, CalendarEvent $record): string => match ($state) {
                        CalendarEvent::ScopeRoom => 'Room '.($record->room?->code ?? 'not recorded'),
                        CalendarEvent::ScopeFaculty => 'Faculty '.($record->faculty?->name ?? 'not recorded'),
                        default => CalendarEvent::recurringBlockScopeOptions()[$state] ?? str($state)->headline()->toString(),
                    })
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->where('scope_type', 'like', "%{$search}%")
                        ->orWhereHas('faculty', fn (Builder $faculty): Builder => $faculty->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('room', fn (Builder $rooms): Builder => $rooms->where('code', 'like', "%{$search}%"))))
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('event_type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => CalendarEvent::recurringBlockTypeOptions()[$state] ?? str($state)->headline()->toString())
                    ->wrap(),
                TextColumn::make('day_of_week')
                    ->label('Day')
                    ->formatStateUsing(fn (int $state): string => CalendarEvent::dayOptions()[$state] ?? '-'),
                TextColumn::make('starts_at')
                    ->label('From')->time('g:i A'),
                TextColumn::make('ends_at')
                    ->label('To')->time('g:i A'),
                TextColumn::make('state')
                    ->label('Status')
                    ->icon(fn (string $state): string => $state === CalendarEvent::StateActive ? 'heroicon-o-check-circle' : 'heroicon-o-minus-circle')
                    ->color(fn (string $state): string => $state === CalendarEvent::StateActive ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state): string => CalendarEvent::stateOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('authority')
                    ->label('Recorded by')->placeholder('Not recorded')->wrap(),
            ])
            ->filters([
                SelectFilter::make('term_id')
                    ->label('Term')->visible(fn ($livewire): bool => $livewire->termId === null)
                    ->relationship('term', 'label'),
                SelectFilter::make('event_type')
                    ->label('Type')
                    ->options(CalendarEvent::recurringBlockTypeOptions()),
                SelectFilter::make('scope_type')
                    ->label('Applies to')
                    ->options(CalendarEvent::recurringBlockScopeOptions()),
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(CalendarEvent::stateOptions()),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filter')->color('gray')->labeledFrom(null))
            ->recordActions([
                EditAction::make()->button()->color('gray'),
            ])
            ->recordActionsColumnLabel('Actions')
            ->toolbarActions([])
            ->stackedOnMobile()
            ->emptyStateHeading(fn (Table $table): string => $table->getFilterIndicators() === [] ? 'No weekly unavailable times recorded' : 'No weekly unavailable times found')
            ->emptyStateDescription(fn (Table $table): string => $table->getFilterIndicators() === [] ? 'Weekly unavailable times prevent recurring meetings. Record dated no-class exceptions in Calendar.' : 'Clear search and filters to see recorded times. Record dated no-class exceptions in Calendar.')
            ->defaultSort('day_of_week');
    }
}
