<?php

namespace App\Filament\Resources\TermOfferings\Pages;

use App\Actions\Registrar\BuildTermOfferings;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Section as OfferingSection;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class ListTermOfferings extends ListRecords
{
    use HasTermPlanningContext;

    protected static string $resource = TermOfferingResource::class;

    #[Url]
    public ?int $programId = null;

    #[Url]
    public ?int $curriculumVersionId = null;

    #[Url]
    public ?string $yearLevel = null;

    public function getTitle(): string
    {
        return 'Offered courses';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->regularBuilderAction(),
            $this->specialOfferingAction(),
        ];
    }

    private function regularBuilderAction(): Action
    {
        return Action::make('buildRegularOfferings')
            ->label('Set up a year level')->slideOver()
            ->modalHeading('Build regular offerings and classes')
            ->modalDescription('Choose one curriculum scope, review its eligible courses and confirm their class setup. Build saves the selected offerings, class sections and delivery groups together. To prepare a single class section for an existing offering, return to Classes and use New class section.')
            ->modalSubmitActionLabel('Build selected offerings and classes')
            ->authorize(fn (): bool => auth()->user()?->can('create', TermOffering::class) ?? false)
            ->fillForm(fn (): array => ['term_id' => $this->termId, 'program_id' => $this->programId, 'curriculum_version_id' => $this->curriculumVersionId, 'year_level' => $this->yearLevel, 'rows' => []])
            ->schema([
                Section::make('Choose the curriculum scope')
                    ->description('Choose the program, active curriculum and year level. Eligible courses appear below after the scope is complete.')
                    ->columns(2)
                    ->schema([
                        Select::make('term_id')
                            ->label('Target Term')
                            ->default(fn (): ?int => $this->termId)
                            ->disabled(fn (): bool => $this->termId !== null)
                            ->dehydrated()
                            ->options(fn (): array => Term::query()->latest('starts_on')->pluck('label', 'id')->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set, BuildTermOfferings $builder) => $this->loadEligibleRows($get, $set, $builder)),
                        Select::make('program_id')
                            ->label('Program')
                            ->default(fn (): ?int => $this->programId)->disabled(fn (): bool => $this->programId !== null)->dehydrated()
                            ->options(fn (): array => Program::query()->where('is_active', true)->orderBy('code')->pluck('code', 'id')->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, BuildTermOfferings $builder): void {
                                $set('curriculum_version_id', null);
                                $set('rows', []);
                                $this->loadEligibleRows($get, $set, $builder);
                            }),
                        Select::make('curriculum_version_id')
                            ->label('Active Curriculum Version')
                            ->default(fn (): ?int => $this->curriculumVersionId)->disabled(fn (): bool => $this->curriculumVersionId !== null)->dehydrated()
                            ->options(fn (Get $get): array => CurriculumVersion::query()
                                ->where('program_id', $get('program_id'))
                                ->where('state', CurriculumVersion::StateActive)
                                ->orderByDesc('id')
                                ->pluck('name', 'id')
                                ->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set, BuildTermOfferings $builder) => $this->loadEligibleRows($get, $set, $builder)),
                        Select::make('year_level')
                            ->default(fn (): ?string => $this->yearLevel)->disabled(fn (): bool => $this->yearLevel !== null)->dehydrated()
                            ->options(fn (Get $get): array => $this->yearLevelOptions($get('curriculum_version_id'), $get('term_id')))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set, BuildTermOfferings $builder) => $this->loadEligibleRows($get, $set, $builder)),
                    ]),
                Repeater::make('rows')
                    ->label('Review eligible courses and class setup')
                    ->helperText('Include the courses to build. Academic facts are inherited and read only; confirm the expected students, modality, class capacity and delivery groups before building.')
                    ->defaultItems(0)
                    ->collapsed()->collapsible()
                    ->afterStateHydrated(fn (Get $get, Set $set, BuildTermOfferings $builder) => $this->loadEligibleRows($get, $set, $builder))
                    ->itemLabel(fn (array $state): string => ($state['already_offered'] ?? false ? 'Already offered · ' : 'New · ').(strtok((string) ($state['inherited_facts'] ?? ''), "\n") ?: 'Eligible course'))
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->schema($this->offeringRowsSchema())
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, BuildTermOfferings $builder): void {
                $actor = auth()->user();

                abort_unless($actor instanceof User, 403);
                $summary = $builder->regular(
                    $actor,
                    Term::query()->findOrFail($this->termId ?? $data['term_id']),
                    Program::query()->findOrFail($data['program_id']),
                    CurriculumVersion::query()->findOrFail($data['curriculum_version_id']),
                    $data['year_level'],
                    $data['rows'] ?? [],
                );
                $this->notifySummary($summary);
            });
    }

    private function specialOfferingAction(): Action
    {
        return Action::make('recordSpecialOffering')
            ->label('Record approved special offering')->slideOver()
            ->modalHeading('Record an approved special offering')
            ->modalDescription('Record an additional offering already approved outside TALA, including its reason and class setup. The course still comes from an active curriculum entry. This command records the offering, classes and delivery groups together; it does not grant the approval.')
            ->modalSubmitActionLabel('Record offering and classes')
            ->authorize(fn (): bool => auth()->user()?->can('create', TermOffering::class) ?? false)
            ->schema([
                Select::make('term_id')
                    ->label('Target Term')
                    ->default(fn (): ?int => $this->termId)
                    ->disabled(fn (): bool => $this->termId !== null)
                    ->dehydrated()
                    ->options(fn (): array => Term::query()->latest('starts_on')->pluck('label', 'id')->all())
                    ->required(),
                Select::make('curriculum_entry_id')
                    ->label('Active Curriculum Entry')
                    ->options(fn (): array => CurriculumEntry::query()
                        ->whereHas('curriculumVersion', fn ($query) => $query->where('state', CurriculumVersion::StateActive))
                        ->whereHas('courseSpecification', fn ($query) => $query->where('state', 'ACTIVE'))
                        ->with(['courseSpecification.course', 'curriculumVersion.program'])
                        ->get()
                        ->mapWithKeys(function (CurriculumEntry $entry): array {
                            $curriculum = $entry->getRelationValue('curriculumVersion');
                            $specification = $entry->getRelationValue('courseSpecification');
                            $program = $curriculum instanceof CurriculumVersion ? $curriculum->getRelationValue('program') : null;
                            $course = $specification instanceof CourseSpecification ? $specification->getRelationValue('course') : null;

                            return [
                                $entry->id => ($program instanceof Program ? $program->code : 'Program').' · '.($course instanceof Course ? $course->code : 'Course')." · {$entry->year_level} · {$entry->term_label}",
                            ];
                        })
                        ->all())
                    ->searchable()
                    ->required(),
                Textarea::make('special_reason')->label('Approved Special Offering Reason')->required()->maxLength(2000),
                Select::make('delivery_variant')->label('Delivery Arrangement')->options([
                    TermOffering::ArrangementNormalClass => 'Normal Class',
                    TermOffering::ArrangementTutorial => 'Tutorial',
                ])->required()->default(TermOffering::ArrangementTutorial),
                Select::make('modality')->options(TermOffering::modalityOptions())->required(),
                TextInput::make('expected_count')->label('Expected students')->numeric()->minValue(0)->required(),
                Section::make('Authorized overrides (optional)')
                    ->description('Use only when the approved offering needs an exception to its inherited course specification.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextInput::make('room_type_override')->label('Authorized room-type override'),
                        Toggle::make('same_faculty_override')->label('Authorized same-faculty override')->nullable(),
                    ]),
                Repeater::make('sections')
                    ->label('Classes for this offering')
                    ->addActionLabel('Add class')
                    ->schema($this->sectionSchema())
                    ->defaultItems(1)
                    ->minItems(1)
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, BuildTermOfferings $builder): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $summary = $builder->special(
                    $actor,
                    Term::query()->findOrFail($this->termId ?? $data['term_id']),
                    CurriculumEntry::query()->findOrFail($data['curriculum_entry_id']),
                    $data,
                );
                $this->notifySummary($summary);
            });
    }

    /**
     * @return array<int, mixed>
     */
    private function offeringRowsSchema(): array
    {
        return [
            Toggle::make('include')->label('Include this course')->default(true),
            Hidden::make('curriculum_entry_id'),
            Hidden::make('already_offered'),
            Textarea::make('inherited_facts')
                ->label('Academic facts from the course specification (read only)')
                ->disabled()
                ->dehydrated(false)
                ->rows(3)
                ->columnSpanFull(),
            Select::make('modality')
                ->options(fn (Get $get): array => collect($get('allowed_modalities') ?? [])
                    ->mapWithKeys(fn (string $modality): array => [$modality => TermOffering::modalityOptions()[$modality] ?? str($modality)->lower()->headline()->toString()])
                    ->all())
                ->required(),
            Hidden::make('allowed_modalities'),
            TextInput::make('expected_count')->label('Expected students')->numeric()->minValue(0)->required(),
            Section::make('Authorized overrides (optional)')
                ->description('Inherited room-type and same-faculty rules apply unless an approved exception is recorded.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextInput::make('room_type_override')->label('Authorized room-type override'),
                    Toggle::make('same_faculty_override')->label('Authorized same-faculty override')->nullable(),
                ]),
            Repeater::make('sections')
                ->label('Classes for this course')
                ->addActionLabel('Add class')
                ->schema($this->sectionSchema())
                ->defaultItems(1)
                ->minItems(1)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function sectionSchema(): array
    {
        return [
            TextInput::make('code')->label('Class / section code')->required(),
            TextInput::make('capacity')->label('Class capacity')->numeric()->minValue(1)->required()->default(30),
            Repeater::make('delivery_groups')
                ->label('Delivery groups')
                ->helperText('Each group describes students taught together within this class. Confirm its expected students and teaching modality.')
                ->addActionLabel('Add delivery group')
                ->schema([
                    TextInput::make('name')->label('Group name')->required()->default('Regular Cohort'),
                    TextInput::make('expected_count')->label('Expected students')->numeric()->minValue(0)->required(),
                    Select::make('modality')->options(TermOffering::modalityOptions())->required(),
                ])
                ->defaultItems(1)
                ->minItems(1)
                ->columnSpanFull(),
        ];
    }

    private function loadEligibleRows(Get $get, Set $set, BuildTermOfferings $builder): void
    {
        $term = Term::query()->find($get('term_id'));
        $program = Program::query()->find($get('program_id'));
        $curriculum = CurriculumVersion::query()->find($get('curriculum_version_id'));
        $yearLevel = $get('year_level');

        if (! $term instanceof Term || ! $program instanceof Program || ! $curriculum instanceof CurriculumVersion || blank($yearLevel)) {
            $set('rows', []);

            return;
        }

        $rows = [];
        $existingOfferings = TermOffering::query()->whereBelongsTo($term)->where('delivery_variant', TermOffering::ArrangementNormalClass)->with('sections.deliveryGroups')->get()->keyBy('curriculum_entry_id');

        foreach ($builder->preview($term, $program, $curriculum, (string) $yearLevel) as $entry) {
            $specification = $entry->getRelationValue('courseSpecification');

            if (! $specification instanceof CourseSpecification) {
                continue;
            }

            $course = $specification->getRelationValue('course');
            $componentRecords = $specification->getRelationValue('components');
            $allowedModalities = $specification->getAttribute('allowed_modalities');
            $components = $componentRecords instanceof Collection
                ? $componentRecords->map(fn ($component): string => (CourseComponent::typeOptions()[$component->component_type] ?? $component->component_type).": {$component->weekly_contact_hours} hrs/week")->implode(', ')
                : '';
            $modalities = is_array($allowedModalities) ? collect($allowedModalities)->map(fn (string $modality): string => TermOffering::modalityOptions()[$modality] ?? $modality)->implode(', ') : '';
            $allowedModalities = is_array($allowedModalities) ? $allowedModalities : [];
            $courseCode = $course instanceof Course ? $course->code : 'Course';
            $existing = $existingOfferings->get($entry->id);
            $isProtected = $existing instanceof TermOffering
                && in_array($existing->state, [TermOffering::StateScheduled, TermOffering::StateCancelled], true);
            $sectionRows = $existing instanceof TermOffering
                ? $this->existingSectionRows($existing)
                : [];

            if ($sectionRows === []) {
                $logicalCohortCode = "{$program->code}-{$this->yearLevelCode($entry->year_level)}A";
                $sectionRows = [[
                    'code' => "{$logicalCohortCode}-{$courseCode}",
                    'capacity' => 30,
                    'delivery_groups' => [[
                        'name' => $logicalCohortCode,
                        'expected_count' => null,
                        'modality' => $allowedModalities[0] ?? null,
                    ]],
                ]];
            }

            $selectedModality = $existing instanceof TermOffering ? $existing->modality : ($allowedModalities[0] ?? null);
            $expectedCount = $existing instanceof TermOffering ? $existing->expected_count : null;
            $roomTypeOverride = $existing instanceof TermOffering ? $existing->room_type_override : null;
            $sameFacultyOverride = $existing instanceof TermOffering ? $existing->same_faculty_override : null;

            $rows[] = [
                'include' => ! $isProtected,
                'curriculum_entry_id' => $entry->id,
                'already_offered' => $existing instanceof TermOffering,
                'inherited_facts' => "{$courseCode} — {$specification->title}\nUnits: {$specification->credit_units}; Components: {$components}; Modalities: {$modalities}; Grading: {$specification->grading_profile_key}; Same-faculty default: ".($specification->same_faculty_default ? 'Yes' : 'No').($existing instanceof TermOffering ? "\nExisting offering state: {$existing->state}" : ''),
                'allowed_modalities' => $allowedModalities,
                'modality' => $selectedModality,
                'expected_count' => $expectedCount,
                'room_type_override' => $roomTypeOverride,
                'same_faculty_override' => $sameFacultyOverride,
                'sections' => $sectionRows,
            ];
        }

        $set('rows', $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function existingSectionRows(TermOffering $offering): array
    {
        return $offering->sections->sortBy('code')
            ->map(function (OfferingSection $section): array {
                $groups = $section->deliveryGroups->sortBy('name')
                    ->map(fn (SectionDeliveryGroup $group): array => [
                        'name' => $group->name,
                        'expected_count' => $group->expected_count,
                        'modality' => $group->modality,
                    ])
                    ->all();

                return [
                    'code' => $section->code,
                    'capacity' => $section->capacity,
                    'delivery_groups' => $groups,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function yearLevelOptions(?int $curriculumId, ?int $termId): array
    {
        $term = Term::query()->find($termId);

        return CurriculumEntry::query()->where('curriculum_version_id', $curriculumId)
            ->when($term, fn ($query) => $query->where('term_type', $term->type))
            ->distinct()->pluck('year_level', 'year_level')->all();
    }

    private function yearLevelCode(string $yearLevel): string
    {
        return match ($yearLevel) {
            'First Year' => '1', 'Second Year' => '2', 'Third Year' => '3', 'Fourth Year' => '4', 'Fifth Year' => '5',
            default => str($yearLevel)->slug()->upper()->toString(),
        };
    }

    /**
     * @param  array{created: int, updated: int, skipped: int, blocked: int, messages: list<string>}  $summary
     */
    private function notifySummary(array $summary): void
    {
        $body = "Created: {$summary['created']}; Updated: {$summary['updated']}; Skipped: {$summary['skipped']}; Blocked: {$summary['blocked']}.";

        if ($summary['messages'] !== []) {
            $body .= ' '.implode(' ', $summary['messages']);
        }

        Notification::make()
            ->title('Term offering confirmation complete')
            ->body($body)
            ->success()
            ->send();
    }
}
