<?php

namespace App\Filament\Resources\CurriculumVersions\Schemas;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Models\AcademicYear;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\GridDirection;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CurriculumVersionForm
{
    public static function configure(Schema $schema, ?int $programId = null): Schema
    {
        return $schema->components([
            Grid::make(1)
                ->schema([
                    $programId !== null
                        ? Hidden::make('program_id')->default($programId)
                        : Select::make('program_id')
                            ->label('Program')
                            ->options(fn (): array => self::programOptions())
                            ->default(fn (): ?int => request()->integer('programId') ?: null)
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $program = filled($state) ? Program::query()->find((int) $state) : null;
                                $suggested = $program instanceof Program
                                    ? app(CurriculumVersionLifecycleService::class)->suggestedNextAcademicYearForProgram($program)
                                    : null;

                                $set('starting_academic_year_id', $suggested?->id);
                                $set('source_curriculum_version_id', null);
                                $set('activate_program_if_inactive', false);
                            })
                            ->required(),
                    Radio::make('creation_mode')
                        ->label('How do you want to start?')
                        ->options([
                            'blank' => 'Start blank',
                            'copy' => 'Copy from existing curriculum',
                        ])
                        ->descriptions([
                            'blank' => 'Build a new curriculum from scratch across year levels and semesters.',
                            'copy' => 'Start with all subjects from an existing Active or Retired curriculum.',
                        ])
                        ->default('blank')
                        ->columns(['default' => 1, 'sm' => 2])
                        ->gridDirection(GridDirection::Row)
                        ->extraAttributes(['class' => 'tala-choice-options'])
                        ->live()
                        ->required()
                        ->columnSpanFull(),
                    Select::make('source_curriculum_version_id')
                        ->label('Copy from curriculum')
                        ->options(fn (Get $get): array => self::copyableCurriculumOptions(
                            self::resolveProgramId($programId, $get('program_id')),
                        ))
                        ->placeholder('Select an Active or Retired curriculum')
                        ->helperText('Choose any Active or Retired curriculum from this program.')
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => $get('creation_mode') === 'copy')
                        ->required(fn (Get $get): bool => $get('creation_mode') === 'copy'),
                    Text::make('Changes you make in this new draft will not affect the original curriculum or its assigned students.')
                        ->color('info')
                        ->visible(fn (Get $get): bool => $get('creation_mode') === 'copy'),
                    self::applyInlineAcademicYearCreation(
                        Select::make('starting_academic_year_id')
                            ->label('Starting batch (Academic Year)')
                            ->options(fn (Get $get): array => self::availableBatchOptions(
                                self::resolveProgramId($programId, $get('program_id')),
                            ))
                            ->default(fn (Get $get): ?int => self::suggestedBatchId(
                                self::resolveProgramId($programId, $get('program_id')),
                            ))
                            ->placeholder('Select starting Academic Year batch')
                            ->helperText('Each Academic Year batch can only belong to one curriculum per program. Use + to add a new academic year.')
                            ->native(true)
                            ->nullable()
                            ->live(),
                    ),
                    Text::make(fn (Get $get): string => self::inactiveProgramNotice(
                        self::resolveProgramId($programId, $get('program_id')),
                    ))
                        ->color('warning')
                        ->visible(fn (Get $get): bool => self::isProgramInactive(
                            self::resolveProgramId($programId, $get('program_id')),
                        )),
                    Checkbox::make('activate_program_if_inactive')
                        ->label('Activate program and proceed')
                        ->helperText('Activates this program now so the new batch curriculum can be assigned and offered in Admissions.')
                        ->default(false)
                        ->live()
                        ->visible(fn (Get $get): bool => self::isProgramInactive(
                            self::resolveProgramId($programId, $get('program_id')),
                        ))
                        ->accepted(fn (Get $get): bool => self::isProgramInactive(
                            self::resolveProgramId($programId, $get('program_id')),
                        )),
                    TextInput::make('name')
                        ->label('Curriculum label (optional when a starting batch is chosen)')
                        ->placeholder('e.g. Batch 2027-28 or BSIT Revised Curriculum')
                        ->helperText('Leave blank to auto-label from the selected starting batch.')
                        ->required(fn (Get $get): bool => blank($get('starting_academic_year_id')))
                        ->maxLength(255),
                    Select::make('effective_entry_term_id')
                        ->label('First student intake (optional)')
                        ->helperText('Optional starting term override (separate from starting batch). Class dates are managed in Term Planning.')
                        ->options(fn (): array => self::termOptions())
                        ->searchable()
                        ->preload()
                        ->nullable(),
                ])
                ->columnSpanFull(),
        ]);
    }

    /** @return array<TextInput|Select> */
    public static function planFields(): array
    {
        return [
            TextInput::make('name')
                ->label('Curriculum name')
                ->placeholder('e.g. BSIT Curriculum · Batch 2026')
                ->required()
                ->maxLength(255),
            Select::make('effective_entry_term_id')->label('First student intake')
                ->helperText('New students starting from this intake will use the plan. Class dates are set in Term Planning.')
                ->options(fn (): array => self::termOptions())->searchable()->preload()->nullable(),
        ];
    }

    /**
     * Schema fields for copying an existing curriculum into a new Draft with batch & inactive-program support.
     *
     * @return list<Component>
     */
    public static function copyFields(CurriculumVersion $source): array
    {
        $source->loadMissing('program');
        $program = $source->program;
        $isInactive = $program instanceof Program && ! $program->is_active;

        return [
            Text::make('Changes you make in the new draft will not affect the original curriculum or its assigned students.')
                ->color('info'),
            self::applyInlineAcademicYearCreation(
                Select::make('starting_academic_year_id')
                    ->label('Starting batch (Academic Year)')
                    ->options(fn (): array => self::availableBatchOptions($source->program_id))
                    ->placeholder('Select starting Academic Year batch')
                    ->helperText('Assigns the new draft to an unassigned Academic Year batch for this program. Use + to add a new academic year.')
                    ->native(true)
                    ->nullable()
                    ->live(),
            ),
            Text::make(fn (): string => self::inactiveProgramNotice($source->program_id))
                ->color('warning')
                ->visible($isInactive),
            Checkbox::make('activate_program_if_inactive')
                ->label('Activate program and proceed')
                ->helperText('Activates this program now so the copied curriculum can receive its starting batch.')
                ->default(false)
                ->visible($isInactive)
                ->accepted(fn (): bool => $isInactive),
            TextInput::make('name')
                ->label('Curriculum label (optional when a starting batch is chosen)')
                ->placeholder('Leave blank to auto-label from the starting batch')
                ->required(fn (Get $get): bool => blank($get('starting_academic_year_id')))
                ->maxLength(255),
            Select::make('effective_entry_term_id')
                ->label('First student intake (optional)')
                ->helperText('Optional starting term override (separate from starting batch). Class dates are set in Term Planning.')
                ->options(fn (): array => self::termOptions())
                ->searchable()
                ->preload()
                ->nullable(),
        ];
    }

    /**
     * Schema fields for assigning an Academic Year batch to a Draft or Active curriculum.
     *
     * @return list<Component>
     */
    public static function addBatchFields(CurriculumVersion $curriculum): array
    {
        $curriculum->loadMissing('program');
        $program = $curriculum->program;
        $isInactive = $program instanceof Program && ! $program->is_active;

        return [
            self::applyInlineAcademicYearCreation(
                Select::make('academic_year_id')
                    ->label('Batch (Academic Year)')
                    ->options(fn (): array => self::availableBatchOptions($curriculum->program_id))
                    ->placeholder('Select an unassigned Academic Year')
                    ->helperText('Only Academic Years not yet assigned to any curriculum for this program are listed. Use + to add a new academic year.')
                    ->native(true)
                    ->required(),
            ),
            Text::make(fn (): string => self::inactiveProgramNotice($curriculum->program_id))
                ->color('warning')
                ->visible($isInactive),
            Checkbox::make('activate_program_if_inactive')
                ->label('Activate program and proceed')
                ->helperText('Activates this program so the assigned batch becomes eligible in Admissions.')
                ->default(false)
                ->visible($isInactive)
                ->accepted($isInactive),
        ];
    }

    /**
     * Attach inline "+ Add academic year" creation directly onto a batch Select field.
     */
    public static function applyInlineAcademicYearCreation(Select $select): Select
    {
        return $select
            ->createOptionForm(self::inlineAcademicYearFormSchema())
            ->createOptionAction(fn (Action $action): Action => $action
                ->label('Add academic year')
                ->tooltip('Add academic year')
                ->modalHeading('Add academic year')
                ->modalDescription('Create an academic year so it can be assigned as a curriculum batch right away.')
                ->modalSubmitActionLabel('Create academic year')
                ->modalCancelActionLabel('Cancel')
                ->modalWidth(Width::Large)
                ->authorize(fn (): bool => auth()->user()?->can('create', AcademicYear::class) ?? false)
                ->visible(fn (): bool => auth()->user()?->can('create', AcademicYear::class) ?? false))
            ->createOptionUsing(function (array $data): int {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                Gate::forUser($actor)->authorize('create', AcademicYear::class);

                $academicYear = AcademicYear::query()->create([
                    'label' => trim((string) $data['label']),
                    'starts_on' => $data['starts_on'],
                    'ends_on' => $data['ends_on'],
                    'state' => (string) ($data['state'] ?? AcademicYear::StateDraft),
                ]);

                Notification::make()
                    ->title('Academic year created')
                    ->body("{$academicYear->label} is now available and selected for batch assignment.")
                    ->success()
                    ->send();

                return (int) $academicYear->id;
            });
    }

    /**
     * @return list<Component>
     */
    public static function inlineAcademicYearFormSchema(): array
    {
        $stateOptions = AcademicYear::stateOptions();

        return [
            TextInput::make('label')
                ->label('Academic year')
                ->placeholder('e.g., AY 2027-2028')
                ->helperText('Use the school year label, for example AY 2027-2028.')
                ->required()
                ->maxLength(255)
                ->unique((new AcademicYear)->getTable(), 'label', ignoreRecord: false),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                DatePicker::make('starts_on')
                    ->label('Start date')
                    ->required(),
                DatePicker::make('ends_on')
                    ->label('End date')
                    ->required()
                    ->after('starts_on'),
            ]),
            Select::make('state')
                ->label('Status')
                ->options([
                    AcademicYear::StateDraft => $stateOptions[AcademicYear::StateDraft] ?? 'Draft',
                    AcademicYear::StateActive => $stateOptions[AcademicYear::StateActive] ?? 'Active',
                ])
                ->default(AcademicYear::StateDraft)
                ->required()
                ->helperText('Use Draft for upcoming school years or Active for the current school year.'),
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function draftAttributes(array $data): array
    {
        return [
            ...$data,
            'version_code' => self::draftCodeFor((int) $data['program_id']),
            'state' => CurriculumVersion::StateDraft,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public static function draftCodeFor(int $programId): string
    {
        $program = Program::query()->findOrFail($programId);

        return Str::limit($program->code, 64, '').'-'.Str::ulid();
    }

    public static function suggestedBatchId(?int $programId): ?int
    {
        if ($programId === null) {
            return null;
        }

        $program = Program::query()->find($programId);

        if (! $program instanceof Program) {
            return null;
        }

        return app(CurriculumVersionLifecycleService::class)
            ->suggestedNextAcademicYearForProgram($program)
            ?->id;
    }

    /**
     * @return array<int, string>
     */
    public static function availableBatchOptions(?int $programId): array
    {
        if ($programId === null) {
            return [];
        }

        $program = Program::query()->find($programId);

        if (! $program instanceof Program) {
            return [];
        }

        return app(CurriculumVersionLifecycleService::class)
            ->availableAcademicYearsForProgram($program)
            ->mapWithKeys(fn (AcademicYear $academicYear): array => [
                $academicYear->id => 'Batch '.CurriculumVersion::formatBatchShort($academicYear),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function copyableCurriculumOptions(?int $programId): array
    {
        if ($programId === null) {
            return [];
        }

        return CurriculumVersion::query()
            ->where('program_id', $programId)
            ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
            ->with('batches.academicYear')
            ->withCount('entries')
            ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(function (CurriculumVersion $curriculum): array {
                $status = CurriculumVersion::stateOptions()[$curriculum->state] ?? $curriculum->state;
                $subjects = $curriculum->entries_count.' '.Str::plural('subject', (int) $curriculum->entries_count);

                return [
                    $curriculum->id => "{$curriculum->batchLabel()} · {$status} ({$subjects})",
                ];
            })
            ->all();
    }

    public static function isProgramInactive(?int $programId): bool
    {
        if ($programId === null) {
            return false;
        }

        $program = Program::query()->find($programId);

        return $program instanceof Program && ! $program->is_active;
    }

    public static function inactiveProgramNotice(?int $programId): string
    {
        if ($programId === null) {
            return '';
        }

        $program = Program::query()->find($programId);

        if (! $program instanceof Program || $program->is_active) {
            return '';
        }

        return "{$program->code} · {$program->name} is currently inactive. Activate the program to proceed with batch assignment.";
    }

    private static function resolveProgramId(?int $fixedProgramId, mixed $selectedProgramId): ?int
    {
        if ($fixedProgramId !== null) {
            return $fixedProgramId;
        }

        return filled($selectedProgramId) ? (int) $selectedProgramId : null;
    }

    /**
     * @return array<int, string>
     */
    private static function programOptions(): array
    {
        return Program::query()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Program $program): array => [
                $program->id => $program->is_active
                    ? "{$program->code} - {$program->name}"
                    : "{$program->code} - {$program->name} (Inactive)",
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function termOptions(): array
    {
        return Term::query()
            ->with('academicYear')
            ->where('state', '!=', Term::StateClosed)
            ->orderByDesc('starts_on')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (Term $term): array => [
                $term->id => collect([
                    $term->academicYear?->label,
                    $term->label,
                ])->filter()->implode(' · '),
            ])
            ->all();
    }
}
