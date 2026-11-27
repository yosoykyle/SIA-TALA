<?php

namespace App\Filament\Resources\Courses\Tables;

use App\Actions\AcademicSetup\CurriculumSpreadsheetExportService;
use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Filament\Resources\Courses\CourseResource;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CoursesTable
{
    public static function configure(Table $table): Table
    {
        /** @var Collection<int, int>|null $activeProgramIds */
        $activeProgramIds = null;

        return $table
            ->modifyQueryUsing(function (Builder $query) use (&$activeProgramIds): Builder {
                $activeProgramIds = null;

                return $query->with(['specifications.curriculumEntries.curriculumVersion.program']);
            })
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
                    ->state(function (Course $record) use (&$activeProgramIds): array {
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

                        $activeProgramIds ??= Program::query()
                            ->where('is_active', true)
                            ->pluck('id');

                        if ($activeProgramIds->count() >= 2
                            && $activeProgramIds->diff($assignedPrograms->pluck('id'))->isEmpty()) {
                            return ['All programs'];
                        }

                        return $assignedPrograms->pluck('code')->all();
                    })
                    ->badge()
                    ->color(fn (mixed $state): string => $state === 'All programs' ? 'success' : 'gray')
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
                        if ($record->state === Course::StateRetired) {
                            return 'Retired';
                        }

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
                        if ($record->state === Course::StateRetired) {
                            return 'gray';
                        }

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
                    ->options([
                        Course::StateActive => 'Active',
                        CourseSpecification::StateDraft => 'Draft available',
                        Course::StateRetired => 'Retired',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! filled($value)) {
                            return $query;
                        }

                        return match ($value) {
                            Course::StateRetired => $query->where(function (Builder $retiredQuery): void {
                                $retiredQuery
                                    ->where('courses.state', Course::StateRetired)
                                    ->orWhere(function (Builder $specRetiredQuery): void {
                                        $specRetiredQuery
                                            ->whereDoesntHave('specifications', fn (Builder $q): Builder => $q->whereIn('state', [
                                                CourseSpecification::StateDraft,
                                                CourseSpecification::StateActive,
                                            ]))
                                            ->whereHas('specifications', fn (Builder $q): Builder => $q->where('state', CourseSpecification::StateRetired));
                                    });
                            }),
                            CourseSpecification::StateDraft => $query
                                ->where('courses.state', '!=', Course::StateRetired)
                                ->whereHas('specifications', fn (Builder $q): Builder => $q->where('state', CourseSpecification::StateDraft)),
                            Course::StateActive => $query
                                ->where('courses.state', Course::StateActive)
                                ->whereDoesntHave('specifications', fn (Builder $q): Builder => $q->where('state', CourseSpecification::StateDraft))
                                ->whereHas('specifications', fn (Builder $q): Builder => $q->where('state', CourseSpecification::StateActive)),
                            default => $query,
                        };
                    }),
                SelectFilter::make('academic_classification')
                    ->label('Classification')
                    ->options(CourseSpecification::shortClassificationOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('specifications', fn (Builder $q): Builder => $q->where('academic_classification', $data['value']))
                        : $query),
            ], layout: FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->size('sm'))
            ->filtersResetAction(fn (Action $action, Table $table): Action => $action->visible(fn (): bool => $table->isFiltered()))
            ->deferFilters(false)
            ->columnManager(false)
            ->extraAttributes(['class' => 'tala-data-table tala-courses-table'])
            ->stackedOnMobile()
            ->recordUrl(fn (Course $record, Table $table): string => CourseResource::getUrl('view', array_filter([
                'record' => $record,
                'oversightTermId' => self::oversightTermId($table),
            ], fn (mixed $value): bool => $value !== null)))
            ->recordActions([
                CourseResource::deleteSubjectAction()->size('sm'),
                ViewAction::make()->button()->size('sm')->color('gray')
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
                CourseResource::createSubjectAction()
                    ->name('emptyCreateSubject')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->visible(fn (HasTable $livewire): bool => ! filled($livewire->getTableSearch())
                        && ! $livewire->getTable()->isFiltered()
                        && (auth()->user()?->can('create', Course::class) ?? false)),
            ])
            ->toolbarActions([
                CourseResource::createSubjectAction()
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->size('sm')
                    ->visible(fn (): bool => auth()->user()?->can('create', Course::class) ?? false),
                BulkActionGroup::make([
                    self::assignSelectedToCurriculaAction(),
                    self::removeSelectedFromCurriculaAction(),
                ])
                    ->label('Draft curricula')
                    ->icon(Heroicon::OutlinedChevronDown)
                    ->iconPosition(IconPosition::After)
                    ->button()
                    ->size('sm')
                    ->color('primary')
                    ->extraAttributes([
                        'x-cloak' => true,
                        'x-show' => 'getSelectedRecordsCount() > 0',
                    ])
                    ->visible(fn (): bool => self::canManageCurricula()),
                BulkActionGroup::make([
                    self::exportSelectedXlsxAction(),
                    self::exportSelectedCsvAction(),
                ])
                    ->label('Export selected')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->button()
                    ->size('sm')
                    ->color('gray')
                    ->extraAttributes([
                        'x-cloak' => true,
                        'x-show' => 'getSelectedRecordsCount() > 0',
                    ])
                    ->visible(fn (): bool => self::canManageCurricula()),
                self::deleteSelectedSubjectsAction(),
            ])
            ->defaultSort('code');
    }

    private static function deleteSelectedSubjectsAction(): BulkAction
    {
        return BulkAction::make('deleteSelectedSubjects')
            ->label('Delete unused drafts')
            ->icon(Heroicon::OutlinedTrash)
            ->button()
            ->size('sm')
            ->color('danger')
            ->outlined()
            ->requiresConfirmation()
            ->authorize(fn (): bool => auth()->user()?->can('create', Course::class) ?? false)
            ->visible(fn (): bool => auth()->user()?->can('create', Course::class) ?? false)
            ->extraAttributes([
                'x-cloak' => true,
                'x-show' => 'getSelectedRecordsCount() > 0',
            ])
            ->modalHeading(function (Collection $records): string {
                $deletableCount = $records
                    ->filter(fn (mixed $record): bool => $record instanceof Course && CourseResource::canDeleteUnusedSubject($record))
                    ->count();

                if ($deletableCount === 0) {
                    return 'No selected subjects can be deleted';
                }

                return "Delete {$deletableCount} unused draft ".Str::plural('subject', $deletableCount).'?';
            })
            ->modalDescription(function (Collection $records): string {
                $courses = $records->filter(fn (mixed $record): bool => $record instanceof Course)->values();
                $deletable = $courses->filter(fn (Course $course): bool => CourseResource::canDeleteUnusedSubject($course))->values();
                $protected = $courses->reject(fn (Course $course): bool => CourseResource::canDeleteUnusedSubject($course))->values();

                if ($deletable->isEmpty()) {
                    return 'Only unused, unpublished draft subjects can be deleted. Published (Active), retired, and referenced subjects are protected.';
                }

                if ($protected->isEmpty()) {
                    return "These {$deletable->count()} unused draft ".Str::plural('subject', $deletable->count())
                        .' will be permanently removed. Published (Active), retired, and referenced subjects can never be deleted.';
                }

                $previewCodes = self::summarizeCourseCodes($protected);

                return "{$deletable->count()} of {$courses->count()} selected subjects are unused drafts and will be permanently deleted. "
                    ."{$protected->count()} published, retired, or referenced ".Str::plural('subject', $protected->count())
                    ." ({$previewCodes}) will be skipped safely.";
            })
            ->modalSubmitActionLabel(function (Collection $records): string {
                $deletableCount = $records
                    ->filter(fn (mixed $record): bool => $record instanceof Course && CourseResource::canDeleteUnusedSubject($record))
                    ->count();

                return $deletableCount > 0
                    ? "Delete {$deletableCount} draft ".Str::plural('subject', $deletableCount)
                    : 'Close';
            })
            ->action(function (Collection $records): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->can('create', Course::class), 403);

                $deletedCount = 0;
                $skippedCourses = collect();

                foreach ($records as $record) {
                    if (! $record instanceof Course) {
                        continue;
                    }

                    if (! CourseResource::canDeleteUnusedSubject($record)) {
                        $skippedCourses->push($record);

                        continue;
                    }

                    try {
                        CourseResource::deleteUnusedSubject($record, $actor);
                        $deletedCount++;
                    } catch (ValidationException) {
                        $skippedCourses->push($record);
                    }
                }

                if ($deletedCount > 0) {
                    $notification = Notification::make()
                        ->title("Deleted {$deletedCount} unused draft ".Str::plural('subject', $deletedCount))
                        ->success();

                    if ($skippedCourses->isNotEmpty()) {
                        $skippedCount = $skippedCourses->count();
                        $notification->body(
                            "Skipped {$skippedCount} protected ".Str::plural('subject', $skippedCount)
                            .': '.self::summarizeCourseCodes($skippedCourses).'.'
                        );
                    }

                    $notification->send();

                    return;
                }

                Notification::make()
                    ->title('No subjects deleted')
                    ->body('Only unused, unpublished draft subjects can be deleted. Published (Active), retired, and referenced subjects are protected.')
                    ->warning()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @param  Collection<int, Course>  $courses
     */
    private static function summarizeCourseCodes(Collection $courses, int $limit = 5): string
    {
        $codes = $courses->pluck('code')->filter()->values();

        if ($codes->count() <= $limit) {
            return $codes->implode(', ');
        }

        $remaining = $codes->count() - $limit;

        return $codes->take($limit)->implode(', ')." and {$remaining} more";
    }

    private static function exportSelectedXlsxAction(): BulkAction
    {
        return BulkAction::make('exportSelectedXlsx')
            ->label('Export selected as Excel (.xlsx)')
            ->icon(Heroicon::OutlinedTableCells)
            ->authorize(fn (): bool => auth()->user()?->can('viewAny', Course::class) ?? false)
            ->visible(fn (): bool => self::canManageCurricula())
            ->action(function (Collection $records): BinaryFileResponse {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                Gate::forUser($actor)->authorize('viewAny', Course::class);

                $exportService = app(CurriculumSpreadsheetExportService::class);
                $path = $exportService->exportSubjects($records->values(), 'xlsx');

                return $exportService->downloadAndDelete($path, basename($path), 'xlsx');
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function exportSelectedCsvAction(): BulkAction
    {
        return BulkAction::make('exportSelectedCsv')
            ->label('Export selected as CSV (.csv)')
            ->icon(Heroicon::OutlinedDocumentText)
            ->authorize(fn (): bool => auth()->user()?->can('viewAny', Course::class) ?? false)
            ->visible(fn (): bool => self::canManageCurricula())
            ->action(function (Collection $records): BinaryFileResponse {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                Gate::forUser($actor)->authorize('viewAny', Course::class);

                $exportService = app(CurriculumSpreadsheetExportService::class);
                $path = $exportService->exportSubjects($records->values(), 'csv');

                return $exportService->downloadAndDelete($path, basename($path), 'csv');
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function canManageCurricula(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can('create', Course::class)
            && $user->can('create', CurriculumVersion::class);
    }

    private static function assignSelectedToCurriculaAction(): BulkAction
    {
        return BulkAction::make('assignSelectedToCurricula')
            ->label('Assign to draft curricula')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->authorize(fn (): bool => self::canManageCurricula())
            ->visible(fn (): bool => self::canManageCurricula())
            ->modalHeading('Assign selected subjects to draft curricula')
            ->modalDescription('Place the selected subjects into one or more open draft curricula. Subjects already present in a curriculum are safely skipped.')
            ->modalSubmitActionLabel('Assign to draft curricula')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->schema([
                CheckboxList::make('curriculum_version_ids')
                    ->label('Draft curricula')
                    ->options(fn (): array => self::editableDraftCurriculumOptions())
                    ->bulkToggleable()
                    ->columns(['default' => 1, 'sm' => 2])
                    ->live()
                    ->required()
                    ->helperText(fn (): string => self::editableDraftCurriculumOptions() === []
                        ? 'No editable draft curricula exist yet. Create or duplicate a draft curriculum first.'
                        : 'Only open, editable draft curricula can be updated.'),
                Grid::make(['default' => 1, 'sm' => 3])
                    ->schema([
                        Select::make('year_level')
                            ->label('Year level')
                            ->options(fn (Get $get): array => self::yearLevelOptions((array) ($get('curriculum_version_ids') ?? [])))
                            ->default(1)
                            ->required(),
                        Select::make('term_type')
                            ->label('Semester')
                            ->options([
                                Term::TypeFirstSemester => '1st Semester',
                                Term::TypeSecondSemester => '2nd Semester',
                                Term::TypeSpecialTerm => 'Summer',
                            ])
                            ->default(Term::TypeFirstSemester)
                            ->required(),
                        Select::make('requirement_group')
                            ->label('Subject requirement')
                            ->options([
                                CurriculumEntry::RequirementGroupRequired => 'Required for all students',
                                CurriculumEntry::RequirementGroupElective => 'Elective subject',
                            ])
                            ->default(CurriculumEntry::RequirementGroupRequired)
                            ->required(),
                    ]),
            ])
            ->action(function (Collection $records, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && self::canManageCurricula(), 403);

                $curriculumIds = collect($data['curriculum_version_ids'] ?? [])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values();

                $yearLevel = (int) ($data['year_level'] ?? 1);
                $termType = (string) ($data['term_type'] ?? Term::TypeFirstSemester);
                $requirementGroup = (string) ($data['requirement_group'] ?? CurriculumEntry::RequirementGroupRequired);

                $service = app(CurriculumWorkbenchService::class);
                $assignedSubjectIds = [];
                $touchedCurriculumIds = [];
                $addedPlacements = 0;
                $skippedAlreadyPresent = 0;
                $skippedYearLimit = 0;
                $skippedNoSpec = 0;

                DB::transaction(function () use (
                    $actor,
                    $records,
                    $curriculumIds,
                    $yearLevel,
                    $termType,
                    $requirementGroup,
                    $service,
                    &$assignedSubjectIds,
                    &$touchedCurriculumIds,
                    &$addedPlacements,
                    &$skippedAlreadyPresent,
                    &$skippedYearLimit,
                    &$skippedNoSpec,
                ): void {
                    $draftCurricula = CurriculumVersion::query()
                        ->whereIn('id', $curriculumIds)
                        ->where('state', CurriculumVersion::StateDraft)
                        ->with('program')
                        ->get()
                        ->filter(fn (CurriculumVersion $cv): bool => $cv->isEditableDraft())
                        ->values();

                    /** @var array<int, CourseSpecification|null> $specsByCourseId */
                    $specsByCourseId = [];

                    foreach ($records as $record) {
                        if (! $record instanceof Course) {
                            continue;
                        }

                        $spec = self::latestAssignableSpecification($record);
                        $specsByCourseId[$record->id] = $spec;

                        if (! $spec instanceof CourseSpecification) {
                            $skippedNoSpec++;
                        }
                    }

                    foreach ($draftCurricula as $curriculum) {
                        if ($yearLevel > $service->yearLevelLimit($curriculum)) {
                            $skippedYearLimit++;

                            continue;
                        }

                        $existingCourseIds = CurriculumEntry::query()
                            ->where('curriculum_version_id', $curriculum->id)
                            ->join('course_specifications', 'course_specifications.id', '=', 'curriculum_entries.course_specification_id')
                            ->pluck('course_specifications.course_id')
                            ->map(fn (mixed $id): int => (int) $id)
                            ->all();

                        $existingLookup = array_flip($existingCourseIds);

                        foreach ($specsByCourseId as $courseId => $spec) {
                            if (! $spec instanceof CourseSpecification) {
                                continue;
                            }

                            if (isset($existingLookup[$courseId])) {
                                $skippedAlreadyPresent++;

                                continue;
                            }

                            $service->createEntry($actor, $curriculum, [
                                'course_specification_id' => $spec->id,
                                'year_level' => $yearLevel,
                                'term_type' => $termType,
                                'requirement_group' => $requirementGroup,
                            ]);

                            $existingLookup[$courseId] = true;
                            $assignedSubjectIds[$courseId] = true;
                            $touchedCurriculumIds[$curriculum->id] = true;
                            $addedPlacements++;
                        }
                    }
                });

                $subjectCount = count($assignedSubjectIds);
                $curriculumCount = count($touchedCurriculumIds);

                if ($addedPlacements > 0) {
                    $summary = "Assigned {$subjectCount} ".Str::plural('subject', $subjectCount)
                        ." to {$curriculumCount} draft ".Str::plural('curriculum', $curriculumCount);

                    if ($skippedAlreadyPresent > 0) {
                        $summary .= " (skipped {$skippedAlreadyPresent} already present)";
                    }

                    $summary .= '.';

                    $notification = Notification::make()
                        ->title($summary)
                        ->success();

                    $notes = [];

                    if ($skippedYearLimit > 0) {
                        $notes[] = "Skipped {$skippedYearLimit} draft ".Str::plural('curriculum', $skippedYearLimit)." whose program length is shorter than Year {$yearLevel}.";
                    }

                    if ($skippedNoSpec > 0) {
                        $notes[] = "Skipped {$skippedNoSpec} ".Str::plural('subject', $skippedNoSpec).' without an assignable revision.';
                    }

                    if ($notes !== []) {
                        $notification->body(implode(' ', $notes));
                    }

                    $notification->send();

                    return;
                }

                $reasons = [];

                if ($skippedAlreadyPresent > 0) {
                    $reasons[] = "{$skippedAlreadyPresent} already present in the selected draft curricula";
                }

                if ($skippedYearLimit > 0) {
                    $reasons[] = "{$skippedYearLimit} skipped due to program year limit";
                }

                if ($skippedNoSpec > 0) {
                    $reasons[] = "{$skippedNoSpec} without an assignable revision";
                }

                Notification::make()
                    ->title('No draft curricula updated')
                    ->body($reasons !== []
                        ? 'No new placements were added ('.implode('; ', $reasons).').'
                        : 'Select at least one open draft curriculum and subject to assign.')
                    ->warning()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function removeSelectedFromCurriculaAction(): BulkAction
    {
        return BulkAction::make('removeSelectedFromCurricula')
            ->label('Remove from draft curricula')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->authorize(fn (): bool => self::canManageCurricula())
            ->visible(fn (): bool => self::canManageCurricula())
            ->modalHeading('Remove selected subjects from draft curricula')
            ->modalDescription('Choose which open draft curricula should remove the selected subjects. Active and retired curricula are never modified.')
            ->modalSubmitActionLabel('Remove from draft curricula')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->schema(function (Collection $records): array {
                $options = self::removableDraftCurriculumOptions($records);

                return [
                    CheckboxList::make('curriculum_version_ids')
                        ->label('Draft curricula containing selected subjects')
                        ->options($options)
                        ->bulkToggleable()
                        ->columns(['default' => 1, 'sm' => 2])
                        ->required()
                        ->helperText($options === []
                            ? 'None of the selected subjects are placed in an editable draft curriculum. Active and retired curricula cannot be modified here.'
                            : 'Only open draft curricula containing at least one selected subject are listed.'),
                ];
            })
            ->action(function (Collection $records, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && self::canManageCurricula(), 403);

                $courseIds = $records
                    ->map(fn (mixed $record): ?int => $record instanceof Course ? $record->id : null)
                    ->filter()
                    ->unique()
                    ->values();

                $curriculumIds = collect($data['curriculum_version_ids'] ?? [])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values();

                $service = app(CurriculumWorkbenchService::class);
                $removedPlacements = 0;
                $touchedCurriculumIds = [];

                DB::transaction(function () use (
                    $actor,
                    $courseIds,
                    $curriculumIds,
                    $service,
                    &$removedPlacements,
                    &$touchedCurriculumIds,
                ): void {
                    $editableDraftIds = CurriculumVersion::query()
                        ->whereIn('id', $curriculumIds)
                        ->where('state', CurriculumVersion::StateDraft)
                        ->get()
                        ->filter(fn (CurriculumVersion $cv): bool => $cv->isEditableDraft())
                        ->pluck('id')
                        ->values();

                    if ($editableDraftIds->isEmpty() || $courseIds->isEmpty()) {
                        return;
                    }

                    $entries = CurriculumEntry::query()
                        ->whereIn('curriculum_version_id', $editableDraftIds)
                        ->whereHas('courseSpecification', fn (Builder $query): Builder => $query->whereIn('course_id', $courseIds))
                        ->get();

                    foreach ($entries as $entry) {
                        $service->removeEntry($actor, $entry);
                        $removedPlacements++;
                        $touchedCurriculumIds[$entry->curriculum_version_id] = true;
                    }
                });

                $curriculumCount = count($touchedCurriculumIds);

                if ($removedPlacements > 0) {
                    Notification::make()
                        ->title("Removed {$removedPlacements} subject ".Str::plural('placement', $removedPlacements)." from {$curriculumCount} draft ".Str::plural('curriculum', $curriculumCount).'.')
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('No subject placements removed')
                    ->body('None of the selected subjects were found in the chosen editable draft curricula.')
                    ->warning()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function latestAssignableSpecification(Course $course): ?CourseSpecification
    {
        return $course->specifications()
            ->whereIn('state', [CourseSpecification::StateActive, CourseSpecification::StateDraft])
            ->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', [CourseSpecification::StateActive])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private static function editableDraftCurriculumOptions(): array
    {
        return CurriculumVersion::query()
            ->where('state', CurriculumVersion::StateDraft)
            ->with(['program', 'batches.academicYear'])
            ->withCount('entries')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (CurriculumVersion $cv): bool => $cv->isEditableDraft())
            ->sortBy(fn (CurriculumVersion $cv): string => ($cv->program?->code ?? '').'|'.$cv->batchLabel())
            ->mapWithKeys(function (CurriculumVersion $cv): array {
                $programCode = $cv->program?->code ?? 'Program';
                $count = (int) $cv->entries_count;

                return [
                    $cv->id => "{$programCode} · {$cv->batchLabel()} ({$count} ".Str::plural('subject', $count).')',
                ];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function removableDraftCurriculumOptions(Collection $records): array
    {
        $courseIds = $records
            ->map(fn (mixed $record): ?int => $record instanceof Course ? $record->id : null)
            ->filter()
            ->unique()
            ->values();

        if ($courseIds->isEmpty()) {
            return [];
        }

        return CurriculumVersion::query()
            ->where('state', CurriculumVersion::StateDraft)
            ->whereHas('entries.courseSpecification', fn (Builder $query): Builder => $query->whereIn('course_id', $courseIds))
            ->with(['program', 'batches.academicYear'])
            ->withCount([
                'entries as matching_entries_count' => fn (Builder $query): Builder => $query->whereHas(
                    'courseSpecification',
                    fn (Builder $specQuery): Builder => $specQuery->whereIn('course_id', $courseIds),
                ),
            ])
            ->orderByDesc('id')
            ->get()
            ->filter(fn (CurriculumVersion $cv): bool => $cv->isEditableDraft())
            ->sortBy(fn (CurriculumVersion $cv): string => ($cv->program?->code ?? '').'|'.$cv->batchLabel())
            ->mapWithKeys(function (CurriculumVersion $cv): array {
                $programCode = $cv->program?->code ?? 'Program';
                $count = (int) $cv->matching_entries_count;

                return [
                    $cv->id => "{$programCode} · {$cv->batchLabel()} ({$count} matching ".Str::plural('placement', $count).')',
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, mixed>  $selectedCurriculumIds
     * @return array<int, string>
     */
    private static function yearLevelOptions(array $selectedCurriculumIds): array
    {
        $ids = collect($selectedCurriculumIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        $query = CurriculumVersion::query()
            ->where('state', CurriculumVersion::StateDraft)
            ->with('program');

        if ($ids->isNotEmpty()) {
            $query->whereIn('id', $ids);
        }

        $maxYears = (int) $query
            ->get()
            ->filter(fn (CurriculumVersion $cv): bool => $cv->isEditableDraft())
            ->map(fn (CurriculumVersion $cv): int => (int) ($cv->program?->duration_years ?: 4))
            ->max();

        $limit = max(1, $maxYears > 0 ? $maxYears : 4);
        $options = [];

        for ($year = 1; $year <= $limit; $year++) {
            $options[$year] = "Year {$year}";
        }

        return $options;
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
