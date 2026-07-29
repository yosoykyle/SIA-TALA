<?php

namespace App\Filament\Resources\CurriculumVersions\Pages;

use App\Actions\AcademicSetup\AcademicReadinessService;
use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReviewCurriculumVersion extends ManageRelatedRecords
{
    protected static string $resource = CurriculumVersionResource::class;

    protected static string $relationship = 'entries';

    protected static ?string $navigationLabel = 'Review Curriculum';

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;
        $user = auth()->user();

        return $record instanceof CurriculumVersion
            && $user instanceof User
            && $user->can('view', $record);
    }

    public function getTitle(): string
    {
        return 'Review Curriculum';
    }

    public function getSubheading(): string
    {
        $curriculum = $this->curriculumVersion();

        return "{$curriculum->program->code} · {$curriculum->version_code} · Review source values, Course Specification completeness, curriculum placement, and the next required action.";
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'courseSpecification.course',
                'courseSpecification.components',
            ]))
            ->columns([
                TextColumn::make('source_course')
                    ->label('Curriculum source')
                    ->state(fn (CurriculumEntry $record): string => $record->courseSpecification->course->code)
                    ->description(function (CurriculumEntry $record): string {
                        $specification = $record->courseSpecification;

                        if (! $specification instanceof CourseSpecification) {
                            return 'No Course Specification is linked.';
                        }

                        return "{$specification->title} · {$specification->credit_units} units";
                    })
                    ->searchable(query: fn ($query, string $search) => $query->whereHas(
                        'courseSpecification',
                        fn ($specification) => $specification
                            ->where('title', 'like', "%{$search}%")
                            ->orWhereHas('course', fn ($course) => $course->where('code', 'like', "%{$search}%")),
                    ))
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('placement')
                    ->label('Curriculum placement')
                    ->state(fn (CurriculumEntry $record): string => "Year {$record->year_level} · {$record->term_label}")
                    ->description(fn (CurriculumEntry $record): string => "Sequence {$record->sequence} · ".(
                        CurriculumEntry::requirementGroupOptions()[$record->requirement_group]
                        ?? str($record->requirement_group)->headline()
                    ))
                    ->wrap(),
                TextColumn::make('courseSpecification.revision_code')
                    ->label('Specification')
                    ->description(function (CurriculumEntry $record): string {
                        $specification = $record->courseSpecification;

                        if (! $specification instanceof CourseSpecification) {
                            return 'Missing';
                        }

                        $state = CourseSpecification::stateOptions()[$specification->state]
                            ?? str($specification->state)->headline();
                        $allowedModalities = $specification->getAttribute('allowed_modalities');
                        $modalities = collect(is_array($allowedModalities) ? $allowedModalities : [])
                            ->map(fn (string $modality): string => CourseSpecification::modalityOptions()[$modality] ?? str($modality)->headline())
                            ->implode(', ');

                        return "{$state} · ".($modalities === '' ? 'No modalities' : $modalities);
                    })
                    ->wrap(),
                TextColumn::make('readiness')
                    ->label('Readiness')
                    ->state(fn (CurriculumEntry $record): string => $this->readiness()->entryReadiness($record)['status'])
                    ->badge()
                    ->color(fn (CurriculumEntry $record): string => $this->readiness()->entryReadiness($record)['color']),
                TextColumn::make('blocker')
                    ->label('What blocks progress')
                    ->state(fn (CurriculumEntry $record): string => $this->readiness()->entryReadiness($record)['blocker'])
                    ->wrap(),
                TextColumn::make('next_action')
                    ->label('Next action')
                    ->state(fn (CurriculumEntry $record): string => $this->readiness()->entryReadiness($record)['next_action'])
                    ->weight('medium')
                    ->wrap(),
            ])
            ->headerActions([
                $this->addCurriculumRowAction(),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->editPlacementAction(),
                    $this->completeSpecificationAction(),
                    $this->viewSpecificationAction(),
                ])
                    ->label('Row actions')
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->tooltip('Curriculum row actions'),
            ])
            ->stackedOnMobile()
            ->defaultSort('sequence')
            ->emptyStateHeading('No curriculum rows are recorded')
            ->emptyStateDescription('Add rows manually to this Draft or import the curriculum CSV template, then return here to review every row.');
    }

    private function addCurriculumRowAction(): Action
    {
        return Action::make('addCurriculumRow')
            ->label('Add curriculum row')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Add a curriculum row')
            ->modalDescription('Select an authoritative Course Specification, then place it in this Draft curriculum.')
            ->schema($this->placementFields(includeSpecification: true))
            ->visible(fn (): bool => $this->currentUserCan('update'))
            ->action(function (array $data): void {
                app(CurriculumWorkbenchService::class)->createEntry(
                    actor: $this->actor(),
                    curriculumVersion: $this->curriculumVersion(),
                    data: $data,
                );

                Notification::make()
                    ->title('Curriculum row added')
                    ->body('The new row is ready for placement and specification review in this workbench.')
                    ->success()
                    ->send();
            });
    }

    private function editPlacementAction(): Action
    {
        return Action::make('editPlacement')
            ->label('Edit placement')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('Correct curriculum placement')
            ->modalDescription('Update where this subject belongs without leaving the curriculum review.')
            ->fillForm(fn (CurriculumEntry $record): array => [
                'year_level' => $record->year_level,
                'term_label' => $record->term_label,
                'term_type' => $record->term_type,
                'sequence' => $record->sequence,
                'requirement_group' => $record->requirement_group,
            ])
            ->schema($this->placementFields())
            ->visible(fn (): bool => $this->currentUserCan('update'))
            ->action(function (CurriculumEntry $record, array $data): void {
                app(CurriculumWorkbenchService::class)->updatePlacement(
                    actor: $this->actor(),
                    entry: $record,
                    data: $data,
                );

                Notification::make()
                    ->title('Curriculum placement updated')
                    ->body('The review table now reflects the corrected year, term, sequence, and requirement group.')
                    ->success()
                    ->send();
            });
    }

    private function completeSpecificationAction(): Action
    {
        return Action::make('completeSpecification')
            ->label('Complete specification')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->modalHeading('Complete Course Specification')
            ->modalDescription('Complete the scheduling fields for this Draft specification without leaving the curriculum review.')
            ->slideOver()
            ->fillForm(function (CurriculumEntry $record): array {
                $specification = $record->courseSpecification;

                if (! $specification instanceof CourseSpecification) {
                    return [];
                }

                $specification->loadMissing('components');

                return [
                    'title' => $specification->title,
                    'credit_units' => $specification->credit_units,
                    'grading_profile_key' => $specification->grading_profile_key,
                    'grading_profile_version' => $specification->grading_profile_version,
                    'allowed_modalities' => $specification->allowed_modalities,
                    'same_faculty_default' => $specification->same_faculty_default,
                    'components' => $specification->components
                        ->sortBy('sequence')
                        ->map(fn (CourseComponent $component): array => [
                            'component_type' => $component->component_type,
                            'weekly_contact_hours' => $component->weekly_contact_hours,
                            'room_type_default' => $component->room_type_default,
                            'required_room_feature_keys' => $component->required_room_feature_keys,
                            'modality_restriction' => $component->modality_restriction,
                            'requires_consecutive_block' => $component->requires_consecutive_block,
                            'same_faculty' => $component->same_faculty,
                            'sequence' => $component->sequence,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->schema($this->specificationFields())
            ->visible(fn (CurriculumEntry $record): bool => $this->canEditSpecification($record))
            ->action(function (CurriculumEntry $record, array $data): void {
                app(CurriculumWorkbenchService::class)->updateSpecification(
                    actor: $this->actor(),
                    entry: $record,
                    data: $data,
                );

                Notification::make()
                    ->title('Course specification updated')
                    ->body('The curriculum row has been re-evaluated against the scheduling-readiness rules.')
                    ->success()
                    ->send();
            });
    }

    private function viewSpecificationAction(): Action
    {
        return Action::make('viewSpecification')
            ->label('View specification')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->url(function (CurriculumEntry $record): ?string {
                $specification = $record->courseSpecification;

                return $specification instanceof CourseSpecification
                    ? CourseSpecificationResource::getUrl('view', ['record' => $specification])
                    : null;
            })
            ->visible(fn (CurriculumEntry $record): bool => $record->courseSpecification instanceof CourseSpecification
                && ! $this->canEditSpecification($record));
    }

    /**
     * @return array<int, Select|TextInput>
     */
    private function placementFields(bool $includeSpecification = false): array
    {
        $fields = [];

        if ($includeSpecification) {
            $fields[] = Select::make('course_specification_id')
                ->label('Course Specification')
                ->options(fn (): array => $this->courseSpecificationOptions())
                ->searchable()
                ->preload()
                ->required();
        }

        return [
            ...$fields,
            TextInput::make('year_level')
                ->label('Year Level')
                ->integer()
                ->minValue(1)
                ->maxValue(3)
                ->required(),
            Select::make('term_type')
                ->label('Term Type')
                ->options(Term::typeOptions())
                ->required(),
            TextInput::make('term_label')
                ->label('Term Label')
                ->helperText('Use the official label shown to staff and students, such as First Semester.')
                ->required()
                ->maxLength(255),
            TextInput::make('sequence')
                ->label('Display Sequence')
                ->integer()
                ->minValue(1)
                ->required(),
            Select::make('requirement_group')
                ->label('Requirement Group')
                ->options(CurriculumEntry::requirementGroupOptions())
                ->required(),
        ];
    }

    /**
     * @return array<int, CheckboxList|Repeater|Select|TextInput|Toggle>
     */
    private function specificationFields(): array
    {
        return [
            TextInput::make('title')
                ->label('Subject Title')
                ->required()
                ->maxLength(255),
            TextInput::make('credit_units')
                ->label('Credit Units')
                ->numeric()
                ->minValue(0.25)
                ->step(0.25)
                ->required(),
            Select::make('grading_profile_key')
                ->label('Grading Profile')
                ->options(CourseSpecification::gradingProfileOptions())
                ->required(),
            TextInput::make('grading_profile_version')
                ->label('Grading Profile Version')
                ->integer()
                ->minValue(1)
                ->required(),
            CheckboxList::make('allowed_modalities')
                ->label('Allowed Delivery Modalities')
                ->options(CourseSpecification::modalityOptions())
                ->columns(2)
                ->required(),
            Toggle::make('same_faculty_default')
                ->label('Use the same faculty for linked components')
                ->required(),
            Repeater::make('components')
                ->label('Course Components')
                ->helperText('At least one Lecture or Laboratory component is required for scheduling.')
                ->schema([
                    Select::make('component_type')
                        ->label('Component Type')
                        ->options(CourseComponent::typeOptions())
                        ->required(),
                    TextInput::make('weekly_contact_hours')
                        ->label('Weekly Contact Hours')
                        ->numeric()
                        ->minValue(0.25)
                        ->step(0.25)
                        ->required(),
                    Select::make('room_type_default')
                        ->label('Default Room Type')
                        ->options(CourseComponent::roomTypeOptions())
                        ->nullable(),
                    TagsInput::make('required_room_feature_keys')
                        ->label('Required Room Features'),
                    Select::make('modality_restriction')
                        ->label('Component Modality')
                        ->options(CourseSpecification::modalityOptions())
                        ->nullable(),
                    Toggle::make('requires_consecutive_block')
                        ->label('Requires Consecutive Block'),
                    Toggle::make('same_faculty')
                        ->label('Same Faculty')
                        ->required(),
                    TextInput::make('sequence')
                        ->integer()
                        ->minValue(1)
                        ->required(),
                ])
                ->columns(2)
                ->defaultItems(1)
                ->minItems(1)
                ->required()
                ->addActionLabel('Add component'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function courseSpecificationOptions(): array
    {
        return CourseSpecification::query()
            ->with('course')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (CourseSpecification $specification): array => [
                $specification->id => collect([
                    $specification->course?->code,
                    $specification->title,
                    $specification->revision_code,
                    CourseSpecification::stateOptions()[$specification->state] ?? str($specification->state)->headline(),
                ])->filter()->implode(' · '),
            ])
            ->all();
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('editCurriculumRows')
                ->label('Open full curriculum record')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(CurriculumVersionResource::getUrl('edit', ['record' => $this->curriculumVersion()]))
                ->visible(fn (): bool => $this->currentUserCan('update')),
            Action::make('recordApproval')
                ->label('Record external approval')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->schema([
                    TextInput::make('approval_reference')
                        ->label('Approval reference')
                        ->helperText('Enter the resolution, board, or institutional reference that approved this curriculum outside TALA.')
                        ->required()
                        ->maxLength(255),
                ])
                ->visible(fn (): bool => $this->currentUserCan('recordApproval'))
                ->action(function (array $data): void {
                    $actor = $this->actor();

                    $this->record = app(CurriculumVersionLifecycleService::class)->recordApproval(
                        actor: $actor,
                        curriculumVersion: $this->curriculumVersion(),
                        approvalReference: (string) ($data['approval_reference'] ?? ''),
                    );

                    Notification::make()
                        ->title('External approval recorded')
                        ->body('Review the activation impact before making this curriculum active.')
                        ->success()
                        ->send();
                }),
            Action::make('activateCurriculum')
                ->label('Activate complete curriculum')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Activate this curriculum for future handovers?')
                ->modalDescription(fn (): string => $this->activationDescription())
                ->modalSubmitActionLabel('Confirm activation')
                ->visible(fn (): bool => $this->currentUserCan('activate'))
                ->action(function (): void {
                    $this->record = app(CurriculumVersionLifecycleService::class)->activate(
                        actor: $this->actor(),
                        curriculumVersion: $this->curriculumVersion(),
                        confirmed: true,
                    );

                    Notification::make()
                        ->title('Curriculum activated')
                        ->body('Existing student curriculum assignments remain unchanged; this version now governs future handovers.')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function readiness(): AcademicReadinessService
    {
        return app(AcademicReadinessService::class);
    }

    private function curriculumVersion(): CurriculumVersion
    {
        $record = $this->getRecord();
        abort_unless($record instanceof CurriculumVersion, 404);

        return $record;
    }

    private function currentUserCan(string $ability): bool
    {
        return $this->actor()->can($ability, $this->curriculumVersion());
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function canEditSpecification(CurriculumEntry $entry): bool
    {
        $specification = $entry->courseSpecification;

        return $specification instanceof CourseSpecification
            && $this->actor()->can('update', $specification);
    }

    private function activationDescription(): string
    {
        $impact = app(CurriculumVersionLifecycleService::class)
            ->activationImpact($this->curriculumVersion());
        $previous = $impact['active_version_code'] ?? 'none';
        $readiness = $impact['readiness_errors'] === []
            ? 'All referenced Course Specifications are ready.'
            : 'Activation is blocked: '.implode(' ', $impact['readiness_errors']);

        return "Previous active version: {$previous}. Curriculum rows: {$impact['entries']}. Existing student curriculum assignments preserved: {$impact['existing_student_locks']}. {$readiness}";
    }
}
