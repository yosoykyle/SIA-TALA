<?php

namespace App\Filament\Resources\CurriculumVersions\Tables;

use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Models\CurriculumVersion;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class CurriculumVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['program', 'effectiveEntryTerm.academicYear', 'entries.courseSpecification.components'])
                ->withCount('entries'))
            ->searchPlaceholder('Search curricula')
            ->columns([
                TextColumn::make('program.code')
                    ->label('Program')
                    ->description(fn (CurriculumVersion $record): string => (string) ($record->program?->name ?? ''))
                    ->searchable(['program.code', 'program.name'])
                    ->sortable()
                    ->wrap(),
                TextColumn::make('name')->label('Curriculum')->searchable(['name', 'version_code'])->weight('bold')->wrap()
                    ->description(fn (CurriculumVersion $record): string => $record->entries_count.' '.str('subject')->plural($record->entries_count)),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        CurriculumVersion::StateActive => 'success',
                        CurriculumVersion::StateDraft => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => CurriculumVersion::stateOptions()[$state] ?? str((string) $state)->headline()->toString())
                    ->sortable(),
                TextColumn::make('effectiveEntryTerm.label')
                    ->label('First student intake')
                    ->formatStateUsing(function (?string $state, CurriculumVersion $record): ?HtmlString {
                        $termLabel = trim((string) $state);
                        $ayLabel = trim((string) ($record->effectiveEntryTerm?->academicYear?->label ?? ''));

                        if ($termLabel === '' && $ayLabel === '') {
                            return null;
                        }

                        if ($termLabel !== '' && $ayLabel !== '') {
                            return new HtmlString(e($termLabel).', <span class="whitespace-nowrap">'.e($ayLabel).'</span>');
                        }

                        return new HtmlString(e($termLabel !== '' ? $termLabel : $ayLabel));
                    })
                    ->wrap()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('program_id')
                    ->label('Program')
                    ->relationship('program', 'code'),
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(CurriculumVersion::stateOptions()),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->color('gray')->labeledFrom(null))
            ->deferFilters(false)
            ->extraAttributes(['class' => 'tala-data-table'])
            ->stackedOnMobile()
            ->recordUrl(fn (CurriculumVersion $record, Table $table): string => CurriculumVersionResource::getUrl('review', array_filter([
                'record' => $record,
                'programId' => $record->program_id,
                'oversightTermId' => self::oversightTermId($table),
            ], fn (mixed $value): bool => $value !== null)))
            ->recordActions([
                Action::make('openSheet')->button()->color('gray')
                    ->label('Open')->icon('heroicon-m-arrow-right')->iconPosition('after')
                    ->url(fn (CurriculumVersion $record, Table $table): string => CurriculumVersionResource::getUrl('review', array_filter([
                        'record' => $record,
                        'programId' => $record->program_id,
                        'oversightTermId' => self::oversightTermId($table),
                    ], fn (mixed $value): bool => $value !== null))),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'No matching curricula'
                : 'No curricula yet')
            ->emptyStateDescription(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'Try a different program, curriculum name, or status filter.'
                : 'Create a program study plan across year levels and semesters.')
            ->emptyStateActions([
                CurriculumVersionResource::createDraftAction(name: 'emptyCreateCurriculum')
                    ->visible(fn (HasTable $livewire): bool => ! filled($livewire->getTableSearch())
                        && ! $livewire->getTable()->isFiltered()
                        && (auth()->user()?->can('create', CurriculumVersion::class) ?? false)),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
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
