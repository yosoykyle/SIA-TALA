<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Tables;

use App\Filament\Resources\FacultyTermLoadOverrides\Pages\ListFacultyTermLoadOverrides;
use App\Models\FacultyTermLoadOverride;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FacultyTermLoadOverridesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('faculty.name')
                    ->label('Teacher')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable()
                    ->visible(fn (ListFacultyTermLoadOverrides $livewire): bool => $livewire->termId === null),
                TextColumn::make('default_max_units_snapshot')
                    ->label('Base limit (units)')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('approved_overload_units')
                    ->label('Approved additional units')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('authority')
                    ->label('Approval reference')
                    ->searchable(),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
                    ->icon(fn (?bool $state): string => $state ? 'heroicon-o-check-circle' : 'heroicon-o-minus-circle')
                    ->color(fn (?bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('faculty_user_id')
                    ->label('Teacher')
                    ->relationship('faculty', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->whereHas('facultyTermLoadOverrides'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('term_id')
                    ->label('Term')
                    ->relationship('term', 'label')
                    ->searchable()
                    ->preload()
                    ->visible(fn (ListFacultyTermLoadOverrides $livewire): bool => $livewire->termId === null),
                SelectFilter::make('is_active')
                    ->label('Active')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()->label('View details')->button()->color('gray')
                    ->url(fn (FacultyTermLoadOverride $record, ListFacultyTermLoadOverrides $livewire): string => $livewire->getResourceUrl('view', ['record' => $record])),
                EditAction::make()->button()->color('gray')
                    ->url(fn (FacultyTermLoadOverride $record, ListFacultyTermLoadOverrides $livewire): string => $livewire->getResourceUrl('edit', ['record' => $record])),
            ])
            ->recordActionsColumnLabel('Actions')
            ->toolbarActions([])
            ->searchPlaceholder('Search teachers')
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filter')->color('gray')->labeledFrom(null))
            ->filtersApplyAction(fn (Action $action): Action => $action->alpineClickHandler('$wire.applyTableFilters(); close()'))
            ->emptyStateHeading('No load exceptions recorded')
            ->emptyStateDescription('Teachers use the Term default unless an approved exception is recorded.')
            ->emptyStateIcon('heroicon-o-academic-cap')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Add load exception')
                    ->button()
                    ->color('gray')
                    ->icon('heroicon-o-plus')
                    ->url(fn (ListFacultyTermLoadOverrides $livewire): string => $livewire->getResourceUrl('create')),
            ])
            ->stackedOnMobile()
            ->defaultSort('updated_at', 'desc');
    }
}
