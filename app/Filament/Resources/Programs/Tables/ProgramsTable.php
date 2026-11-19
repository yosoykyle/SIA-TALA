<?php

namespace App\Filament\Resources\Programs\Tables;

use App\Filament\Resources\Programs\ProgramResource;
use App\Models\CurriculumVersion;
use App\Models\Program;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProgramsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('curriculumVersions'))
            ->searchPlaceholder('Program code or name')
            ->columns([
                TextColumn::make('name')->label('Program')->weight('semibold')->wrap()->searchable(['name', 'code'])
                    ->description(fn (Program $record): string => $record->code.($record->duration_years ? ' · '.$record->duration_years.' years' : '').(! $record->is_active ? ' · Inactive' : '')),
                TextColumn::make('active_curriculum')->label('Active curriculum')
                    ->state(fn (Program $record): string => $record->curriculumVersions->firstWhere('state', CurriculumVersion::StateActive)?->name ?? 'None yet')
                    ->description(fn (Program $record): ?string => ($draft = $record->curriculumVersions->firstWhere('state', CurriculumVersion::StateDraft)) ? 'Draft in progress: '.$draft->name : null)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Program status')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->color('gray')->labeledFrom(null))
            ->deferFilters(false)
            ->extraAttributes(['class' => 'tala-data-table'])
            ->striped()
            ->stackedOnMobile()
            ->recordUrl(fn (Program $record, Table $table): string => ProgramResource::getUrl('view', array_filter([
                'record' => $record,
                'oversightTermId' => self::oversightTermId($table),
            ], fn (mixed $value): bool => $value !== null)))
            ->recordActions([
                Action::make('openProgram')
                    ->button()
                    ->color('gray')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-right')
                    ->iconPosition('after')
                    ->url(fn (Program $record, Table $table): string => ProgramResource::getUrl('view', array_filter([
                        'record' => $record,
                        'oversightTermId' => self::oversightTermId($table),
                    ], fn (mixed $value): bool => $value !== null))),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'No matching programs'
                : 'No programs yet')
            ->emptyStateDescription(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'Try a different code, name, or status filter.'
                : 'Add a degree or diploma program to begin building curricula.')
            ->emptyStateActions([
                ProgramResource::createProgramAction('emptyCreateProgram')
                    ->visible(fn (HasTable $livewire): bool => ! filled($livewire->getTableSearch())
                        && ! $livewire->getTable()->isFiltered()
                        && (auth()->user()?->can('create', Program::class) ?? false)),
            ])
            ->toolbarActions([])
            ->defaultSort('code');
    }

    private static function oversightTermId(Table $table): ?int
    {
        $livewire = $table->getLivewire();

        if (property_exists($livewire, 'oversightTermId') && is_int($livewire->oversightTermId) && $livewire->oversightTermId > 0) {
            return $livewire->oversightTermId;
        }

        return request()->integer('oversightTermId') ?: null;
    }
}
