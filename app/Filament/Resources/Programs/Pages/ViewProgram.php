<?php

namespace App\Filament\Resources\Programs\Pages;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Filament\Concerns\HasAcademicOversightReturn;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\Programs\Schemas\ProgramForm;
use App\Filament\Resources\Programs\Tables\ProgramsTable;
use App\Models\AcademicYear;
use App\Models\CandidateScheduleRow;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\GridDirection;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class ViewProgram extends ViewRecord
{
    use HasAcademicOversightReturn;

    protected static string $resource = ProgramResource::class;

    protected string $view = 'filament.resources.programs.pages.view-program';

    #[Url(as: 'tab')]
    public string $activeTab = 'curricula';

    #[Url(as: 'termId')]
    public ?int $selectedTermId = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (! in_array($this->activeTab, ['curricula', 'sections'], true)) {
            $this->activeTab = 'curricula';
        }

        $this->selectedTermId = $this->resolveSelectedTerm()?->id;
    }

    public function switchTab(string $tab): void
    {
        if (in_array($tab, ['curricula', 'sections'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function getTitle(): string
    {
        $program = $this->getRecord();

        return $program instanceof Program ? $program->name : parent::getTitle();
    }

    public function getHeading(): string|Htmlable
    {
        $program = $this->getRecord();

        if (! $program instanceof Program) {
            return parent::getHeading();
        }

        $badgeColor = $program->is_active ? 'success' : 'gray';
        $badgeLabel = $program->is_active ? 'Active' : 'Inactive';

        return new HtmlString(Blade::render(<<<'BLADE'
            <span class="tala-program-heading">
                <span>{{ $name }}</span>
                <x-filament::badge :color="$badgeColor" size="sm">{{ $badgeLabel }}</x-filament::badge>
            </span>
        BLADE, [
            'name' => $program->name,
            'badgeColor' => $badgeColor,
            'badgeLabel' => $badgeLabel,
        ]));
    }

    public function getSubheading(): ?string
    {
        $program = $this->getRecord();

        if (! $program instanceof Program) {
            return null;
        }

        $years = $program->duration_years
            ? $program->duration_years.' '.Str::plural('year', (int) $program->duration_years)
            : null;

        return collect([
            $program->code,
            $years,
        ])->filter()->implode(' · ');
    }

    public function getBreadcrumbs(): array
    {
        $program = $this->getRecord();
        $label = $program instanceof Program ? (string) $program->code : 'Program';
        $params = $this->oversightQueryParameters();

        return [
            CatalogCurriculaWorkbench::getUrl($params) => 'Programs & Curricula',
            ProgramResource::getUrl('index', $params) => 'Programs',
            $label,
        ];
    }

    protected function getHeaderActions(): array
    {
        $program = $this->programRecord();

        return [
            $this->returnToOversightAction('Return to Academic Oversight')
                ->visible(fn (): bool => $this->isReadOnly()),
            EditAction::make()
                ->label('Edit')
                ->color('gray')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->modal()
                ->modalHeading('Edit program details')
                ->modalDescription('Update program code, name, max year, or active status.')
                ->modalWidth(Width::TwoExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->schema(fn (Schema $schema): Schema => ProgramForm::configure($schema))
                ->authorize(fn (): bool => $this->canManage())
                ->visible(fn (): bool => $this->canManage()),
            Action::make('deactivateProgram')
                ->label('Deactivate')
                ->color('gray')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->authorize(fn (): bool => $this->canManage() && $this->programRecord()->is_active && ! ProgramsTable::isUnusedProgram($this->programRecord(), fresh: true))
                ->visible(fn (): bool => $this->canManage() && $this->programRecord()->is_active && ! ProgramsTable::isUnusedProgram($this->programRecord(), fresh: true))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => "Deactivate {$this->programRecord()->code}?")
                ->modalDescription(fn (): string => $this->deactivationConfirmationCopy($this->programRecord()))
                ->modalSubmitActionLabel('Deactivate')
                ->action(function (): void {
                    $actor = $this->actor();
                    $program = $this->programRecord();
                    abort_unless($this->canManage(), 403);

                    $blockReason = ProgramsTable::deactivationBlockReason($program);

                    if ($blockReason !== null) {
                        Notification::make()
                            ->title('Program cannot be deactivated')
                            ->body($blockReason)
                            ->danger()
                            ->send();

                        return;
                    }

                    $program->update(['is_active' => false]);
                    $this->record = $program->fresh();

                    activity()
                        ->performedOn($program)
                        ->causedBy($actor)
                        ->event('program_deactivated')
                        ->withProperties([
                            'program_code' => $program->code,
                            'program_name' => $program->name,
                        ])
                        ->log('Program deactivated');

                    Notification::make()
                        ->title("{$program->code} deactivated")
                        ->body('New batches and admissions have stopped. Continuing students remain unaffected.')
                        ->success()
                        ->send();
                }),
            Action::make('reactivateProgram')
                ->label('Reactivate')
                ->color('primary')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->authorize(fn (): bool => $this->canManage() && ! $this->programRecord()->is_active)
                ->visible(fn (): bool => $this->canManage() && ! $this->programRecord()->is_active)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => "Reactivate {$this->programRecord()->code}?")
                ->modalDescription(fn (): string => "Reactivate {$this->programRecord()->code}? New batches can be assigned and the program will be eligible for Admissions once it has a curriculum for the incoming batch.")
                ->modalSubmitActionLabel('Reactivate')
                ->action(function (): void {
                    $actor = $this->actor();
                    $program = $this->programRecord();
                    abort_unless($this->canManage(), 403);

                    $program->update(['is_active' => true]);
                    $this->record = $program->fresh();

                    activity()
                        ->performedOn($program)
                        ->causedBy($actor)
                        ->event('program_reactivated')
                        ->withProperties([
                            'program_code' => $program->code,
                            'program_name' => $program->name,
                        ])
                        ->log('Program reactivated');

                    Notification::make()
                        ->title("{$program->code} reactivated")
                        ->success()
                        ->send();
                }),
            Action::make('deleteProgram')
                ->label('Delete')
                ->color('danger')
                ->icon(Heroicon::OutlinedTrash)
                ->authorize(fn (): bool => $this->canManage() && ProgramsTable::isUnusedProgram($this->programRecord(), fresh: true))
                ->visible(fn (): bool => $this->canManage() && ProgramsTable::isUnusedProgram($this->programRecord(), fresh: true))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => "Delete {$this->programRecord()->code}?")
                ->modalDescription('This unused program has no curricula, applications, or student records and will be permanently removed.')
                ->modalSubmitActionLabel('Delete')
                ->action(function (): void {
                    $actor = $this->actor();
                    $program = $this->programRecord();
                    abort_unless($this->canManage(), 403);

                    $deleted = DB::transaction(function () use ($program, $actor): bool {
                        $locked = Program::query()->lockForUpdate()->findOrFail($program->id);

                        if (! ProgramsTable::isUnusedProgram($locked, fresh: true)) {
                            return false;
                        }

                        $properties = [
                            'program_id' => $locked->id,
                            'program_code' => $locked->code,
                            'program_name' => $locked->name,
                        ];

                        $locked->delete();

                        activity()
                            ->causedBy($actor)
                            ->event('program_deleted')
                            ->withProperties($properties)
                            ->log('Unused program deleted');

                        return true;
                    });

                    if (! $deleted) {
                        Notification::make()
                            ->title('Program cannot be deleted')
                            ->body('This program already has linked records. Deactivate it instead.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Program deleted')
                        ->success()
                        ->send();

                    $this->redirect(CatalogCurriculaWorkbench::getUrl($this->oversightQueryParameters()));
                }),
        ];
    }

    public function activateBeforeCreateCurriculumAction(): Action
    {
        return Action::make('activateBeforeCreateCurriculum')
            ->requiresConfirmation()
            ->authorize(fn (): bool => $this->canCreateCurriculum() && $this->canManage())
            ->modalHeading(fn (): string => "Activate {$this->programRecord()->code} to continue?")
            ->modalDescription(function (array $arguments): string {
                $code = $this->programRecord()->code;
                $intent = ($arguments['mode'] ?? 'blank') === 'copy'
                    ? 'copying a curriculum'
                    : 'creating a new curriculum';

                return "{$code} is inactive. You need to activate the program before {$intent}.";
            })
            ->modalSubmitActionLabel('Activate and proceed')
            ->modalCancelActionLabel('Cancel')
            ->action(function (array $arguments): void {
                $this->activateProgramWithAudit('create_curriculum');
                $this->replaceMountedAction('createCurriculum', $arguments);
            });
    }

    public function createCurriculumAction(): Action
    {
        return Action::make('createCurriculum')
            ->label('Add new curriculum')
            ->authorize(fn (): bool => $this->canCreateCurriculum())
            ->modalHeading('Add new curriculum')
            ->modalDescription('Assign a starting batch and either start from a blank sheet or copy an existing curriculum.')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Create draft')
            ->modalCancelActionLabel('Cancel')
            ->fillForm(function (array $arguments): array {
                $program = $this->programRecord();
                $lifecycle = app(CurriculumVersionLifecycleService::class);
                $suggested = $lifecycle->suggestedNextAcademicYearForProgram($program);
                $copyable = $this->copyableCurricula($program);

                $requestedMode = (string) ($arguments['mode'] ?? 'blank');
                $mode = ($requestedMode === 'copy' && $copyable->isNotEmpty()) ? 'copy' : 'blank';

                $sourceId = isset($arguments['sourceCurriculumId'])
                    ? (int) $arguments['sourceCurriculumId']
                    : $copyable->first()?->id;

                return [
                    'program_display' => "{$program->code} — {$program->name}",
                    'starting_academic_year_id' => $suggested?->id,
                    'creation_mode' => $mode,
                    'source_curriculum_version_id' => $sourceId,
                ];
            })
            ->schema(function (): array {
                $program = $this->programRecord();
                $lifecycle = app(CurriculumVersionLifecycleService::class);
                $availableYears = $lifecycle->availableAcademicYearsForProgram($program);
                $copyable = $this->copyableCurricula($program);

                $yearOptions = $availableYears
                    ->mapWithKeys(fn (AcademicYear $year): array => [
                        $year->id => 'Batch '.CurriculumVersion::formatBatchShort($year),
                    ])
                    ->all();

                $sourceOptions = $copyable
                    ->mapWithKeys(function (CurriculumVersion $cv): array {
                        $stateLabel = CurriculumVersion::stateOptions()[$cv->state] ?? $cv->state;

                        return [
                            $cv->id => sprintf(
                                '%s — %s (%d %s)',
                                $cv->batchLabel(),
                                $stateLabel,
                                (int) $cv->entries_count,
                                Str::plural('subject', (int) $cv->entries_count),
                            ),
                        ];
                    })
                    ->all();

                $modeOptions = ['blank' => 'Start blank'];
                $modeDescriptions = ['blank' => 'Begin with an empty curriculum and add subjects by year and semester.'];

                if ($sourceOptions !== []) {
                    $modeOptions['copy'] = 'Copy from existing curriculum';
                    $modeDescriptions['copy'] = 'Start with all subjects copied from an active or retired curriculum.';
                }

                $academicYearsUrl = TermOverview::getUrl(['overviewTab' => 'academic-years']);

                return [
                    TextInput::make('program_display')
                        ->label('Program')
                        ->disabled()
                        ->dehydrated(false),
                    Select::make('starting_academic_year_id')
                        ->label('Starting batch')
                        ->options($yearOptions)
                        ->native(true)
                        ->required($yearOptions !== [])
                        ->placeholder($yearOptions === [] ? 'No unassigned academic years available' : 'Select starting academic year batch')
                        ->helperText(new HtmlString(
                            ($yearOptions === []
                                ? 'All configured academic years already have a curriculum for this program. '
                                : 'Select the first incoming batch that will follow this curriculum. ')
                            .'<a class="fi-link" href="'.e($academicYearsUrl).'">Manage academic years in Term Planning</a>.'
                        )),
                    Radio::make('creation_mode')
                        ->label('How would you like to start?')
                        ->options($modeOptions)
                        ->descriptions($modeDescriptions)
                        ->columns(['default' => 1, 'sm' => 2])
                        ->gridDirection(GridDirection::Row)
                        ->extraAttributes(['class' => 'tala-choice-options'])
                        ->required()
                        ->live()
                        ->columnSpanFull(),
                    Select::make('source_curriculum_version_id')
                        ->label('Copy subjects from')
                        ->options($sourceOptions)
                        ->visible(fn (Get $get): bool => $get('creation_mode') === 'copy' && $sourceOptions !== [])
                        ->required(fn (Get $get): bool => $get('creation_mode') === 'copy' && $sourceOptions !== [])
                        ->live(),
                    Placeholder::make('copy_immutability_notice')
                        ->hiddenLabel()
                        ->visible(fn (Get $get): bool => $get('creation_mode') === 'copy' && $sourceOptions !== [])
                        ->content(function (Get $get) use ($copyable): HtmlString {
                            $sourceId = (int) ($get('source_curriculum_version_id') ?? 0);
                            $source = $copyable->firstWhere('id', $sourceId) ?? $copyable->first();
                            $sourceLabel = $source instanceof CurriculumVersion ? $source->batchLabel() : 'the selected curriculum';

                            $message = "This creates a new, separate curriculum. All subjects from {$sourceLabel} will be copied. Changes you make will not affect the original curriculum or its students.";

                            return new HtmlString(Blade::render(
                                '<x-tala-access-alert variant="info"><p>{{ $message }}</p></x-tala-access-alert>',
                                ['message' => $message],
                            ));
                        }),
                ];
            })
            ->action(function (array $data): void {
                $actor = $this->actor();
                $program = $this->programRecord();
                abort_unless($this->canCreateCurriculum(), 403);

                $startingYearId = isset($data['starting_academic_year_id']) ? (int) $data['starting_academic_year_id'] : null;
                $startingBatch = $startingYearId ? AcademicYear::query()->find($startingYearId) : null;

                if (! $startingBatch instanceof AcademicYear) {
                    Notification::make()
                        ->title('Starting batch required')
                        ->body('Add an unassigned Academic Year before creating a new batch curriculum.')
                        ->danger()
                        ->send();

                    return;
                }

                $short = CurriculumVersion::formatBatchShort($startingBatch);
                $versionCode = $this->generateUniqueVersionCode($program, $short);
                $name = "{$program->code} Curriculum — Batch {$short}";
                $effectiveEntryTermId = $this->resolveFirstTermIdForAcademicYear($startingBatch);
                $lifecycle = app(CurriculumVersionLifecycleService::class);

                try {
                    if (($data['creation_mode'] ?? 'blank') === 'copy') {
                        $source = CurriculumVersion::query()
                            ->where('program_id', $program->id)
                            ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                            ->findOrFail((int) $data['source_curriculum_version_id']);

                        $draft = $lifecycle->copyToDraft(
                            actor: $actor,
                            source: $source,
                            versionCode: $versionCode,
                            name: $name,
                            effectiveEntryTermId: $effectiveEntryTermId,
                            startingBatch: $startingBatch,
                            activateProgramIfInactive: true,
                        );
                    } else {
                        $draft = $lifecycle->createDraft(
                            actor: $actor,
                            program: $program,
                            versionCode: $versionCode,
                            name: $name,
                            effectiveEntryTermId: $effectiveEntryTermId,
                            startingBatch: $startingBatch,
                            activateProgramIfInactive: true,
                        );
                    }
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Curriculum could not be created')
                        ->body(collect($exception->errors())->flatten()->first() ?? 'Please check the selected starting batch.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Draft curriculum created')
                    ->body($draft->batchLabel().' is ready for editing.')
                    ->success()
                    ->send();

                $this->redirect($this->reviewUrl($draft));
            });
    }

    public function activateAndContinueDraftAction(): Action
    {
        return Action::make('activateAndContinueDraft')
            ->color('primary')
            ->requiresConfirmation()
            ->authorize(fn (): bool => $this->canManage())
            ->modalHeading(fn (): string => "Activate {$this->programRecord()->code} to continue?")
            ->modalDescription(fn (): string => "{$this->programRecord()->code} is inactive. You need to activate the program before continuing a draft.")
            ->modalSubmitActionLabel('Activate and proceed')
            ->modalCancelActionLabel('Cancel')
            ->action(function (array $arguments): void {
                $program = $this->programRecord();
                $draft = CurriculumVersion::query()
                    ->where('program_id', $program->id)
                    ->where('state', CurriculumVersion::StateDraft)
                    ->findOrFail((int) ($arguments['curriculumId'] ?? 0));

                $this->activateProgramWithAudit('continue_draft_curriculum');

                $this->redirect($this->reviewUrl($draft));
            });
    }

    public function deleteDraftCurriculumAction(): Action
    {
        return Action::make('deleteDraftCurriculum')
            ->requiresConfirmation()
            ->authorize(fn (): bool => $this->canManage())
            ->color('danger')
            ->modalHeading(function (array $arguments): string {
                $draft = CurriculumVersion::query()->with('academicYears')->find((int) ($arguments['curriculumId'] ?? 0));

                return $draft instanceof CurriculumVersion
                    ? 'Delete '.$draft->batchLabel().'?'
                    : 'Delete draft curriculum?';
            })
            ->modalDescription('This draft curriculum and its draft subject placements will be permanently deleted. Active curricula and students are not affected.')
            ->modalSubmitActionLabel('Delete draft')
            ->modalCancelActionLabel('Cancel')
            ->action(function (array $arguments): void {
                $actor = $this->actor();
                $program = $this->programRecord();
                abort_unless($this->canManage(), 403);

                $draft = CurriculumVersion::query()
                    ->where('program_id', $program->id)
                    ->findOrFail((int) ($arguments['curriculumId'] ?? 0));

                try {
                    app(CurriculumVersionLifecycleService::class)->discardDraft($draft, $actor);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Draft could not be deleted')
                        ->body(collect($exception->errors())->flatten()->first() ?? 'This draft cannot be deleted.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Draft curriculum deleted')
                    ->success()
                    ->send();
            });
    }

    public function activateBeforeAddBatchAction(): Action
    {
        return Action::make('activateBeforeAddBatch')
            ->requiresConfirmation()
            ->authorize(fn (): bool => $this->canManage())
            ->modalHeading(fn (): string => "Activate {$this->programRecord()->code} to continue?")
            ->modalDescription(fn (): string => "{$this->programRecord()->code} is inactive. You need to activate the program before adding a batch.")
            ->modalSubmitActionLabel('Activate and proceed')
            ->modalCancelActionLabel('Cancel')
            ->action(function (array $arguments): void {
                $this->activateProgramWithAudit('add_curriculum_batch');
                $this->replaceMountedAction('addBatchToCurriculum', $arguments);
            });
    }

    public function addBatchToCurriculumAction(): Action
    {
        return Action::make('addBatchToCurriculum')
            ->authorize(fn (): bool => $this->canManage())
            ->modalHeading(function (array $arguments): string {
                $cv = CurriculumVersion::query()->with('academicYears')->find((int) ($arguments['curriculumId'] ?? 0));

                return $cv instanceof CurriculumVersion
                    ? 'Add batch to '.$cv->batchLabel()
                    : 'Add batch to curriculum';
            })
            ->modalDescription('Assign an additional incoming batch to use this active curriculum without duplicating its subjects.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Add batch')
            ->modalCancelActionLabel('Cancel')
            ->fillForm(function (): array {
                $suggested = app(CurriculumVersionLifecycleService::class)
                    ->suggestedNextAcademicYearForProgram($this->programRecord());

                return [
                    'academic_year_id' => $suggested?->id,
                ];
            })
            ->schema(function (): array {
                $available = app(CurriculumVersionLifecycleService::class)
                    ->availableAcademicYearsForProgram($this->programRecord());

                $options = $available
                    ->mapWithKeys(fn (AcademicYear $year): array => [
                        $year->id => 'Batch '.CurriculumVersion::formatBatchShort($year),
                    ])
                    ->all();

                $academicYearsUrl = TermOverview::getUrl(['overviewTab' => 'academic-years']);

                return [
                    Select::make('academic_year_id')
                        ->label('Batch (Academic Year)')
                        ->options($options)
                        ->native(true)
                        ->required($options !== [])
                        ->placeholder($options === [] ? 'No unassigned academic years available' : 'Select batch')
                        ->helperText(new HtmlString(
                            ($options === []
                                ? 'All configured academic years are already assigned to a curriculum for this program. '
                                : 'Only unassigned academic years are listed. ')
                            .'<a class="fi-link" href="'.e($academicYearsUrl).'">Manage academic years in Term Planning</a>.'
                        )),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $actor = $this->actor();
                $program = $this->programRecord();
                abort_unless($this->canManage(), 403);

                $curriculum = CurriculumVersion::query()
                    ->where('program_id', $program->id)
                    ->findOrFail((int) ($arguments['curriculumId'] ?? 0));

                $academicYear = AcademicYear::query()->find((int) ($data['academic_year_id'] ?? 0));

                if (! $academicYear instanceof AcademicYear) {
                    Notification::make()
                        ->title('Batch required')
                        ->body('Select an unassigned Academic Year to add.')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    app(CurriculumVersionLifecycleService::class)->addBatch(
                        actor: $actor,
                        curriculum: $curriculum,
                        academicYear: $academicYear,
                        activateProgramIfInactive: true,
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Batch could not be added')
                        ->body(collect($exception->errors())->flatten()->first() ?? 'Unable to add batch.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Batch added')
                    ->body('Batch '.CurriculumVersion::formatBatchShort($academicYear).' now uses '.$curriculum->fresh(['academicYears'])->batchLabel().'.')
                    ->success()
                    ->send();
            });
    }

    public function retireCurriculumAction(): Action
    {
        return Action::make('retireCurriculum')
            ->requiresConfirmation()
            ->authorize(fn (): bool => $this->canManage())
            ->modalHeading(function (array $arguments): string {
                $cv = CurriculumVersion::query()->with('academicYears')->find((int) ($arguments['curriculumId'] ?? 0));

                return $cv instanceof CurriculumVersion
                    ? 'Retire '.$cv->batchLabel().'?'
                    : 'Retire curriculum?';
            })
            ->modalDescription('No new batches can be added to this curriculum once retired. Existing batches and enrolled students will continue using it until they graduate.')
            ->modalSubmitActionLabel('Retire')
            ->modalCancelActionLabel('Cancel')
            ->action(function (array $arguments): void {
                $actor = $this->actor();
                $program = $this->programRecord();
                abort_unless($this->canManage(), 403);

                $curriculum = CurriculumVersion::query()
                    ->where('program_id', $program->id)
                    ->findOrFail((int) ($arguments['curriculumId'] ?? 0));

                try {
                    app(CurriculumVersionLifecycleService::class)->retire($actor, $curriculum);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Curriculum could not be retired')
                        ->body(collect($exception->errors())->flatten()->first() ?? 'Unable to retire this curriculum.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Curriculum retired')
                    ->body($curriculum->fresh(['academicYears'])->batchLabel().' is now retired for new batches.')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $program = $this->programRecord();
        $canManage = $this->canManage();
        $canCreateCurriculum = $this->canCreateCurriculum();
        $lifecycle = app(CurriculumVersionLifecycleService::class);

        $curricula = $program->curriculumVersions()
            ->with(['academicYears', 'copiedFrom.academicYears', 'entries.courseSpecification'])
            ->withCount([
                'entries',
                'batches',
                'studentProfiles as active_student_profiles_count' => fn (Builder $query): Builder => $query
                    ->whereNull('archived_at')
                    ->whereNull('merged_into_id'),
            ])
            ->orderByRaw("CASE state WHEN 'ACTIVE' THEN 1 WHEN 'DRAFT' THEN 2 WHEN 'SUPERSEDED' THEN 3 ELSE 4 END")
            ->orderByDesc('id')
            ->get();

        $program->setRelation('curriculumVersions', $curricula);

        $curriculaRows = $curricula->map(function (CurriculumVersion $cv) use ($program, $canManage, $lifecycle): array {
            $units = $cv->entries->sum(fn ($entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0));
            $unitsFormatted = rtrim(rtrim(number_format($units, 2, '.', ''), '0'), '.');
            $startShort = $cv->startBatchLabel() ? Str::after($cv->startBatchLabel(), 'Batch ') : null;
            $lastShort = $cv->lastBatchLabel() ? Str::after($cv->lastBatchLabel(), 'Batch ') : null;
            $batchesRange = ($cv->batches_count > 1 && $startShort !== null && $lastShort !== null && $startShort !== $lastShort)
                ? "{$startShort} to {$lastShort}"
                : null;

            return [
                'id' => $cv->id,
                'state' => $cv->state,
                'status_label' => CurriculumVersion::stateOptions()[$cv->state] ?? $cv->state,
                'status_color' => match ($cv->state) {
                    CurriculumVersion::StateActive => 'success',
                    CurriculumVersion::StateDraft => 'warning',
                    default => 'gray',
                },
                'batch_label' => $cv->batchLabel(),
                'copied_from_label' => $cv->copiedFrom instanceof CurriculumVersion ? $cv->copiedFrom->batchLabel() : null,
                'start_batch_label' => $cv->startBatchLabel(),
                'last_batch_label' => $cv->lastBatchLabel(),
                'batches_count' => (int) $cv->batches_count,
                'batches_range' => $batchesRange,
                'subjects_count' => (int) $cv->entries_count,
                'units_formatted' => $unitsFormatted,
                'students_count' => (int) ($cv->active_student_profiles_count ?? $cv->studentCountForBatches()),
                'review_url' => $this->reviewUrl($cv),
                'can_delete_draft' => $canManage && $cv->state === CurriculumVersion::StateDraft && $lifecycle->canDiscardDraft($cv),
                'continue_requires_activation' => $canManage && $cv->state === CurriculumVersion::StateDraft && ! $program->is_active,
            ];
        })->all();

        $allTerms = Term::query()
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        $selectedTerm = $this->resolveSelectedTerm($allTerms);
        $yearLevelRows = $selectedTerm instanceof Term
            ? $this->buildYearLevelsAndSections($program, $selectedTerm)
            : [];
        $sectionsSummary = $this->buildSectionsSummary($selectedTerm, $yearLevelRows, $canManage);

        $availability = $program->admissionsAvailability();
        $continuing = ProgramsTable::continuingBatchAndStudentCounts($program);
        $officialEnrolledCount = $program->officialEnrolledStudentsCount($selectedTerm?->id);
        $activeBatchStudents = collect($curriculaRows)
            ->where('state', CurriculumVersion::StateActive)
            ->sum('students_count');

        return [
            'program' => $program,
            'readOnly' => $this->isReadOnly(),
            'canManage' => $canManage,
            'canCreateCurriculum' => $canCreateCurriculum,
            'activeTab' => $this->activeTab,
            'availability' => $availability,
            'continuingBatches' => $continuing['batches'],
            'continuingStudents' => $continuing['students'],
            'officialEnrolledCount' => $officialEnrolledCount,
            'activeBatchStudents' => (int) $activeBatchStudents,
            'curriculaRows' => $curriculaRows,
            'curriculaCount' => count($curriculaRows),
            'activeCurriculaCount' => $curricula->where('state', CurriculumVersion::StateActive)->count(),
            'draftCurriculaCount' => $curricula->where('state', CurriculumVersion::StateDraft)->count(),
            'availableBatchYearsCount' => $lifecycle->availableAcademicYearsForProgram($program)->count(),
            'terms' => $allTerms,
            'selectedTerm' => $selectedTerm,
            'yearLevelRows' => $yearLevelRows,
            'yearLevelsCount' => count($yearLevelRows),
            'sectionsSummary' => $sectionsSummary,
        ];
    }

    /**
     * Build Tab 2 (Year Levels & Sections) strictly from live TermCohort, Official Enrollment, and CurriculumEntry.year_level records.
     *
     * @return list<array<string, mixed>>
     */
    private function buildYearLevelsAndSections(Program $program, Term $term): array
    {
        $cohorts = TermCohort::query()
            ->where('program_id', $program->id)
            ->where('term_id', $term->id)
            ->with([
                'curriculumVersion.academicYears',
                'classOfferings.termOffering.curriculumEntry',
                'enrollments' => fn ($q) => $q
                    ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
                    ->with(['studentProfile.entryTerm.academicYear']),
            ])
            ->orderBy('reference')
            ->get();

        // Also inspect officially enrolled students for this program & term who have no TermCohort yet.
        $unassignedEnrollments = Enrollment::query()
            ->where('term_id', $term->id)
            ->where('canonical_outcome', Enrollment::OutcomeOfficiallyEnrolled)
            ->whereNull('student_section_id')
            ->whereHas('studentProfile', fn ($q) => $q->where('program_id', $program->id))
            ->with([
                'studentProfile.entryTerm.academicYear',
                'studentProfile.curriculumVersion.academicYears',
                'courseEnrollments.termOffering.curriculumEntry',
            ])
            ->get();

        // Precompute schedule statuses per Section ID for the selected term (2 compact queries, zero N+1).
        $allSectionIds = $cohorts
            ->flatMap(fn (TermCohort $cohort) => $cohort->classOfferings->pluck('id'))
            ->unique()
            ->values()
            ->all();

        $scheduleLookup = $this->resolveSectionScheduleStatusLookup($term->id, $allSectionIds);

        /** @var array<int, array{cohorts: list<TermCohort>, unassigned_enrollments: list<Enrollment>}> $grouped */
        $grouped = [];

        foreach ($cohorts as $cohort) {
            $yearLevel = $cohort->resolvedYearLevel() ?? 0;
            $grouped[$yearLevel] ??= ['cohorts' => [], 'unassigned_enrollments' => []];
            $grouped[$yearLevel]['cohorts'][] = $cohort;
        }

        foreach ($unassignedEnrollments as $enrollment) {
            $yearLevel = 0;

            foreach ($enrollment->courseEnrollments as $courseEnrollment) {
                $parsed = TermCohort::parseYearLevelValue($courseEnrollment->termOffering?->curriculumEntry?->year_level);

                if ($parsed !== null) {
                    $yearLevel = $parsed;
                    break;
                }
            }

            $grouped[$yearLevel] ??= ['cohorts' => [], 'unassigned_enrollments' => []];
            $grouped[$yearLevel]['unassigned_enrollments'][] = $enrollment;
        }

        ksort($grouped);

        $rows = [];

        foreach ($grouped as $yearLevel => $bucket) {
            /** @var list<TermCohort> $yearCohorts */
            $yearCohorts = $bucket['cohorts'];
            /** @var list<Enrollment> $yearUnassigned */
            $yearUnassigned = $bucket['unassigned_enrollments'];

            $allYearEnrollments = collect($yearCohorts)
                ->flatMap(fn (TermCohort $cohort) => $cohort->enrollments)
                ->concat($yearUnassigned);

            // Derive primary batch and off-batch / irregular student pills from StudentProfile.entryTerm.academicYear.
            $batchBreakdown = $this->resolveBatchBreakdown($allYearEnrollments);

            // Distinct curricula used by cohorts (or unassigned student profiles) in this year level.
            $curriculaUsed = collect($yearCohorts)
                ->map(fn (TermCohort $cohort): ?CurriculumVersion => $cohort->curriculumVersion)
                ->concat(collect($yearUnassigned)->map(fn (Enrollment $e): ?CurriculumVersion => $e->studentProfile?->curriculumVersion))
                ->filter(fn (?CurriculumVersion $cv): bool => $cv instanceof CurriculumVersion)
                ->unique('id')
                ->values();

            $primaryBatch = $batchBreakdown['primary'];
            $batchIsPlanned = false;

            if ($primaryBatch === null) {
                $primaryBatch = $this->resolveFallbackBatchLabel($curriculaUsed, $term, $yearLevel, $yearCohorts !== []);
                $batchIsPlanned = $primaryBatch !== null;
            }

            $sectionRows = [];
            $publishedCount = 0;
            $draftCount = 0;

            foreach ($yearCohorts as $cohort) {
                $sectionIds = $cohort->classOfferings->pluck('id')->all();
                $scheduleStatus = $this->cohortScheduleStatus($sectionIds, $scheduleLookup);

                if ($scheduleStatus === 'Published') {
                    $publishedCount++;
                } elseif ($scheduleStatus === 'Draft') {
                    $draftCount++;
                }

                $enrolled = $cohort->enrollments->count();
                $planned = $cohort->confirmed_count ?? $cohort->forecast_count;

                $sectionRows[] = [
                    'id' => $cohort->id,
                    'reference' => (string) $cohort->reference,
                    'enrolled_count' => $enrolled,
                    'planned_size' => $planned !== null ? (int) $planned : null,
                    'subjects_count' => $cohort->classOfferings->count(),
                    'schedule_status' => $scheduleStatus,
                    'schedule_color' => match ($scheduleStatus) {
                        'Published' => 'success',
                        'Draft', 'No schedule yet' => 'warning',
                        default => 'warning',
                    },
                    'timetable_url' => ($scheduleStatus === 'Published' && TermTimetable::canAccess())
                        ? TermTimetable::getUrl([
                            'termId' => $term->id,
                            'timetableState' => 'official',
                            'timetableView' => 'section',
                            'viewFilter' => (string) $cohort->id,
                        ])
                        : null,
                ];
            }

            $cohortCount = count($yearCohorts);
            $studentsCount = $allYearEnrollments->count();

            $yearStatus = match (true) {
                $cohortCount === 0 => ['label' => 'Needs setup', 'color' => 'warning'],
                $publishedCount === $cohortCount => ['label' => 'Active', 'color' => 'success'],
                $studentsCount > 0 || $draftCount > 0 || $publishedCount > 0 => ['label' => 'Enrolling', 'color' => 'info'],
                default => ['label' => 'Planned', 'color' => 'info'],
            };

            $rows[] = [
                'year_key' => $yearLevel,
                'year_label' => $yearLevel > 0 ? $this->shortOrdinalYearLabel($yearLevel) : 'Unassigned year',
                'primary_batch' => $primaryBatch,
                'batch_is_planned' => $batchIsPlanned,
                'off_batches' => $batchBreakdown['off_batches'],
                'curricula' => $curriculaUsed->map(fn (CurriculumVersion $cv): array => [
                    'id' => $cv->id,
                    'label' => $cv->batchLabel(),
                    'url' => $this->reviewUrl($cv),
                ])->all(),
                'sections_count' => $cohortCount,
                'students_count' => $studentsCount,
                'unassigned_students_count' => count($yearUnassigned),
                'status_label' => $yearStatus['label'],
                'status_color' => $yearStatus['color'],
                'sections' => $sectionRows,
            ];
        }

        return $rows;
    }

    /**
     * Derive a clean fallback batch label from the linked curriculum's assigned batches for the selected term's Academic Year.
     *
     * @param  Collection<int, CurriculumVersion>  $curriculaUsed
     */
    private function resolveFallbackBatchLabel(Collection $curriculaUsed, Term $term, int $yearLevel, bool $hasCohorts): ?string
    {
        $termAcademicYear = $term->academicYear;
        $termStartYear = $termAcademicYear instanceof AcademicYear
            ? CurriculumVersion::extractAcademicYearStart($termAcademicYear)
            : null;
        $expectedEntryYear = ($termStartYear !== null && $yearLevel > 0)
            ? $termStartYear - ($yearLevel - 1)
            : $termStartYear;

        $assignedYears = $curriculaUsed
            ->flatMap(fn (CurriculumVersion $cv): Collection => $cv->academicYears)
            ->filter(fn (mixed $year): bool => $year instanceof AcademicYear)
            ->unique('id')
            ->sortBy(fn (AcademicYear $year): array => [
                CurriculumVersion::extractAcademicYearStart($year) ?? PHP_INT_MAX,
                $year->starts_on?->timestamp ?? PHP_INT_MAX,
                (int) $year->id,
            ])
            ->values();

        if ($assignedYears->isNotEmpty()) {
            $matched = null;

            if ($expectedEntryYear !== null) {
                $matched = $assignedYears->first(
                    fn (AcademicYear $year): bool => CurriculumVersion::extractAcademicYearStart($year) === $expectedEntryYear,
                );
            }

            if (! $matched instanceof AcademicYear && $termAcademicYear instanceof AcademicYear) {
                $matched = $assignedYears->firstWhere('id', $termAcademicYear->id);
            }

            if (! $matched instanceof AcademicYear && $termStartYear !== null) {
                $matched = $assignedYears
                    ->filter(function (AcademicYear $year) use ($termStartYear): bool {
                        $start = CurriculumVersion::extractAcademicYearStart($year);

                        return $start !== null && $start <= $termStartYear;
                    })
                    ->last();
            }

            $chosen = $matched instanceof AcademicYear ? $matched : $assignedYears->last();

            if ($chosen instanceof AcademicYear) {
                return 'Batch '.CurriculumVersion::formatBatchShort($chosen);
            }
        }

        if ($hasCohorts && $termAcademicYear instanceof AcademicYear) {
            return 'Batch '.CurriculumVersion::formatBatchShort($termAcademicYear);
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $yearLevelRows
     * @return array{
     *     year_levels_count: int,
     *     sections_count: int,
     *     enrolled_count: int,
     *     planned_capacity: int,
     *     has_planned_capacity: bool,
     *     published_sections_count: int,
     *     unscheduled_sections_count: int,
     *     term_planning_url: ?string,
     *     schedule_url: ?string,
     * }
     */
    private function buildSectionsSummary(?Term $selectedTerm, array $yearLevelRows, bool $canManage): array
    {
        $sectionsCount = 0;
        $enrolledCount = 0;
        $plannedCapacity = 0;
        $hasPlannedCapacity = false;
        $publishedSectionsCount = 0;
        $unscheduledSectionsCount = 0;

        foreach ($yearLevelRows as $yearRow) {
            $enrolledCount += (int) $yearRow['students_count'];

            foreach ($yearRow['sections'] as $section) {
                $sectionsCount++;

                if ($section['planned_size'] !== null) {
                    $plannedCapacity += (int) $section['planned_size'];
                    $hasPlannedCapacity = true;
                }

                if ($section['schedule_status'] === 'Published') {
                    $publishedSectionsCount++;
                } elseif ($section['schedule_status'] === 'No schedule yet') {
                    $unscheduledSectionsCount++;
                }
            }
        }

        $termPlanningUrl = ($selectedTerm instanceof Term && $canManage)
            ? TermClasses::getUrl(['termId' => $selectedTerm->id, 'programId' => $this->record->id])
            : null;
        $scheduleUrl = ($selectedTerm instanceof Term && $canManage)
            ? TermTimetable::getUrl(['termId' => $selectedTerm->id])
            : null;

        return [
            'year_levels_count' => count($yearLevelRows),
            'sections_count' => $sectionsCount,
            'enrolled_count' => $enrolledCount,
            'planned_capacity' => $plannedCapacity,
            'has_planned_capacity' => $hasPlannedCapacity,
            'published_sections_count' => $publishedSectionsCount,
            'unscheduled_sections_count' => $unscheduledSectionsCount,
            'term_planning_url' => $termPlanningUrl,
            'schedule_url' => $scheduleUrl,
        ];
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @return array{primary: ?string, off_batches: list<array{count: int, label: string}>}
     */
    private function resolveBatchBreakdown(Collection $enrollments): array
    {
        /** @var array<int, array{label: string, count: int, sort: int}> $counts */
        $counts = [];

        foreach ($enrollments as $enrollment) {
            $academicYear = $enrollment->studentProfile?->entryTerm?->academicYear;

            if (! $academicYear instanceof AcademicYear) {
                continue;
            }

            $ayId = (int) $academicYear->id;

            if (! isset($counts[$ayId])) {
                $counts[$ayId] = [
                    'label' => 'Batch '.CurriculumVersion::formatBatchShort($academicYear),
                    'count' => 0,
                    'sort' => CurriculumVersion::extractAcademicYearStart($academicYear) ?? $ayId,
                ];
            }

            $counts[$ayId]['count']++;
        }

        if ($counts === []) {
            return ['primary' => null, 'off_batches' => []];
        }

        uasort($counts, fn (array $a, array $b): int => [$b['count'], $b['sort']] <=> [$a['count'], $a['sort']]);
        $sorted = array_values($counts);
        $primary = array_shift($sorted);

        $offBatches = array_map(
            fn (array $item): array => [
                'count' => $item['count'],
                'label' => $item['label'],
            ],
            $sorted,
        );

        return [
            'primary' => $primary['label'],
            'off_batches' => $offBatches,
        ];
    }

    /**
     * Bulk-resolve which Section IDs have Published meetings vs Draft candidate rows in the selected term.
     *
     * @param  list<int>  $sectionIds
     * @return array{published: array<int, true>, draft: array<int, true>}
     */
    private function resolveSectionScheduleStatusLookup(int $termId, array $sectionIds): array
    {
        if ($sectionIds === []) {
            return ['published' => [], 'draft' => []];
        }

        $publishedSectionIds = PublishedTimetableMeeting::query()
            ->whereIn('section_id', $sectionIds)
            ->whereHas('timetableVersion', fn ($q) => $q
                ->where('term_id', $termId)
                ->where('state', PublishedTimetableVersion::StatePublished))
            ->distinct()
            ->pluck('section_id')
            ->all();

        $officialMeetingSectionIds = SectionMeeting::query()
            ->activeOfficial()
            ->whereHas('scheduleRun', fn ($q) => $q->where('term_id', $termId))
            ->whereHas('schedulingDemand.sectionDeliveryGroup', fn ($q) => $q->whereIn('section_id', $sectionIds))
            ->with('schedulingDemand.sectionDeliveryGroup:id,section_id')
            ->get()
            ->map(fn (SectionMeeting $meeting): ?int => $meeting->schedulingDemand?->sectionDeliveryGroup?->section_id)
            ->filter()
            ->unique()
            ->all();

        $publishedMap = [];

        foreach (array_merge($publishedSectionIds, $officialMeetingSectionIds) as $id) {
            $publishedMap[(int) $id] = true;
        }

        $draftSectionIds = CandidateScheduleRow::query()
            ->whereHas('scheduleRun', fn ($q) => $q
                ->where('term_id', $termId)
                ->whereIn('status', [
                    ScheduleGenerationRun::StatusQueued,
                    ScheduleGenerationRun::StatusDispatching,
                    ScheduleGenerationRun::StatusUnderReview,
                ]))
            ->whereHas('schedulingDemand.sectionDeliveryGroup', fn ($q) => $q->whereIn('section_id', $sectionIds))
            ->with('schedulingDemand.sectionDeliveryGroup:id,section_id')
            ->get()
            ->map(fn (CandidateScheduleRow $row): ?int => $row->schedulingDemand?->sectionDeliveryGroup?->section_id)
            ->filter()
            ->unique()
            ->all();

        $draftMap = [];

        foreach ($draftSectionIds as $id) {
            $draftMap[(int) $id] = true;
        }

        return [
            'published' => $publishedMap,
            'draft' => $draftMap,
        ];
    }

    /**
     * @param  list<int>  $sectionIds
     * @param  array{published: array<int, true>, draft: array<int, true>}  $lookup
     */
    private function cohortScheduleStatus(array $sectionIds, array $lookup): string
    {
        if ($sectionIds === []) {
            return 'No schedule yet';
        }

        $hasPublished = false;
        $hasDraft = false;

        foreach ($sectionIds as $sectionId) {
            if (isset($lookup['published'][$sectionId])) {
                $hasPublished = true;
            } elseif (isset($lookup['draft'][$sectionId])) {
                $hasDraft = true;
            }
        }

        if ($hasPublished) {
            return 'Published';
        }

        if ($hasDraft) {
            return 'Draft';
        }

        return 'No schedule yet';
    }

    private function deactivationConfirmationCopy(Program $program): string
    {
        $blockReason = ProgramsTable::deactivationBlockReason($program);

        if ($blockReason !== null) {
            return $blockReason;
        }

        $continuing = ProgramsTable::continuingBatchAndStudentCounts($program);

        if ($continuing['students'] > 0) {
            return sprintf(
                'Deactivate %s? New batches and admissions will stop. The %d current %s (%d %s) will continue until they finish.',
                $program->code,
                $continuing['batches'],
                Str::plural('batch', $continuing['batches']),
                $continuing['students'],
                Str::plural('student', $continuing['students']),
            );
        }

        return "Deactivate {$program->code}? New batches and admissions will stop. No currently enrolled students are affected.";
    }

    private function activateProgramWithAudit(string $reason): void
    {
        $actor = $this->actor();
        $program = $this->programRecord();
        abort_unless($this->canManage(), 403);

        if (! $program->is_active) {
            $program->update(['is_active' => true]);
            $this->record = $program->fresh();

            activity()
                ->performedOn($program)
                ->causedBy($actor)
                ->event('program_reactivated')
                ->withProperties([
                    'program_code' => $program->code,
                    'program_name' => $program->name,
                    'reason' => $reason,
                ])
                ->log('Program activated via Activate and proceed');

            Notification::make()
                ->title("{$program->code} activated")
                ->success()
                ->send();
        }
    }

    /**
     * @return Collection<int, CurriculumVersion>
     */
    private function copyableCurricula(Program $program): Collection
    {
        return $program->curriculumVersions()
            ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
            ->with('academicYears')
            ->withCount('entries')
            ->orderByRaw("CASE state WHEN 'ACTIVE' THEN 1 WHEN 'SUPERSEDED' THEN 2 ELSE 3 END")
            ->orderByDesc('id')
            ->get();
    }

    private function generateUniqueVersionCode(Program $program, string $batchShort): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $batchShort) ?: (string) now()->year;
        $base = strtoupper(trim((string) $program->code)).'-'.trim($slug, '-');
        $candidate = $base;
        $suffix = 2;

        while (CurriculumVersion::query()
            ->where('program_id', $program->id)
            ->where('version_code', $candidate)
            ->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function resolveFirstTermIdForAcademicYear(AcademicYear $academicYear): ?int
    {
        $firstSem = Term::query()
            ->where('academic_year_id', $academicYear->id)
            ->where('type', Term::TypeFirstSemester)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->value('id');

        if ($firstSem !== null) {
            return (int) $firstSem;
        }

        $earliest = Term::query()
            ->where('academic_year_id', $academicYear->id)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->value('id');

        return $earliest !== null ? (int) $earliest : null;
    }

    /**
     * @param  Collection<int, Term>|null  $terms
     */
    private function resolveSelectedTerm(?Collection $terms = null): ?Term
    {
        if ($this->selectedTermId !== null) {
            $selected = $terms instanceof Collection
                ? $terms->firstWhere('id', $this->selectedTermId)
                : Term::query()->with('academicYear')->find($this->selectedTermId);

            if ($selected instanceof Term) {
                return $selected;
            }
        }

        if ($terms instanceof Collection) {
            return $terms->firstWhere('state', Term::StateActive) ?? $terms->first();
        }

        return Term::query()
            ->where('state', Term::StateActive)
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first()
            ?? Term::query()
                ->with('academicYear')
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->first();
    }

    private function shortOrdinalYearLabel(int $year): string
    {
        return match ($year) {
            1 => '1st Year',
            2 => '2nd Year',
            3 => '3rd Year',
            default => "{$year}th Year",
        };
    }

    private function reviewUrl(CurriculumVersion $record): string
    {
        return CurriculumVersionResource::getUrl('review', array_filter([
            'record' => $record->id,
            'programId' => $this->programRecord()->id,
            'oversightTermId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null));
    }

    private function programRecord(): Program
    {
        $program = $this->getRecord();
        abort_unless($program instanceof Program, 404);

        return $program;
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function isReadOnly(): bool
    {
        return ! $this->isRegistrarActor();
    }

    private function canManage(): bool
    {
        return ! $this->isReadOnly() && (auth()->user()?->can('update', $this->programRecord()) ?? false);
    }

    private function canCreateCurriculum(): bool
    {
        return ! $this->isReadOnly() && (auth()->user()?->can('create', CurriculumVersion::class) ?? false);
    }
}
