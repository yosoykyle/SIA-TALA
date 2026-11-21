<?php

namespace App\Filament\Resources\Courses\Tables;

use App\Filament\Resources\Courses\CourseResource;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Program;
use App\Models\Term;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['specifications.curriculumEntries.curriculumVersion.program']))
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
                TextColumn::make('programs')
                    ->label('Programs')
                    ->state(function (Course $record): array {
                        $assignedPrograms = $record->specifications
                            ->flatMap(fn (CourseSpecification $spec) => $spec->curriculumEntries)
                            ->map(fn ($entry) => $entry->curriculumVersion?->program)
                            ->filter(fn (mixed $program): bool => $program instanceof Program)
                            ->unique('id')
                            ->sortBy('code')
                            ->values();

                        if ($assignedPrograms->isEmpty()) {
                            return [];
                        }

                        $activeProgramIds = Program::query()
                            ->where('is_active', true)
                            ->pluck('id');

                        if ($activeProgramIds->count() >= 2
                            && $activeProgramIds->diff($assignedPrograms->pluck('id'))->isEmpty()) {
                            return ['All programs'];
                        }

                        return $assignedPrograms->pluck('code')->all();
                    })
                    ->badge()
                    ->color(fn (mixed $state): string => $state === 'All programs' ? 'success' : 'info')
                    ->placeholder('Not assigned')
                    ->wrap(),
                TextColumn::make('year_and_term')
                    ->label('Year & term')
                    ->state(fn (Course $record): ?string => self::yearAndTermSummary($record))
                    ->tooltip(fn (Course $record): ?string => self::yearAndTermTooltip($record))
                    ->placeholder('Not placed')
                    ->extraAttributes(['class' => 'whitespace-nowrap tala-year-term-col'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap tala-year-term-cell']),
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
                SelectFilter::make('program_id')
                    ->label('Program')
                    ->options(fn (): array => Program::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('specifications.curriculumEntries.curriculumVersion', fn (Builder $q): Builder => $q->where('program_id', (int) $data['value']))
                        : $query),
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
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->color('gray'))
            ->filtersResetAction(fn (Action $action, Table $table): Action => $action->visible(fn (): bool => $table->isFiltered()))
            ->deferFilters(false)
            ->extraAttributes(['class' => 'tala-data-table tala-courses-table'])
            ->stackedOnMobile()
            ->recordUrl(fn (Course $record, Table $table): string => CourseResource::getUrl('view', array_filter([
                'record' => $record,
                'oversightTermId' => self::oversightTermId($table),
            ], fn (mixed $value): bool => $value !== null)))
            ->recordActions([
                CourseResource::assignToCurriculaAction(),
                CourseResource::deleteSubjectAction(),
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

    private static function yearAndTermSummary(Course $record): ?string
    {
        $placements = self::formattedPlacements($record);

        if ($placements->isEmpty()) {
            return null;
        }

        if ($placements->count() === 1) {
            return $placements->first();
        }

        return 'Multiple';
    }

    private static function yearAndTermTooltip(Course $record): ?string
    {
        $placements = self::formattedPlacements($record);

        if ($placements->count() <= 1) {
            return null;
        }

        $detailed = $record->specifications
            ->flatMap(fn (CourseSpecification $spec) => $spec->curriculumEntries)
            ->map(function (CurriculumEntry $entry): ?array {
                $programCode = $entry->curriculumVersion?->program?->code;
                $formatted = self::formatEntryPlacement($entry);

                return $programCode !== null
                    ? [
                        'sort' => $programCode.'-'.$formatted['sort'],
                        'label' => "{$programCode}: {$formatted['label']}",
                    ]
                    : null;
            })
            ->filter()
            ->unique('label')
            ->sortBy('sort')
            ->pluck('label');

        return $detailed->isNotEmpty() ? $detailed->implode(', ') : $placements->implode(', ');
    }

    /**
     * @return Collection<int, string>
     */
    private static function formattedPlacements(Course $record): Collection
    {
        return $record->specifications
            ->flatMap(fn (CourseSpecification $spec) => $spec->curriculumEntries)
            ->map(fn (CurriculumEntry $entry): array => self::formatEntryPlacement($entry))
            ->unique('label')
            ->sortBy('sort')
            ->pluck('label')
            ->values();
    }

    /**
     * @return array{sort: string, label: string}
     */
    private static function formatEntryPlacement(CurriculumEntry $entry): array
    {
        $yearNumber = self::parseYearNumber($entry->year_level);
        $termOrder = match ($entry->term_type) {
            Term::TypeFirstSemester => 1,
            Term::TypeSecondSemester => 2,
            Term::TypeSpecialTerm => 3,
            default => 4,
        };
        $termShort = match ($entry->term_type) {
            Term::TypeFirstSemester => '1st Sem',
            Term::TypeSecondSemester => '2nd Sem',
            Term::TypeSpecialTerm => 'Summer',
            default => $entry->term_label !== '' ? $entry->term_label : $entry->term_type,
        };
        $label = $yearNumber > 0 ? "Year {$yearNumber} · {$termShort}" : $termShort;

        return [
            'sort' => sprintf('%03d-%d-%s', $yearNumber, $termOrder, $label),
            'label' => $label,
        ];
    }

    private static function parseYearNumber(mixed $yearLevel): int
    {
        if (is_int($yearLevel)) {
            return $yearLevel;
        }

        $normalized = strtolower(trim((string) $yearLevel));

        if (preg_match('/\d+/', $normalized, $matches) === 1) {
            return (int) $matches[0];
        }

        return match (true) {
            str_contains($normalized, 'first') => 1,
            str_contains($normalized, 'second') => 2,
            str_contains($normalized, 'third') => 3,
            str_contains($normalized, 'fourth') => 4,
            str_contains($normalized, 'fifth') => 5,
            default => 0,
        };
    }
}
