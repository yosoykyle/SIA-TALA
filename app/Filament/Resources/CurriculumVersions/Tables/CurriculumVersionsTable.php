<?php

namespace App\Filament\Resources\CurriculumVersions\Tables;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\CurriculumVersions\Schemas\CurriculumVersionForm;
use App\Models\AcademicYear;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CurriculumVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'program',
                    'batches.academicYear',
                    'copiedFrom.batches.academicYear',
                    'effectiveEntryTerm.academicYear',
                    'entries.termOfferings.term.academicYear',
                ])
                ->withCount(['entries', 'batches', 'studentProfiles']))
            ->searchPlaceholder('Search curricula')
            ->columns([
                TextColumn::make('program.code')
                    ->label('Program')
                    ->weight('bold')
                    ->description(fn (CurriculumVersion $record): string => Str::limit((string) ($record->program?->name ?? ''), 38))
                    ->tooltip(fn (CurriculumVersion $record): ?string => $record->program?->name)
                    ->searchable(['program.code', 'program.name'])
                    ->sortable()
                    ->wrap(),
                TextColumn::make('batch_label')
                    ->label('Curriculum')
                    ->state(fn (CurriculumVersion $record): string => $record->batchLabel())
                    ->searchable(['name', 'version_code'])
                    ->weight('bold')
                    ->wrap()
                    ->description(function (CurriculumVersion $record): string {
                        $subjectCount = (int) $record->entries_count;
                        $parts = [
                            $subjectCount.' '.Str::plural('subject', $subjectCount),
                            self::formatTermsAndIntakeSummary($record),
                        ];

                        return implode(' · ', $parts);
                    })
                    ->tooltip(fn (CurriculumVersion $record): ?string => self::termsAndIntakeTooltip($record)),
                TextColumn::make('batches_count')
                    ->label('Batches')
                    ->badge()
                    ->color(fn (CurriculumVersion $record, mixed $state): string => (int) $state > 0
                        ? 'primary'
                        : (in_array($record->state, [CurriculumVersion::StateDraft, CurriculumVersion::StateActive], true) ? 'warning' : 'gray'))
                    ->description(fn (CurriculumVersion $record): ?string => (int) $record->batches_count === 0 && $record->state === CurriculumVersion::StateActive
                        ? 'Not in Admissions'
                        : self::batchRangeDescription($record))
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('student_profiles_count')
                    ->label('Students')
                    ->numeric()
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'tabular-nums'])
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        CurriculumVersion::StateActive => 'success',
                        CurriculumVersion::StateDraft => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        CurriculumVersion::StateActive => 'Active',
                        CurriculumVersion::StateDraft => 'Draft',
                        CurriculumVersion::StateSuperseded => 'Retired',
                        CurriculumVersion::StateArchived => 'Archived',
                        default => str((string) $state)->headline()->toString(),
                    })
                    ->tooltip(fn (CurriculumVersion $record): ?string => match ($record->state) {
                        CurriculumVersion::StateSuperseded => 'Retired from new batch assignments; continuing students stay on this plan.',
                        CurriculumVersion::StateArchived => 'Archived historical curriculum.',
                        default => null,
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('program_id')
                    ->label('Program')
                    ->relationship('program', 'code'),
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(CurriculumVersion::stateOptions()),
            ], layout: FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->size('sm'))
            ->filtersResetAction(fn (Action $action, Table $table): Action => $action->visible(fn (): bool => $table->isFiltered()))
            ->deferFilters(false)
            ->columnManager(false)
            ->extraAttributes(['class' => 'tala-data-table'])
            ->stackedOnMobile()
            ->recordUrl(fn (CurriculumVersion $record, Table $table): string => CurriculumVersionResource::getUrl('review', array_filter([
                'record' => $record,
                'programId' => $record->program_id,
                'oversightTermId' => self::oversightTermId($table),
            ], fn (mixed $value): bool => $value !== null)))
            ->recordActions([
                self::configureAddBatchAction(
                    Action::make('assignBatch')
                        ->label('Assign batch')
                        ->button()
                        ->size('sm')
                        ->color('warning')
                        ->icon(Heroicon::OutlinedPlus),
                    submitLabel: 'Assign batch',
                )
                    ->authorize(fn (CurriculumVersion $record): bool => $record->state === CurriculumVersion::StateActive
                        && (int) $record->batches_count === 0
                        && (auth()->user()?->can('copy', $record) ?? false))
                    ->visible(fn (CurriculumVersion $record): bool => $record->state === CurriculumVersion::StateActive
                        && (int) $record->batches_count === 0
                        && (auth()->user()?->can('copy', $record) ?? false)),
                Action::make('open')
                    ->label('Open')
                    ->button()
                    ->size('sm')
                    ->color('gray')
                    ->icon(null)
                    ->url(fn (CurriculumVersion $record, Table $table): string => CurriculumVersionResource::getUrl('review', array_filter([
                        'record' => $record,
                        'programId' => $record->program_id,
                        'oversightTermId' => self::oversightTermId($table),
                    ], fn (mixed $value): bool => $value !== null))),
                ActionGroup::make([
                    self::configureAddBatchAction(
                        Action::make('addBatch')
                            ->label('Add batch')
                            ->color('primary')
                            ->icon(Heroicon::OutlinedPlus),
                        submitLabel: 'Add batch',
                    )
                        ->authorize(fn (CurriculumVersion $record): bool => in_array($record->state, [CurriculumVersion::StateDraft, CurriculumVersion::StateActive], true)
                            && ! ($record->state === CurriculumVersion::StateActive && (int) $record->batches_count === 0)
                            && (auth()->user()?->can('copy', $record) ?? false))
                        ->visible(fn (CurriculumVersion $record): bool => in_array($record->state, [CurriculumVersion::StateDraft, CurriculumVersion::StateActive], true)
                            && ! ($record->state === CurriculumVersion::StateActive && (int) $record->batches_count === 0)
                            && (auth()->user()?->can('copy', $record) ?? false)),
                    Action::make('retire')
                        ->label('Retire')
                        ->color('gray')
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->requiresConfirmation()
                        ->modalHeading(fn (CurriculumVersion $record): string => 'Retire '.$record->batchLabel().'?')
                        ->modalDescription('No new batches can be assigned after retiring. Students already assigned to this curriculum continue uninterrupted.')
                        ->modalSubmitActionLabel('Retire curriculum')
                        ->authorize(fn (CurriculumVersion $record): bool => $record->state === CurriculumVersion::StateActive
                            && (auth()->user()?->can('copy', $record) ?? false))
                        ->visible(fn (CurriculumVersion $record): bool => $record->state === CurriculumVersion::StateActive
                            && (auth()->user()?->can('copy', $record) ?? false))
                        ->action(function (CurriculumVersion $record): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);

                            try {
                                app(CurriculumVersionLifecycleService::class)->retire($actor, $record);
                            } catch (ValidationException $exception) {
                                Notification::make()
                                    ->title('Could not retire curriculum')
                                    ->body(collect($exception->errors())->flatten()->implode(' '))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Curriculum retired')
                                ->body('Continuing students keep this curriculum, and no new batches can be assigned to it.')
                                ->success()
                                ->send();
                        }),
                    Action::make('duplicate')
                        ->label('Duplicate')
                        ->color('gray')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->authorize(fn (CurriculumVersion $record): bool => auth()->user()?->can('copy', $record) ?? false)
                        ->visible(fn (CurriculumVersion $record): bool => auth()->user()?->can('copy', $record) ?? false)
                        ->modalHeading('Copy to new curriculum')
                        ->modalDescription('Copy this curriculum and all its subjects into an editable draft for a new batch. Continuing student assignments stay unchanged.')
                        ->modalWidth(Width::TwoExtraLarge)
                        ->stickyModalHeader()
                        ->stickyModalFooter()
                        ->modalSubmitActionLabel(fn (CurriculumVersion $record): string => $record->program?->is_active === false
                            ? 'Activate and proceed'
                            : 'Copy to draft')
                        ->fillForm(fn (CurriculumVersion $record): array => [
                            'starting_academic_year_id' => CurriculumVersionForm::suggestedBatchId($record->program_id),
                            'activate_program_if_inactive' => false,
                            'name' => '',
                            'effective_entry_term_id' => $record->effective_entry_term_id,
                        ])
                        ->schema(fn (CurriculumVersion $record): array => CurriculumVersionForm::copyFields($record))
                        ->action(function (CurriculumVersion $record, array $data, Table $table, HasTable $livewire): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);

                            $startingBatch = filled($data['starting_academic_year_id'] ?? null)
                                ? AcademicYear::query()->find((int) $data['starting_academic_year_id'])
                                : null;

                            try {
                                $draft = app(CurriculumVersionLifecycleService::class)->copyToDraft(
                                    actor: $actor,
                                    source: $record,
                                    versionCode: CurriculumVersionForm::draftCodeFor($record->program_id),
                                    name: filled($data['name'] ?? null) ? trim((string) $data['name']) : null,
                                    effectiveEntryTermId: filled($data['effective_entry_term_id'] ?? null)
                                        ? (int) $data['effective_entry_term_id']
                                        : null,
                                    startingBatch: $startingBatch,
                                    activateProgramIfInactive: (bool) ($data['activate_program_if_inactive'] ?? false),
                                );
                            } catch (ValidationException $exception) {
                                Notification::make()
                                    ->title('Could not copy curriculum')
                                    ->body(collect($exception->errors())->flatten()->implode(' '))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Curriculum copied to draft')
                                ->body("Created {$draft->batchLabel()} with {$draft->entries->count()} ".Str::plural('subject', $draft->entries->count()).'.')
                                ->success()
                                ->send();

                            $livewire->redirect(CurriculumVersionResource::getUrl('review', array_filter([
                                'record' => $draft,
                                'programId' => $draft->program_id,
                                'oversightTermId' => self::oversightTermId($table),
                            ], fn (mixed $value): bool => $value !== null)));
                        }),
                    Action::make('discardDraft')
                        ->label('Discard draft')
                        ->color('danger')
                        ->icon(Heroicon::OutlinedTrash)
                        ->requiresConfirmation()
                        ->modalHeading('Discard this draft curriculum?')
                        ->modalDescription('Its draft subject placements will be permanently removed. Active curricula and student records are not affected.')
                        ->modalSubmitActionLabel('Discard draft')
                        ->authorize(fn (CurriculumVersion $record): bool => (auth()->user()?->can('update', $record) ?? false)
                            && app(CurriculumVersionLifecycleService::class)->canDiscardDraft($record))
                        ->visible(fn (CurriculumVersion $record): bool => (auth()->user()?->can('update', $record) ?? false)
                            && app(CurriculumVersionLifecycleService::class)->canDiscardDraft($record))
                        ->action(function (CurriculumVersion $record): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);

                            try {
                                app(CurriculumVersionLifecycleService::class)->discardDraft($record, $actor);
                            } catch (ValidationException $exception) {
                                Notification::make()
                                    ->title('Draft curriculum cannot be discarded')
                                    ->body(collect($exception->errors())->flatten()->implode(' '))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Draft curriculum discarded')
                                ->success()
                                ->send();
                        }),
                ])
                    ->icon(Heroicon::OutlinedEllipsisHorizontal)
                    ->color('gray')
                    ->button()
                    ->size('sm')
                    ->hiddenLabel()
                    ->tooltip('More actions'),
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
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->visible(fn (HasTable $livewire): bool => ! filled($livewire->getTableSearch())
                        && ! $livewire->getTable()->isFiltered()
                        && (auth()->user()?->can('create', CurriculumVersion::class) ?? false)),
            ])
            ->toolbarActions([
                CurriculumVersionResource::createDraftAction(oversightTermId: self::oversightTermId($table))
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->size('sm')
                    ->visible(fn (): bool => auth()->user()?->can('create', CurriculumVersion::class) ?? false),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function configureAddBatchAction(Action $action, string $submitLabel): Action
    {
        return $action
            ->modalHeading(fn (CurriculumVersion $record): string => (int) $record->batches_count === 0
                ? 'Assign starting batch to '.$record->batchLabel()
                : 'Add batch to '.$record->batchLabel())
            ->modalDescription('Assign an Academic Year batch to this curriculum without duplicating its subjects.')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel(fn (CurriculumVersion $record): string => $record->program?->is_active === false
                ? 'Activate and proceed'
                : $submitLabel)
            ->fillForm(fn (CurriculumVersion $record): array => [
                'academic_year_id' => CurriculumVersionForm::suggestedBatchId($record->program_id),
                'activate_program_if_inactive' => false,
            ])
            ->schema(fn (CurriculumVersion $record): array => CurriculumVersionForm::addBatchFields($record))
            ->action(function (CurriculumVersion $record, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $academicYear = AcademicYear::query()->findOrFail((int) $data['academic_year_id']);

                try {
                    app(CurriculumVersionLifecycleService::class)->addBatch(
                        actor: $actor,
                        curriculum: $record,
                        academicYear: $academicYear,
                        activateProgramIfInactive: (bool) ($data['activate_program_if_inactive'] ?? false),
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Could not assign batch')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Batch '.CurriculumVersion::formatBatchShort($academicYear).' added')
                    ->body("Assigned to {$record->fresh('batches.academicYear')->batchLabel()}.")
                    ->success()
                    ->send();
            });
    }

    private static function oversightTermId(Table $table): ?int
    {
        $livewire = $table->getLivewire();

        if (property_exists($livewire, 'oversightTermId') && is_int($livewire->oversightTermId) && $livewire->oversightTermId > 0) {
            return $livewire->oversightTermId;
        }

        return request()->integer('oversightTermId') ?: null;
    }

    private static function batchRangeDescription(CurriculumVersion $record): ?string
    {
        if ((int) $record->batches_count <= 1) {
            return null;
        }

        $start = $record->startBatchLabel() ? Str::after($record->startBatchLabel(), 'Batch ') : null;
        $last = $record->lastBatchLabel() ? Str::after($record->lastBatchLabel(), 'Batch ') : null;

        if ($start === null || $last === null || $start === $last) {
            return null;
        }

        return "{$start} to {$last}";
    }

    private static function formatTermsAndIntakeSummary(CurriculumVersion $record): string
    {
        $terms = self::usedTerms($record);

        if ($terms->isNotEmpty()) {
            $firstTerm = self::formatTermLabel($terms->first());
            $extra = $terms->count() - 1;

            return $extra > 0
                ? "{$firstTerm} (+{$extra} more)"
                : $firstTerm;
        }

        if ($record->effectiveEntryTerm instanceof Term) {
            return 'First intake: '.self::formatTermLabel($record->effectiveEntryTerm);
        }

        return 'Not used in a term yet';
    }

    private static function termsAndIntakeTooltip(CurriculumVersion $record): ?string
    {
        $terms = self::usedTerms($record);

        if ($terms->count() <= 1) {
            return null;
        }

        return $terms
            ->map(fn (Term $term): string => self::formatTermLabel($term))
            ->implode(', ');
    }

    /** @var array<int, Collection<int, Term>> */
    private static array $usedTermsCache = [];

    /**
     * @return Collection<int, Term>
     */
    private static function usedTerms(CurriculumVersion $record): Collection
    {
        $cacheKey = spl_object_id($record);
        if (isset(self::$usedTermsCache[$cacheKey])) {
            return self::$usedTermsCache[$cacheKey];
        }

        if (count(self::$usedTermsCache) > 200) {
            self::$usedTermsCache = [];
        }

        $offeringTerms = $record->entries
            ->flatMap(fn (CurriculumEntry $entry): Collection => $entry->termOfferings)
            ->map(fn (TermOffering $offering): ?Term => $offering->term)
            ->filter(fn (mixed $term): bool => $term instanceof Term);

        $enrollmentTerms = Term::query()
            ->with('academicYear')
            ->whereHas('enrollments.studentProfile', fn (Builder $query): Builder => $query->where('curriculum_version_id', $record->id))
            ->get();

        return self::$usedTermsCache[$cacheKey] = $offeringTerms
            ->merge($enrollmentTerms)
            ->unique('id')
            ->sortByDesc(fn (Term $term): string => (string) ($term->starts_on?->toDateString() ?? ''))
            ->values();
    }

    private static function formatTermLabel(Term $term): string
    {
        $termLabel = trim((string) $term->label);
        $ayLabel = trim((string) ($term->academicYear?->label ?? ''));

        if ($termLabel !== '' && $ayLabel !== '' && ! str_contains($termLabel, $ayLabel)) {
            return "{$termLabel}, {$ayLabel}";
        }

        return $termLabel !== '' ? $termLabel : $ayLabel;
    }
}
