<?php

namespace App\Filament\Resources\TermOfferings\Pages;

use App\Actions\Registrar\BuildTermOfferings;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Section as OfferingSection;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
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

class ListTermOfferings extends ListRecords
{
    protected static string $resource = TermOfferingResource::class;

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
            ->label('Build Regular Offerings')
            ->authorize(fn (): bool => auth()->user()?->can('create', TermOffering::class) ?? false)
            ->schema([
                Section::make('Offering Scope')
                    ->description('Select an active curriculum scope. Eligible rows inherit academic facts from Course Specifications.')
                    ->columns(4)
                    ->schema([
                        Select::make('term_id')
                            ->label('Target Term')
                            ->options(fn (): array => Term::query()->latest('starts_on')->pluck('label', 'id')->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set, BuildTermOfferings $builder) => $this->loadEligibleRows($get, $set, $builder)),
                        Select::make('program_id')
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
                            ->options($this->yearLevelOptions())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set, BuildTermOfferings $builder) => $this->loadEligibleRows($get, $set, $builder)),
                    ]),
                Repeater::make('rows')
                    ->label('Eligible Curriculum Entries')
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
                    Term::query()->findOrFail($data['term_id']),
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
            ->label('Record Approved Special Offering')
            ->authorize(fn (): bool => auth()->user()?->can('create', TermOffering::class) ?? false)
            ->schema([
                Select::make('term_id')
                    ->label('Target Term')
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
                TextInput::make('expected_count')->numeric()->minValue(0)->required(),
                TextInput::make('room_type_override')->label('Authorized Room-Type Override'),
                Toggle::make('same_faculty_override')->label('Authorized Same-Faculty Override')->nullable(),
                Repeater::make('sections')
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
                    Term::query()->findOrFail($data['term_id']),
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
            Toggle::make('include')->default(true),
            Hidden::make('curriculum_entry_id'),
            Textarea::make('inherited_facts')
                ->label('Inherited Course Specification (read only)')
                ->disabled()
                ->dehydrated(false)
                ->rows(3)
                ->columnSpanFull(),
            Select::make('modality')
                ->options(fn (Get $get): array => collect($get('allowed_modalities') ?? [])
                    ->mapWithKeys(fn (string $modality): array => [$modality => TermOffering::modalityOptions()[$modality] ?? str($modality)->headline()->toString()])
                    ->all())
                ->required(),
            Hidden::make('allowed_modalities'),
            TextInput::make('expected_count')->numeric()->minValue(0)->required(),
            TextInput::make('room_type_override')->label('Authorized Room-Type Override'),
            Toggle::make('same_faculty_override')->label('Same-Faculty Override')->nullable(),
            Repeater::make('sections')
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
            TextInput::make('code')->label('Confirmed Section Code')->required(),
            TextInput::make('capacity')->numeric()->minValue(1)->required()->default(30),
            Repeater::make('delivery_groups')
                ->label('Planned Delivery Groups')
                ->schema([
                    TextInput::make('name')->required()->default('Regular Cohort'),
                    TextInput::make('expected_count')->numeric()->minValue(0)->required()->default(30),
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

        foreach ($builder->preview($term, $program, $curriculum, (string) $yearLevel) as $entry) {
            $specification = $entry->getRelationValue('courseSpecification');

            if (! $specification instanceof CourseSpecification) {
                continue;
            }

            $course = $specification->getRelationValue('course');
            $componentRecords = $specification->getRelationValue('components');
            $allowedModalities = $specification->getAttribute('allowed_modalities');
            $components = $componentRecords instanceof Collection
                ? $componentRecords->map(fn ($component): string => "{$component->component_type}: {$component->weekly_contact_hours} hrs/week")->implode(', ')
                : '';
            $modalities = is_array($allowedModalities) ? collect($allowedModalities)->implode(', ') : '';
            $allowedModalities = is_array($allowedModalities) ? $allowedModalities : [];
            $courseCode = $course instanceof Course ? $course->code : 'Course';
            $existing = TermOffering::query()
                ->whereBelongsTo($term)
                ->whereBelongsTo($entry)
                ->where('delivery_variant', TermOffering::ArrangementNormalClass)
                ->first();
            $isProtected = $existing instanceof TermOffering
                && in_array($existing->state, [TermOffering::StateScheduled, TermOffering::StateCancelled], true);
            $sectionRows = $existing instanceof TermOffering
                ? $this->existingSectionRows($existing)
                : [];

            if ($sectionRows === []) {
                $sectionRows = [[
                    'code' => "{$program->code}-{$this->yearLevelNumber($entry->year_level)}-A",
                    'capacity' => 30,
                    'delivery_groups' => [[
                        'name' => 'Regular Cohort',
                        'expected_count' => 30,
                        'modality' => $allowedModalities[0] ?? null,
                    ]],
                ]];
            }

            $selectedModality = $existing instanceof TermOffering ? $existing->modality : ($allowedModalities[0] ?? null);
            $expectedCount = $existing instanceof TermOffering ? $existing->expected_count : 30;
            $roomTypeOverride = $existing instanceof TermOffering ? $existing->room_type_override : null;
            $sameFacultyOverride = $existing instanceof TermOffering ? $existing->same_faculty_override : null;

            $rows[] = [
                'include' => ! $isProtected,
                'curriculum_entry_id' => $entry->id,
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
        return OfferingSection::query()
            ->whereBelongsTo($offering, 'termOffering')
            ->orderBy('code')
            ->get()
            ->map(function (OfferingSection $section): array {
                $groups = SectionDeliveryGroup::query()
                    ->whereBelongsTo($section)
                    ->orderBy('name')
                    ->get()
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
    private function yearLevelOptions(): array
    {
        return [
            'First Year' => 'First Year',
            'Second Year' => 'Second Year',
            'Third Year' => 'Third Year',
            'Fourth Year' => 'Fourth Year',
            'Fifth Year' => 'Fifth Year',
        ];
    }

    private function yearLevelNumber(string $yearLevel): int
    {
        return (int) (Collection::make(array_keys($this->yearLevelOptions()))->search($yearLevel) + 1);
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
