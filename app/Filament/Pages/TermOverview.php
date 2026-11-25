<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Models\AcademicYear;
use App\Models\CurriculumVersionBatch;
use App\Models\GraduationReviewBatch;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

class TermOverview extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable {
        getTableColumnsSessionKey as protected nativeTableColumnsSessionKey;
    }

    #[Url]
    public string $overviewTab = 'terms';

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static ?string $navigationLabel = 'Term Planning';

    protected static ?string $title = 'Term Planning';

    protected static ?string $slug = 'overview';

    protected static ?int $navigationSort = 0;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = true;

    protected static string $planningTab = 'plan';

    protected string $view = 'filament.pages.term-overview';

    public function mount(): void
    {
        parent::mount();

        if (! in_array($this->overviewTab, ['terms', 'academic-years'], true)) {
            $this->overviewTab = 'terms';
        }
    }

    public function chooseOverviewTab(string $tab): void
    {
        if (! in_array($tab, ['terms', 'academic-years'], true)) {
            $tab = 'terms';
        }

        $this->overviewTab = $tab;
        $this->tableColumns = [];
        $this->cachedDefaultTableColumnState = null;
        $this->resetTableSearch();
        $this->resetTableColumnSearches();
        $this->resetTableFiltersForm();
        $this->resetTable();
    }

    public function viewAcademicYearTerms(int $academicYearId): void
    {
        $this->chooseOverviewTab('terms');
        $this->tableFilters['academic_year_id'] = ['value' => (string) $academicYearId];
        $this->resetPage();
    }

    public function getTableColumnsSessionKey(): string
    {
        return $this->nativeTableColumnsSessionKey().'_'.$this->overviewTab;
    }

    public function table(Table $table): Table
    {
        if ($this->overviewTab === 'academic-years') {
            return $this->academicYearsTable($table);
        }

        return $this->termsTable($table);
    }

    private function termsTable(Table $table): Table
    {
        return $table
            ->query(
                Term::query()
                    ->with([
                        'academicYear',
                        'calendarPackages',
                        'timetableVersions',
                        'scheduleGenerationRuns' => fn ($query) => $query->select(['id', 'term_id', 'status', 'candidate_state']),
                    ])
                    ->orderByDesc('starts_on')
                    ->orderByDesc('id')
            )
            ->columns([
                TextColumn::make('label')
                    ->label('Term')
                    ->state(fn (Term $record): string => filled($record->label) ? $record->label : $record->typeLabel())
                    ->description(fn (Term $record): ?string => $record->academicYear?->label)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $identity): Builder => $identity
                        ->where('label', 'like', '%'.$search.'%')
                        ->orWhere('type', 'like', '%'.$search.'%')
                        ->orWhereHas('academicYear', fn (Builder $year): Builder => $year->where('label', 'like', '%'.$search.'%'))))
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('starts_on')
                    ->label('Dates')
                    ->state(fn (Term $record): string => ($record->starts_on && $record->ends_on)
                        ? $record->starts_on->format('M j, Y').' – '.$record->ends_on->format('M j, Y')
                        : 'Not set')
                    ->sortable()
                    ->extraCellAttributes(['class' => 'tabular-nums whitespace-nowrap']),
                TextColumn::make('stage')
                    ->label('Stage')
                    ->state(fn (Term $record): string => $this->termStageBadge($record))
                    ->description(fn (Term $record): string => $this->termStageProgressDescription($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Published' => 'success',
                        'Planning' => 'warning',
                        default => 'gray',
                    })
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('academic_year_id')
                    ->label('Academic year')
                    ->options(fn (): array => AcademicYear::query()
                        ->orderByDesc('starts_on')
                        ->pluck('label', 'id')
                        ->all()),
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(Term::stateOptions()),
            ], layout: FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->size('sm'))
            ->filtersResetAction(fn (Action $action, Table $table): Action => $action->visible(fn (): bool => $table->isFiltered()))
            ->deferFilters(false)
            ->columnManager(false)
            ->extraAttributes(['class' => 'tala-data-table'])
            ->stackedOnMobile()
            ->recordUrl(fn (Term $record): string => TermCalendar::getUrl(['termId' => $record->id]))
            ->toolbarActions([
                $this->createTermAction()->size('sm'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->button()
                    ->size('sm')
                    ->color('gray')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->iconPosition(IconPosition::After)
                    ->url(fn (Term $record): string => TermCalendar::getUrl(['termId' => $record->id])),
            ])
            ->searchPlaceholder('Search term or academic year')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('academicYear'))
            ->defaultPaginationPageOption(10)
            ->paginated([10, 25, 50])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading('No terms recorded')
            ->emptyStateDescription(fn (): string => auth()->user()?->can('create', Term::class)
                ? 'Create your first term and its dates, then configure its calendar, classes, faculty, rooms, and timetable.'
                : 'Ask the Registrar to create a term before planning begins.')
            ->emptyStateActions([
                Action::make('emptyCreateTerm')
                    ->label('New term')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('create', Term::class))
                    ->action(fn () => $this->mountAction('createTerm')),
            ]);
    }

    private function academicYearsTable(Table $table): Table
    {
        return $table
            ->query(
                AcademicYear::query()
                    ->select('academic_years.*')
                    ->withCount('terms')
                    ->addSelect([
                        'curriculum_batches_count' => CurriculumVersionBatch::query()
                            ->selectRaw('count(*)')
                            ->whereColumn('academic_year_id', 'academic_years.id'),
                        'graduation_review_batches_count' => GraduationReviewBatch::query()
                            ->selectRaw('count(*)')
                            ->whereColumn('academic_year_id', 'academic_years.id'),
                    ])
                    ->orderByDesc('starts_on')
                    ->orderByDesc('id')
            )
            ->columns([
                TextColumn::make('label')
                    ->label('Academic year')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('starts_on')
                    ->label('Dates')
                    ->state(fn (AcademicYear $record): string => ($record->starts_on && $record->ends_on)
                        ? $record->starts_on->format('M j, Y').' – '.$record->ends_on->format('M j, Y')
                        : 'Not set')
                    ->sortable()
                    ->extraCellAttributes(['class' => 'tabular-nums whitespace-nowrap']),
                TextColumn::make('terms_count')
                    ->label('Terms')
                    ->state(function (AcademicYear $record): string {
                        $count = (int) ($record->terms_count ?? 0);

                        return $count.' '.Str::plural('term', $count);
                    })
                    ->description(function (AcademicYear $record): ?string {
                        $batches = (int) ($record->getAttribute('curriculum_batches_count') ?? 0);

                        return $batches > 0
                            ? $batches.' '.Str::plural('curriculum batch', $batches)
                            : null;
                    })
                    ->sortable()
                    ->extraCellAttributes(['class' => 'tabular-nums']),
                TextColumn::make('state')
                    ->label('Status')
                    ->state(fn (AcademicYear $record): string => AcademicYear::stateOptions()[$record->state] ?? $record->state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AcademicYear::stateOptions()[AcademicYear::StateActive] ?? 'Active' => 'success',
                        AcademicYear::stateOptions()[AcademicYear::StateDraft] ?? 'Draft' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(AcademicYear::stateOptions()),
            ], layout: FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->size('sm'))
            ->filtersResetAction(fn (Action $action, Table $table): Action => $action->visible(fn (): bool => $table->isFiltered()))
            ->deferFilters(false)
            ->columnManager(false)
            ->extraAttributes(['class' => 'tala-data-table'])
            ->stackedOnMobile()
            ->recordUrl(fn (AcademicYear $record): string => self::getUrl([
                'overviewTab' => 'terms',
                'tableFilters' => ['academic_year_id' => ['value' => (string) $record->id]],
            ]))
            ->toolbarActions([
                $this->createAcademicYearAction()->size('sm'),
            ])
            ->recordActions([
                Action::make('viewTerms')
                    ->label('View terms')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->button()
                    ->size('sm')
                    ->color('gray')
                    ->action(fn (AcademicYear $record) => $this->viewAcademicYearTerms((int) $record->id)),
                Action::make('editAcademicYear')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->button()
                    ->size('sm')
                    ->color('gray')
                    ->modalHeading('Edit academic year')
                    ->modalDescription('Update the academic year label, status, or dates. Dates must cover all existing terms under this academic year.')
                    ->modalSubmitActionLabel('Save changes')
                    ->modalCancelActionLabel('Cancel')
                    ->modalWidth(Width::Large)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->visible(fn (AcademicYear $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->fillForm(fn (AcademicYear $record): array => [
                        'label' => $record->label,
                        'starts_on' => $record->starts_on?->toDateString(),
                        'ends_on' => $record->ends_on?->toDateString(),
                        'state' => $record->state,
                    ])
                    ->schema(function (AcademicYear $record): array {
                        $termBounds = $this->academicYearTermDateBounds($record);
                        $minTermStart = $termBounds['min_start'];
                        $maxTermEnd = $termBounds['max_end'];
                        $dateHelper = $minTermStart !== null && $maxTermEnd !== null
                            ? sprintf(
                                'Existing terms span %s – %s. Academic year dates must cover all existing terms.',
                                CarbonImmutable::parse($minTermStart)->format('M j, Y'),
                                CarbonImmutable::parse($maxTermEnd)->format('M j, Y'),
                            )
                            : null;

                        return [
                            TextInput::make('label')
                                ->label('Academic year')
                                ->placeholder('e.g., AY 2026–2027')
                                ->required()
                                ->maxLength(255)
                                ->rule(Rule::unique((new AcademicYear)->getTable(), 'label')->ignore($record->id)),
                            Grid::make(['default' => 1, 'sm' => 2])->schema([
                                DatePicker::make('starts_on')
                                    ->label('Start date')
                                    ->native(false)
                                    ->displayFormat('M j, Y')
                                    ->suffixIcon(Heroicon::OutlinedCalendar)
                                    ->maxDate($minTermStart)
                                    ->helperText($dateHelper)
                                    ->required()
                                    ->rules([
                                        function () use ($minTermStart): Closure {
                                            return function (string $attribute, mixed $value, Closure $fail) use ($minTermStart): void {
                                                if ($minTermStart === null || blank($value)) {
                                                    return;
                                                }
                                                if (CarbonImmutable::parse((string) $value)->gt(CarbonImmutable::parse($minTermStart))) {
                                                    $fail('The start date cannot be after the earliest existing term ('.CarbonImmutable::parse($minTermStart)->format('M j, Y').').');
                                                }
                                            };
                                        },
                                    ]),
                                DatePicker::make('ends_on')
                                    ->label('End date')
                                    ->native(false)
                                    ->displayFormat('M j, Y')
                                    ->suffixIcon(Heroicon::OutlinedCalendar)
                                    ->minDate($maxTermEnd)
                                    ->required()
                                    ->after('starts_on')
                                    ->rules([
                                        function () use ($maxTermEnd): Closure {
                                            return function (string $attribute, mixed $value, Closure $fail) use ($maxTermEnd): void {
                                                if ($maxTermEnd === null || blank($value)) {
                                                    return;
                                                }
                                                if (CarbonImmutable::parse((string) $value)->lt(CarbonImmutable::parse($maxTermEnd))) {
                                                    $fail('The end date cannot be before the latest existing term ('.CarbonImmutable::parse($maxTermEnd)->format('M j, Y').').');
                                                }
                                            };
                                        },
                                    ]),
                            ]),
                            Select::make('state')
                                ->label('Status')
                                ->options(AcademicYear::stateOptions())
                                ->required(),
                        ];
                    })
                    ->action(function (AcademicYear $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        Gate::forUser($actor)->authorize('update', $record);

                        try {
                            DB::transaction(function () use ($record, $data): void {
                                $locked = AcademicYear::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                                $newStart = CarbonImmutable::parse((string) $data['starts_on'])->startOfDay();
                                $newEnd = CarbonImmutable::parse((string) $data['ends_on'])->startOfDay();

                                $outsideTerm = $locked->terms()
                                    ->where(function (Builder $query) use ($newStart, $newEnd): void {
                                        $query->whereDate('starts_on', '<', $newStart->toDateString())
                                            ->orWhereDate('ends_on', '>', $newEnd->toDateString());
                                    })
                                    ->first();

                                if ($outsideTerm instanceof Term) {
                                    throw ValidationException::withMessages([
                                        'starts_on' => sprintf(
                                            'Cannot change dates because %s (%s – %s) would fall outside this academic year.',
                                            $outsideTerm->typeLabel(),
                                            $outsideTerm->starts_on->format('M j, Y'),
                                            $outsideTerm->ends_on->format('M j, Y'),
                                        ),
                                    ]);
                                }

                                $newState = (string) $data['state'];
                                $isClosingOrDeactivating = in_array($newState, [AcademicYear::StateClosed, AcademicYear::StateArchived], true)
                                    || ($newState !== AcademicYear::StateActive && $newState !== $locked->state);

                                if ($isClosingOrDeactivating
                                    && $locked->terms()->whereIn('state', [Term::StateActive, Term::StateDraft])->exists()) {
                                    throw ValidationException::withMessages([
                                        'state' => 'Cannot close or deactivate this academic year while active or draft terms still exist under it. Close or remove those terms first.',
                                    ]);
                                }

                                $locked->update([
                                    'label' => trim((string) $data['label']),
                                    'starts_on' => $newStart->toDateString(),
                                    'ends_on' => $newEnd->toDateString(),
                                    'state' => $newState,
                                ]);
                            }, attempts: 3);
                        } catch (ValidationException $exception) {
                            $message = collect($exception->errors())->flatten()->first();
                            Notification::make()
                                ->title('Cannot update academic year')
                                ->body(is_string($message) ? $message : 'Review the academic year dates and try again.')
                                ->danger()
                                ->persistent()
                                ->send();

                            throw $exception;
                        }

                        Notification::make()
                            ->title('Academic year updated')
                            ->success()
                            ->send();
                    }),
                Action::make('deleteAcademicYear')
                    ->label('Delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->button()
                    ->size('sm')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (AcademicYear $record): string => 'Delete '.$record->label.'?')
                    ->modalDescription('This unused academic year has no terms or curriculum batches and will be permanently removed.')
                    ->modalSubmitActionLabel('Delete academic year')
                    ->modalSubmitAction(fn (Action $action): Action => $action->color('danger'))
                    ->visible(fn (AcademicYear $record): bool => (bool) auth()->user()?->can('update', $record)
                        && $this->canDeleteAcademicYear($record))
                    ->action(function (AcademicYear $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        Gate::forUser($actor)->authorize('update', $record);

                        try {
                            DB::transaction(function () use ($record, $actor): void {
                                $locked = AcademicYear::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

                                if ($locked->terms()->exists()
                                    || CurriculumVersionBatch::query()->where('academic_year_id', $locked->id)->exists()
                                    || GraduationReviewBatch::query()->where('academic_year_id', $locked->id)->exists()) {
                                    throw ValidationException::withMessages([
                                        'academic_year' => 'This academic year is referenced by existing terms, curriculum batches, or graduation review batches and cannot be deleted.',
                                    ]);
                                }

                                $label = $locked->label;
                                $id = $locked->id;
                                $locked->delete();

                                activity()
                                    ->causedBy($actor)
                                    ->event('academic_year_deleted')
                                    ->withProperties(['academic_year_id' => $id, 'label' => $label])
                                    ->log('Unused academic year deleted');
                            }, attempts: 3);
                        } catch (ValidationException $exception) {
                            $message = collect($exception->errors())->flatten()->first();
                            Notification::make()
                                ->title('Cannot delete academic year')
                                ->body(is_string($message) ? $message : 'This academic year is still in use.')
                                ->danger()
                                ->persistent()
                                ->send();

                            throw $exception;
                        } catch (QueryException) {
                            Notification::make()
                                ->title('Cannot delete academic year')
                                ->body('This academic year is still referenced by linked academic records.')
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Academic year deleted')
                            ->success()
                            ->send();
                    }),
            ])
            ->searchPlaceholder('Search academic year')
            ->defaultPaginationPageOption(10)
            ->paginated([10, 25, 50])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading('No academic years recorded')
            ->emptyStateDescription(fn (): string => auth()->user()?->can('create', AcademicYear::class)
                ? 'Add an academic year before creating terms or assigning incoming curriculum batches.'
                : 'Ask the Registrar to add an academic year before planning begins.')
            ->emptyStateActions([
                Action::make('emptyCreateAcademicYear')
                    ->label('New academic year')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('create', AcademicYear::class))
                    ->action(fn () => $this->mountAction('createAcademicYear')),
            ]);
    }

    public function createAcademicYearAction(): Action
    {
        return Action::make('createAcademicYear')
            ->label('New academic year')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->modalHeading('New academic year')
            ->modalDescription('Add an academic year to organize terms and assign incoming curriculum batches.')
            ->modalSubmitActionLabel('Create academic year')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => (bool) auth()->user()?->can('create', AcademicYear::class))
            ->schema([
                TextInput::make('label')
                    ->label('Academic year')
                    ->placeholder('e.g., AY 2026–2027')
                    ->required()
                    ->maxLength(255)
                    ->unique((new AcademicYear)->getTable(), 'label'),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    DatePicker::make('starts_on')
                        ->label('Start date')
                        ->native(false)
                        ->displayFormat('M j, Y')
                        ->suffixIcon(Heroicon::OutlinedCalendar)
                        ->required(),
                    DatePicker::make('ends_on')
                        ->label('End date')
                        ->native(false)
                        ->displayFormat('M j, Y')
                        ->suffixIcon(Heroicon::OutlinedCalendar)
                        ->required()
                        ->after('starts_on'),
                ]),
                Select::make('state')
                    ->label('Status')
                    ->options(AcademicYear::stateOptions())
                    ->default(AcademicYear::StateDraft)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                Gate::forUser($actor)->authorize('create', AcademicYear::class);

                $year = AcademicYear::query()->create([
                    'label' => trim((string) $data['label']),
                    'starts_on' => $data['starts_on'],
                    'ends_on' => $data['ends_on'],
                    'state' => (string) ($data['state'] ?? AcademicYear::StateDraft),
                ]);

                Notification::make()
                    ->title('Academic year created')
                    ->body("{$year->label} is now available for terms and curriculum batches.")
                    ->success()
                    ->send();
            });
    }

    private function termStageBadge(Term $record): string
    {
        if ($record->state === Term::StateClosed) {
            return 'Closed';
        }

        if ($record->timetableVersions->contains('state', PublishedTimetableVersion::StatePublished)) {
            return 'Published';
        }

        if ($record->state === Term::StateActive
            || $record->calendarPackages->contains('state', TermCalendarPackage::StateActive)) {
            return 'Planning';
        }

        return 'Draft';
    }

    private function termStageProgressDescription(Term $record): string
    {
        if ($record->state === Term::StateClosed) {
            return 'Archived and read-only';
        }

        if ($record->timetableVersions->contains('state', PublishedTimetableVersion::StatePublished)) {
            return 'Official timetable live';
        }

        $activeRun = $record->scheduleGenerationRuns
            ->sortByDesc('id')
            ->first(fn (ScheduleGenerationRun $run): bool => in_array($run->status, [
                ScheduleGenerationRun::StatusQueued,
                ScheduleGenerationRun::StatusDispatching,
                ScheduleGenerationRun::StatusUnderReview,
            ], true) && ! in_array($run->candidate_state, ['Rejected', 'Stale', 'Superseded'], true));

        if ($activeRun instanceof ScheduleGenerationRun) {
            if (in_array($activeRun->status, [ScheduleGenerationRun::StatusQueued, ScheduleGenerationRun::StatusDispatching], true)) {
                return 'Calendar active · Timetable generating';
            }

            if ($activeRun->candidate_state === 'Accepted') {
                return 'Timetable accepted · Ready to publish';
            }

            return 'Draft timetable ready for review';
        }

        if ($record->calendarPackages->contains('state', TermCalendarPackage::StateActive)) {
            return 'Calendar active · Preparing classes & schedule';
        }

        if ($record->calendarPackages->contains('state', TermCalendarPackage::StateDraft)) {
            return 'Draft calendar saved · Activate to begin planning';
        }

        return 'Calendar not set';
    }

    /** @return array{min_start: ?string, max_end: ?string} */
    private function academicYearTermDateBounds(AcademicYear $record): array
    {
        $minStart = $record->terms()->min('starts_on');
        $maxEnd = $record->terms()->max('ends_on');

        return [
            'min_start' => filled($minStart) ? CarbonImmutable::parse((string) $minStart)->toDateString() : null,
            'max_end' => filled($maxEnd) ? CarbonImmutable::parse((string) $maxEnd)->toDateString() : null,
        ];
    }

    private function canDeleteAcademicYear(AcademicYear $record): bool
    {
        $termsCount = $record->getAttribute('terms_count') !== null
            ? (int) $record->getAttribute('terms_count')
            : $record->terms()->count();

        $batchesCount = $record->getAttribute('curriculum_batches_count') !== null
            ? (int) $record->getAttribute('curriculum_batches_count')
            : CurriculumVersionBatch::query()->where('academic_year_id', $record->id)->count();

        $graduationBatchesCount = $record->getAttribute('graduation_review_batches_count') !== null
            ? (int) $record->getAttribute('graduation_review_batches_count')
            : GraduationReviewBatch::query()->where('academic_year_id', $record->id)->count();

        return $termsCount === 0 && $batchesCount === 0 && $graduationBatchesCount === 0;
    }
}
