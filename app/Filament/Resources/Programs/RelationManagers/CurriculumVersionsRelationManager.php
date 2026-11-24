<?php

namespace App\Filament\Resources\Programs\RelationManagers;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Models\AcademicYear;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class CurriculumVersionsRelationManager extends RelationManager
{
    #[Url]
    public ?int $oversightTermId = null;

    protected static bool $isLazy = false;

    protected static string $relationship = 'curriculumVersions';

    protected static ?string $title = 'Curricula';

    public function isReadOnly(): bool
    {
        return ! $this->canManage();
    }

    public function table(Table $table): Table
    {
        $program = $this->ownerProgram();
        $curriculumUrl = fn (CurriculumVersion $record): string => CurriculumVersionResource::getUrl('review', array_filter([
            'record' => $record,
            'programId' => $program->id,
            'oversightTermId' => $this->oversightTermId(),
        ], fn (mixed $value): bool => $value !== null));

        return $table
            ->recordTitleAttribute('name')
            ->extraAttributes(['class' => 'tala-data-table tala-program-curricula-table'])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['academicYears', 'effectiveEntryTerm.academicYear', 'entries.courseSpecification'])
                ->withCount(['entries', 'batches'])
                ->orderByRaw("CASE state WHEN 'ACTIVE' THEN 1 WHEN 'DRAFT' THEN 2 WHEN 'SUPERSEDED' THEN 3 ELSE 4 END")
                ->orderByDesc('id'))
            ->groups([
                Group::make('state')
                    ->titlePrefixedWithLabel(false)
                    ->getKeyFromRecordUsing(fn (CurriculumVersion $record): string => $record->state)
                    ->getTitleFromRecordUsing(fn (CurriculumVersion $record): string => match ($record->state) {
                        CurriculumVersion::StateActive => 'Active',
                        CurriculumVersion::StateDraft => 'Draft',
                        default => 'Retired',
                    })
                    ->orderQueryUsing(fn (Builder $query): Builder => $query
                        ->orderByRaw("CASE state WHEN 'ACTIVE' THEN 1 WHEN 'DRAFT' THEN 2 WHEN 'SUPERSEDED' THEN 3 ELSE 4 END")
                        ->orderByDesc('id'))
                    ->collapsible(),
            ])
            ->defaultGroup('state')
            ->groupingSettingsHidden()
            ->heading('Curricula')
            ->description('Curricula for this program. Open any row to view or edit its subjects, or start a new batch curriculum.')
            ->columns([
                TextColumn::make('batch_label')
                    ->label('Curriculum')
                    ->weight('semibold')
                    ->state(fn (CurriculumVersion $record): string => $record->batchLabel())
                    ->description(function (CurriculumVersion $record): string {
                        $units = $record->entries->sum(fn ($entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0));
                        $unitsFormatted = rtrim(rtrim(number_format($units, 2, '.', ''), '0'), '.');

                        return $record->entries_count.' '.Str::plural('subject', (int) $record->entries_count).' · '.$unitsFormatted.' '.Str::plural('unit', $units === 1.0 ? 1 : 2);
                    })
                    ->wrap(),
                TextColumn::make('start_batch')
                    ->label('Start batch')
                    ->state(fn (CurriculumVersion $record): ?string => $record->startBatchLabel())
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('last_batch')
                    ->label('Last batch used')
                    ->state(fn (CurriculumVersion $record): ?string => $record->lastBatchLabel())
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('batches_count')
                    ->label('Batches')
                    ->numeric()
                    ->alignCenter(),
                TextColumn::make('students_count')
                    ->label('Students')
                    ->state(fn (CurriculumVersion $record): int => $record->studentCountForBatches())
                    ->numeric()
                    ->alignCenter(),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CurriculumVersion::stateOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        CurriculumVersion::StateActive => 'success',
                        CurriculumVersion::StateDraft => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->placeholder('All statuses')
                    ->options(CurriculumVersion::stateOptions()),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->size('sm')->label('Filters')->color('gray'))
            ->deferFilters(false)
            ->columnManager(false)
            ->stackedOnMobile()
            ->headerActions([
                CurriculumVersionResource::createDraftAction($program->id, $this->oversightTermId(), 'createCurriculumDraft')
                    ->size('sm')
                    ->color(fn (): string => $program->curriculumVersions()->exists() ? 'gray' : 'primary'),
            ])
            ->recordUrl(fn (CurriculumVersion $record): string => $curriculumUrl($record))
            ->recordActions([
                Action::make('addBatch')
                    ->label('Add batch')
                    ->button()
                    ->size('sm')
                    ->color('gray')
                    ->icon(Heroicon::OutlinedPlus)
                    ->authorize(fn (CurriculumVersion $record): bool => $this->canManage() && $record->state === CurriculumVersion::StateActive)
                    ->visible(fn (CurriculumVersion $record): bool => $this->canManage() && $record->state === CurriculumVersion::StateActive)
                    ->modalHeading(fn (CurriculumVersion $record): string => 'Add batch to '.$record->batchLabel())
                    ->modalDescription(fn (): ?string => ! $program->is_active
                        ? "{$program->code} is inactive. Proceeding will activate the program and assign the selected batch."
                        : 'Assign an unassigned academic year batch to this active curriculum.')
                    ->modalSubmitActionLabel(fn (): string => ! $program->is_active ? 'Activate and proceed' : 'Add batch')
                    ->schema([
                        Select::make('academic_year_id')
                            ->label('Academic year batch')
                            ->required()
                            ->options(fn (): array => app(CurriculumVersionLifecycleService::class)
                                ->availableAcademicYearsForProgram($program)
                                ->mapWithKeys(fn (AcademicYear $year): array => [
                                    $year->id => 'Batch '.CurriculumVersion::formatBatchShort($year).' ('.$year->label.')',
                                ])
                                ->all()),
                    ])
                    ->action(function (CurriculumVersion $record, array $data): void {
                        $academicYear = AcademicYear::query()->findOrFail((int) $data['academic_year_id']);

                        try {
                            app(CurriculumVersionLifecycleService::class)->addBatch(
                                actor: $this->actor(),
                                curriculum: $record,
                                academicYear: $academicYear,
                                activateProgramIfInactive: ! $this->ownerProgram()->is_active,
                            );
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Batch could not be added')
                                ->body(collect($exception->errors())->flatten()->first() ?? 'Unable to add this batch.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Batch added')
                            ->body('Batch '.CurriculumVersion::formatBatchShort($academicYear).' is now assigned to '.$record->fresh(['academicYears'])->batchLabel().'.')
                            ->success()
                            ->send();
                    }),
                Action::make('retireCurriculum')
                    ->label('Retire')
                    ->button()
                    ->size('sm')
                    ->color('gray')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->authorize(fn (CurriculumVersion $record): bool => $this->canManage() && $record->state === CurriculumVersion::StateActive)
                    ->visible(fn (CurriculumVersion $record): bool => $this->canManage() && $record->state === CurriculumVersion::StateActive)
                    ->requiresConfirmation()
                    ->modalHeading(fn (CurriculumVersion $record): string => 'Retire '.$record->batchLabel().'?')
                    ->modalDescription('No new batches can be added once retired. Existing batches and students will continue using this curriculum normally.')
                    ->modalSubmitActionLabel('Retire')
                    ->action(function (CurriculumVersion $record): void {
                        app(CurriculumVersionLifecycleService::class)->retire($this->actor(), $record);

                        Notification::make()
                            ->title('Curriculum retired')
                            ->success()
                            ->send();
                    }),
                Action::make('discardDraft')
                    ->label('Discard draft')
                    ->button()
                    ->size('sm')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash)
                    ->authorize(fn (CurriculumVersion $record): bool => $this->canManage() && $record->isEditableDraft())
                    ->visible(fn (CurriculumVersion $record): bool => $this->canManage() && $record->isEditableDraft())
                    ->requiresConfirmation()
                    ->modalHeading('Discard this draft curriculum?')
                    ->modalDescription('Its draft subject placements will be permanently removed. Active curricula and student records are not affected.')
                    ->modalSubmitActionLabel('Discard draft')
                    ->action(function (CurriculumVersion $record): void {
                        app(CurriculumVersionLifecycleService::class)->discardDraft($record, $this->actor());

                        Notification::make()
                            ->title('Draft curriculum discarded')
                            ->success()
                            ->send();
                    }),
                Action::make('openCurriculumSheet')
                    ->button()
                    ->size('sm')
                    ->color('gray')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-right')
                    ->iconPosition('after')
                    ->url(fn (CurriculumVersion $record): string => $curriculumUrl($record)),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'No matching curricula'
                : 'No curricula yet')
            ->emptyStateDescription(fn (): string => $this->canManage()
                ? 'Create the first study plan for '.$program->code.' across year levels and semesters.'
                : 'No curriculum has been recorded for this program yet.')
            ->emptyStateActions([
                CurriculumVersionResource::createDraftAction($program->id, $this->oversightTermId(), 'emptyCreateCurriculumDraft')
                    ->visible(fn (): bool => $this->canManage()),
            ])
            ->toolbarActions([]);
    }

    private function canManage(): bool
    {
        return $this->actor()->can('update', $this->ownerProgram());
    }

    private function ownerProgram(): Program
    {
        $owner = $this->getOwnerRecord();
        abort_unless($owner instanceof Program, 404);

        return $owner;
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function oversightTermId(): ?int
    {
        if (is_int($this->oversightTermId) && $this->oversightTermId > 0) {
            return $this->oversightTermId;
        }

        $termId = request()->integer('oversightTermId');

        return $termId > 0 ? $termId : null;
    }
}
