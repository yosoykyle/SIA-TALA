<?php

namespace App\Filament\Resources\CourseSpecifications\Schemas;

use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use App\Support\RoomEquipment;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\GridDirection;
use Illuminate\Support\Str;

class CourseSpecificationForm
{
    public static function configure(Schema $schema, ?int $courseId = null): Schema
    {
        return $schema->components([
            Section::make('Subject basics')
                ->description('Set the subject name, unit value, and subject type for this revision.')
                ->schema([
                    Select::make('course_id')
                        ->label('Subject code')
                        ->hidden($courseId !== null)
                        ->dehydrated()
                        ->options(fn (): array => self::courseOptions())
                        ->default(fn (mixed $livewire): ?int => $courseId ?? ((is_object($livewire) && property_exists($livewire, 'courseId') && is_int($livewire->courseId)) ? $livewire->courseId : (request()->integer('courseId') ?: (request()->integer('course_id') ?: null))))
                        ->searchable()
                        ->preload()
                        ->required(),
                    Hidden::make('revision_code')->default(fn (): string => (string) Str::ulid()),
                    TextInput::make('title')
                        ->label('Subject name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('credit_units')
                        ->label('Units')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(30)
                        ->step(0.25)
                        ->required(),
                    self::subjectTypeField(),
                    Textarea::make('description')
                        ->label('Subject description (optional)')
                        ->placeholder('Brief overview of topics, competencies, or outcomes covered in this subject.')
                        ->maxLength(2000)
                        ->rows(2)
                        ->columnSpanFull(),
                    Hidden::make('grading_profile_key')->default(CourseSpecification::GradingProfileServitechV1),
                    Hidden::make('grading_profile_version')->default(1),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),
            Section::make('Weekly classes & teaching')
                ->description('Choose the schedule type, delivery modes, and weekly lecture or lab sessions.')
                ->schema([
                    self::scheduleTypeField(),
                    CheckboxList::make('allowed_modalities')
                        ->label('Allowed delivery modes')
                        ->options(CourseSpecification::modalityOptions())
                        ->columns(2)
                        ->live()
                        ->required()
                        ->columnSpanFull(),
                    self::sameFacultyToggle(),
                    self::classMeetings(relationship: true),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),
            Section::make('Prerequisites & equivalents (optional)')
                ->description('Optional rules from the approved curriculum. Use approved equivalents for older or bridging subject codes that count in place of this subject.')
                ->schema([
                    Repeater::make('requirements')
                        ->label('Subject requirements')
                        ->relationship()
                        ->extraAttributes(['class' => 'tala-record-repeater'])
                        ->schema([
                            Select::make('rule_type')
                                ->label('Requirement type')
                                ->options(CourseRequirement::typeOptions())
                                ->default(CourseRequirement::TypePrerequisite)
                                ->required(),
                            Hidden::make('group_key')->default(fn (): string => (string) Str::ulid()),
                            Select::make('related_course_id')
                                ->label('Related subject')
                                ->options(fn (): array => self::courseOptions())
                                ->searchable()
                                ->preload()
                                ->required(),
                            Hidden::make('state')->default(CourseRequirement::StateActive),
                            Hidden::make('sequence')->default(1),
                        ])
                        ->columns(['default' => 1, 'md' => 2])
                        ->defaultItems(0)
                        ->addActionLabel('Add requirement'),
                ])
                ->columnSpanFull(),
        ]);
    }

    /** @return array<Component> */
    public static function newSubjectFields(int $columns = 2, bool $withCurriculumShortcut = false): array
    {
        $requirements = [];
        foreach (['prerequisite_ids' => 'Prerequisites', 'corequisite_ids' => 'Corequisites', 'equivalent_ids' => 'Approved equivalent subjects'] as $key => $label) {
            $field = Select::make($key)->label($label)->multiple()->searchable()->preload()->default([])->options(fn (): array => self::courseOptions());

            if ($key === 'equivalent_ids') {
                $field
                    ->helperText('Older or alternate subject codes (such as bridging or previous-curriculum subjects) accepted in place of this subject and its prerequisites.')
                    ->columnSpanFull();
            }

            $requirements[] = $field;
        }

        $sections = [
            Section::make('Subject basics')
                ->description('Enter the subject code, name, units, and subject type.')
                ->schema([
                    TextInput::make('code')->label('Subject code')->required()->maxLength(64),
                    ...self::definitionFields(includeSubjectType: true),
                ])
                ->columns($columns === 2 ? ['default' => 1, 'md' => 2] : $columns)
                ->columnSpanFull(),
            Section::make('Weekly classes & teaching')
                ->description('Choose the schedule type, delivery modes, and weekly lecture or lab sessions.')
                ->schema(self::teachingFields($columns, defaultFaceToFace: true))
                ->columns($columns === 2 ? ['default' => 1, 'md' => 2] : $columns)
                ->columnSpanFull(),
            Section::make('Prerequisites & equivalents (optional)')
                ->description('Select only requirements in the approved subject plan.')
                ->schema($requirements)
                ->columns($columns === 2 ? ['default' => 1, 'md' => 2] : $columns)
                ->columnSpanFull(),
        ];

        if ($withCurriculumShortcut) {
            $sections[] = Section::make('Add to curricula (optional)')
                ->description('Optionally place this subject into open draft curricula.')
                ->schema(self::curriculumAssignmentFields($columns))
                ->columns($columns === 2 ? ['default' => 1, 'md' => 2] : $columns)
                ->columnSpanFull();
        }

        return $sections;
    }

    /** @return array<int, Component> */
    public static function curriculumAssignmentFields(int $columns = 2, bool $includeDoNotAssign = true): array
    {
        $options = $includeDoNotAssign
            ? ['none' => 'Do not assign yet']
            : [];
        $descriptions = $includeDoNotAssign
            ? ['none' => 'Save to catalog only.']
            : [];

        $options += [
            'all_active' => 'All active programs (same year & term)',
            'selected' => 'Selected programs (same year & term)',
            'custom' => 'Custom per program',
        ];
        $descriptions += [
            'all_active' => 'Adds to every active program with an open draft.',
            'selected' => 'Pick programs sharing one year and term.',
            'custom' => 'Set a different year and term per program.',
        ];

        $yearOptions = [
            1 => 'Year 1',
            2 => 'Year 2',
            3 => 'Year 3',
            4 => 'Year 4',
            5 => 'Year 5',
        ];
        $termOptions = [
            Term::TypeFirstSemester => '1st Semester',
            Term::TypeSecondSemester => '2nd Semester',
            Term::TypeSpecialTerm => 'Summer',
        ];

        return [
            Radio::make('apply_to')
                ->label('Placement option')
                ->options($options)
                ->descriptions($descriptions)
                ->default($includeDoNotAssign ? 'none' : 'custom')
                ->columns(['default' => 1, 'sm' => 2])
                ->gridDirection(GridDirection::Row)
                ->extraAttributes([
                    'class' => 'tala-choice-options',
                    'style' => 'grid-template-columns: repeat(auto-fit, minmax(min(100%, 21rem), 1fr));',
                ])
                ->live()
                ->columnSpanFull(),
            Text::make(fn (): string => self::draftAvailabilityHint() ?? '')
                ->color('neutral')
                ->visible(fn (Get $get): bool => ($get('apply_to') !== 'none') && self::draftAvailabilityHint() !== null)
                ->columnSpanFull(),
            CheckboxList::make('program_ids')
                ->label('Selected programs')
                ->options(fn (): array => self::activeProgramAssignmentOptions())
                ->disableOptionWhen(fn (string $value): bool => ! self::programHasEditableDraft((int) $value))
                ->columns($columns === 2 ? 2 : 1)
                ->bulkToggleable()
                ->visible(fn (Get $get): bool => $get('apply_to') === 'selected')
                ->required(fn (Get $get): bool => $get('apply_to') === 'selected')
                ->columnSpanFull(),
            Grid::make(2)
                ->schema([
                    Select::make('placement_year_level')
                        ->label('Year level')
                        ->options($yearOptions)
                        ->default(1)
                        ->required(),
                    Select::make('placement_term_type')
                        ->label('Semester / Term')
                        ->options($termOptions)
                        ->default(Term::TypeFirstSemester)
                        ->required(),
                ])
                ->visible(fn (Get $get): bool => in_array($get('apply_to'), ['all_active', 'selected'], true))
                ->columnSpanFull(),
            Repeater::make('placements')
                ->label('Program placements')
                ->schema([
                    Select::make('program_id')
                        ->label('Program')
                        ->options(fn (): array => self::activeProgramAssignmentOptions())
                        ->disableOptionWhen(fn (string $value): bool => ! self::programHasEditableDraft((int) $value))
                        ->searchable()
                        ->distinct()
                        ->required(),
                    Select::make('year_level')
                        ->label('Year level')
                        ->options($yearOptions)
                        ->default(1)
                        ->required(),
                    Select::make('term_type')
                        ->label('Semester / Term')
                        ->options($termOptions)
                        ->default(Term::TypeFirstSemester)
                        ->required(),
                ])
                ->columns($columns === 2 ? ['default' => 1, 'md' => 3] : 1)
                ->defaultItems(1)
                ->addActionLabel('Add program placement')
                ->visible(fn (Get $get): bool => $get('apply_to') === 'custom')
                ->required(fn (Get $get): bool => $get('apply_to') === 'custom')
                ->columnSpanFull(),
        ];
    }

    /** @return array<int, Component> */
    public static function workbenchFields(): array
    {
        return [
            Section::make('Subject basics')
                ->compact()
                ->schema(self::definitionFields())
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),
            Section::make('Weekly classes & teaching')
                ->compact()
                ->schema(self::teachingFields())
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function subjectTypeOptions(): array
    {
        return CourseSpecification::academicClassificationOptions();
    }

    /**
     * @return array<string, string>
     */
    public static function weeklySessionOptions(): array
    {
        return [
            '1x60' => '1 session/week · 1 hr',
            '1x90' => '1 session/week · 1.5 hrs',
            '1x120' => '1 session/week · 2 hrs',
            '1x180' => '1 session/week · 3 hrs',
            '2x60' => '2 sessions/week · 1 hr each (2 hrs total)',
            '2x90' => '2 sessions/week · 1.5 hrs each (3 hrs total)',
            '2x120' => '2 sessions/week · 2 hrs each (4 hrs total)',
            '3x60' => '3 sessions/week · 1 hr each (3 hrs total)',
            '4x60' => '4 sessions/week · 1 hr each (4 hrs total)',
        ];
    }

    private static function subjectTypeField(): Select
    {
        return Select::make('academic_classification')
            ->label('Subject type')
            ->options(self::subjectTypeOptions())
            ->default(CourseSpecification::AcademicClassificationOrdinary)
            ->live()
            ->helperText('Controls GWA inclusion and marks TESDA competency courses. Link external TESDA NC assessments on the curriculum Review step.')
            ->required();
    }

    /**
     * @return array<int, Component>
     */
    private static function definitionFields(bool $includeSubjectType = false): array
    {
        $fields = [
            TextInput::make('title')
                ->label('Subject name')
                ->required()
                ->maxLength(255),
            TextInput::make('credit_units')
                ->label('Units')
                ->numeric()
                ->minValue(0)
                ->maxValue(30)
                ->step(0.25)
                ->rules([
                    fn (Get $get): string => $get('academic_classification') === CourseSpecification::AcademicClassificationNonCredit
                        ? 'gte:0'
                        : 'gt:0',
                ])
                ->required(),
        ];

        if ($includeSubjectType) {
            $fields[] = self::subjectTypeField();
        }

        $fields[] = Textarea::make('description')
            ->label('Subject description (optional)')
            ->placeholder('Brief overview of topics, competencies, or outcomes covered in this subject.')
            ->maxLength(2000)
            ->rows(2)
            ->columnSpanFull();
        $fields[] = Hidden::make('grading_profile_key')->default(CourseSpecification::GradingProfileServitechV1);
        $fields[] = Hidden::make('grading_profile_version')->default(1);

        return $fields;
    }

    private static function teachingFields(int $columns = 2, bool $defaultFaceToFace = false): array
    {
        $modalities = CheckboxList::make('allowed_modalities')
            ->label('Allowed delivery modes')
            ->options(CourseSpecification::modalityOptions())
            ->columns(2)
            ->live()
            ->required()
            ->columnSpanFull();

        if ($defaultFaceToFace) {
            $modalities->default([CourseSpecification::ModalityFaceToFace]);
        }

        return [
            self::scheduleTypeField(),
            $modalities,
            self::sameFacultyToggle(),
            self::classMeetings(columns: $columns),
        ];
    }

    private static function scheduleTypeField(): Radio
    {
        return Radio::make('scheduling_treatment')
            ->label('Schedule type')
            ->options([
                CourseSpecification::SchedulingRecurring => 'Weekly scheduled class',
                CourseSpecification::SchedulingExternallyArranged => 'Arranged / flexible schedule',
            ])
            ->descriptions([
                CourseSpecification::SchedulingRecurring => 'Assigned fixed weekly days & times on the timetable',
                CourseSpecification::SchedulingExternallyArranged => 'No fixed weekly room/time slot (e.g. OJT, Thesis)',
            ])
            ->default(CourseSpecification::SchedulingRecurring)
            ->columns(['default' => 1, 'sm' => 2])
            ->gridDirection(GridDirection::Row)
            ->extraAttributes([
                'class' => 'tala-choice-options tala-choice-options--cards',
                'style' => 'grid-template-columns: repeat(auto-fit, minmax(min(100%, 19rem), 1fr));',
            ])
            ->live()
            ->required()
            ->columnSpanFull();
    }

    private static function sameFacultyToggle(): Toggle
    {
        return Toggle::make('same_faculty_default')
            ->label('Same teacher for Lecture & Lab')
            ->helperText('Keep one teacher assigned to both the Lecture and Lab meetings of a section')
            ->inline(false)
            ->default(false)
            ->dehydratedWhenHidden()
            ->visible(fn (Get $get): bool => $get('scheduling_treatment') !== CourseSpecification::SchedulingExternallyArranged
                && count($get('components') ?? []) > 1)
            ->columnSpanFull();
    }

    private static function classMeetings(bool $relationship = false, int $columns = 2): Repeater
    {
        $field = Repeater::make('components')
            ->label('Class meetings')
            ->columnSpanFull()
            ->extraAttributes(['class' => 'tala-record-repeater'])
            ->live()
            ->schema([
                Select::make('component_type')
                    ->label('Class format')
                    ->options(CourseComponent::typeOptions())
                    ->default(CourseComponent::TypeLecture)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $set('room_type_default', match ($state) {
                            CourseComponent::TypeLecture => CourseComponent::RoomTypeLectureRoom,
                            CourseComponent::TypeLaboratory => CourseComponent::RoomTypeLaboratory,
                            default => null,
                        });
                    })
                    ->required(),
                Select::make('room_type_default')
                    ->label('Room type')
                    ->options(CourseComponent::roomTypeOptions())
                    ->default(CourseComponent::RoomTypeLectureRoom)
                    ->placeholder('Select room type')
                    ->nullable(),
                Select::make('meeting_pattern')
                    ->label('Weekly sessions')
                    ->options(self::weeklySessionOptions())
                    ->default('2x90')
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $parsed = CourseComponent::parseMeetingPattern((string) $state);

                        if ($parsed !== null) {
                            $set('weekly_contact_hours', round(($parsed['count'] * $parsed['duration_minutes']) / 60, 2));
                        }
                    })
                    ->required(),
                TextInput::make('weekly_contact_hours')
                    ->label('Weekly hours')
                    ->numeric()
                    ->default(3)
                    ->minValue(0.25)
                    ->maxValue(40)
                    ->step(0.25)
                    ->helperText('Auto-calculated from weekly sessions; adjust if needed.')
                    ->required(),
                RoomEquipment::select('required_room_feature_keys')
                    ->multiple()
                    ->label('Room equipment (optional)')
                    ->columnSpan(function (Get $get): array|string {
                        $modalities = (array) ($get('../../allowed_modalities') ?? []);
                        $showsModalityRestriction = in_array(CourseSpecification::ModalityFaceToFace, $modalities, true)
                            && in_array(CourseSpecification::ModalityOnline, $modalities, true);

                        return $showsModalityRestriction ? ['default' => 1, 'md' => 1] : 'full';
                    })
                    ->visible(function (Get $get): bool {
                        $modalities = (array) ($get('../../allowed_modalities') ?? []);

                        return in_array(CourseSpecification::ModalityFaceToFace, $modalities, true)
                            && $get('modality_restriction') !== CourseSpecification::ModalityOnline;
                    }),
                Select::make('modality_restriction')
                    ->label('Meeting delivery mode')
                    ->placeholder('Follow subject modes')
                    ->options(CourseSpecification::modalityOptions())
                    ->nullable()
                    ->live()
                    ->visible(function (Get $get): bool {
                        $modalities = (array) ($get('../../allowed_modalities') ?? []);

                        return in_array(CourseSpecification::ModalityFaceToFace, $modalities, true)
                            && in_array(CourseSpecification::ModalityOnline, $modalities, true);
                    }),
                Hidden::make('requires_consecutive_block')->default(false),
                Hidden::make('same_faculty')->default(false),
                Hidden::make('sequence')->default(1),
            ])
            ->columns($columns === 2 ? ['default' => 1, 'md' => 2] : $columns)
            ->defaultItems(1)
            ->addActionLabel('Add class meeting')
            ->visible(fn (Get $get): bool => $get('scheduling_treatment') !== CourseSpecification::SchedulingExternallyArranged);

        return $relationship ? $field->relationship()->orderColumn('sequence') : $field;
    }

    /**
     * @return array<int, string>
     */
    private static function courseOptions(): array
    {
        return Course::query()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Course $course): array => [$course->id => $course->code])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function activeProgramAssignmentOptions(): array
    {
        return Program::query()
            ->where('is_active', true)
            ->with(['curriculumVersions' => fn ($query) => $query
                ->where('state', CurriculumVersion::StateDraft)
                ->orderByDesc('id')])
            ->orderBy('code')
            ->get()
            ->mapWithKeys(function (Program $program): array {
                $draft = $program->curriculumVersions
                    ->first(fn (CurriculumVersion $version): bool => $version->isEditableDraft());

                $statusNote = $draft instanceof CurriculumVersion
                    ? "Draft: {$draft->name}"
                    : 'No editable draft — duplicate active plan first';

                return [$program->id => "{$program->code} — {$program->name} ({$statusNote})"];
            })
            ->all();
    }

    private static function programHasEditableDraft(int $programId): bool
    {
        return CurriculumVersion::query()
            ->where('program_id', $programId)
            ->where('state', CurriculumVersion::StateDraft)
            ->orderByDesc('id')
            ->get()
            ->contains(fn (CurriculumVersion $version): bool => $version->isEditableDraft());
    }

    private static function draftAvailabilityHint(): ?string
    {
        $programs = Program::query()
            ->where('is_active', true)
            ->with(['curriculumVersions' => fn ($query) => $query
                ->where('state', CurriculumVersion::StateDraft)
                ->orderByDesc('id')])
            ->get();

        if ($programs->isEmpty()) {
            return 'No active programs exist yet.';
        }

        $editableCount = $programs
            ->filter(fn (Program $program): bool => $program->curriculumVersions
                ->contains(fn (CurriculumVersion $version): bool => $version->isEditableDraft()))
            ->count();

        $total = $programs->count();

        if ($editableCount === $total) {
            return null;
        }

        if ($editableCount === 0) {
            return 'Adds to programs that have an open draft curriculum. Duplicate an active curriculum first to edit it.';
        }

        return "Adds to {$editableCount} of {$total} active ".Str::plural('program', $total).' with an open draft curriculum. Duplicate an active curriculum first to edit locked programs.';
    }
}
