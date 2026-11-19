<?php

namespace App\Filament\Resources\Courses\Tables;

use App\Filament\Resources\Courses\CourseResource;
use App\Models\Course;
use App\Models\CourseSpecification;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('specifications'))
            ->searchPlaceholder('Subject code or name')
            ->columns([
                TextColumn::make('code')
                    ->label('Subject')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')->wrap()
                    ->description(fn (Course $record): string => self::primarySpecification($record)?->title ?? 'No subject details recorded'),
                TextColumn::make('active_revision')
                    ->label('Units')
                    ->state(fn (Course $record): string => ($spec = self::primarySpecification($record))
                        ? number_format((float) $spec->credit_units, 2).' units' : 'Not recorded')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'specifications',
                        fn (Builder $q): Builder => $q->where('title', 'like', "%{$search}%")
                            ->orWhere('revision_code', 'like', "%{$search}%"),
                    ))
                    ->wrap(),
                TextColumn::make('revision_status')
                    ->label('Status')
                    ->state(function (Course $record): string {
                        $draft = $record->specifications
                            ->where('state', CourseSpecification::StateDraft)
                            ->sortByDesc('id')
                            ->first();

                        if ($draft instanceof CourseSpecification) {
                            return 'Draft available';
                        }

                        $active = $record->specifications
                            ->where('state', CourseSpecification::StateActive)
                            ->sortByDesc('id')
                            ->first();

                        if ($active instanceof CourseSpecification) {
                            return 'Active';
                        }

                        $latest = $record->specifications->sortByDesc('id')->first();

                        return $latest instanceof CourseSpecification
                            ? (CourseSpecification::stateOptions()[$latest->state] ?? $latest->state)
                            : 'No revision';
                    })
                    ->badge()
                    ->color(function (Course $record): string {
                        if ($record->specifications->where('state', CourseSpecification::StateDraft)->isNotEmpty()) {
                            return 'warning';
                        }

                        if ($record->specifications->where('state', CourseSpecification::StateActive)->isNotEmpty()) {
                            return 'success';
                        }

                        return 'gray';
                    }),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(Course::stateOptions()),
                SelectFilter::make('academic_classification')
                    ->label('Classification')
                    ->options(CourseSpecification::academicClassificationOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('specifications', fn (Builder $q): Builder => $q->where('academic_classification', $data['value']))
                        : $query),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->color('gray')->labeledFrom(null))
            ->filtersResetAction(fn (Action $action, Table $table): Action => $action->visible(fn (): bool => $table->isFiltered()))
            ->deferFilters(false)
            ->extraAttributes(['class' => 'tala-data-table'])
            ->stackedOnMobile()
            ->recordUrl(fn (Course $record, Table $table): string => CourseResource::getUrl('view', array_filter([
                'record' => $record,
                'oversightTermId' => self::oversightTermId($table),
            ], fn (mixed $value): bool => $value !== null)))
            ->recordActions([
                ViewAction::make()->button()->color('gray')
                    ->label('Open')->icon('heroicon-m-arrow-right')->iconPosition('after')
                    ->url(fn (Course $record, Table $table): string => CourseResource::getUrl('view', array_filter([
                        'record' => $record,
                        'oversightTermId' => self::oversightTermId($table),
                    ], fn (mixed $value): bool => $value !== null))),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'No matching subjects'
                : 'No subjects yet')
            ->emptyStateDescription(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'Try a different subject code, title, or filter.'
                : 'Create a reusable subject to use across program curricula.')
            ->emptyStateActions([
                Action::make('emptyCreateSubject')
                    ->label('New subject')
                    ->icon(Heroicon::Plus)
                    ->color('primary')
                    ->visible(fn (HasTable $livewire): bool => ! filled($livewire->getTableSearch())
                        && ! $livewire->getTable()->isFiltered()
                        && (auth()->user()?->can('create', Course::class) ?? false))
                    ->action(fn (HasTable $livewire) => $livewire->mountAction('create')),
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

    private static function primarySpecification(Course $record): ?CourseSpecification
    {
        return $record->specifications
            ->where('state', CourseSpecification::StateActive)
            ->sortByDesc('id')
            ->first()
            ?? $record->specifications->sortByDesc('id')->first();
    }
}
