<?php

namespace App\Filament\Resources\CourseSpecifications\Schemas;

use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Support\RoomEquipment;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CourseSpecificationForm
{
    public static function configure(Schema $schema, ?int $courseId = null): Schema
    {
        return $schema->components([
            Section::make('Subject revision')
                ->description('Define the subject title, units, and classification for this revision.')
                ->contained(false)
                ->schema([
                    Select::make('course_id')
                        ->label('Subject code')->hidden($courseId !== null)->dehydrated()
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
                    Select::make('academic_classification')
                        ->label('Academic classification')
                        ->options(CourseSpecification::academicClassificationOptions())
                        ->default(CourseSpecification::AcademicClassificationOrdinary)
                        ->required(),
                    Textarea::make('description')
                        ->label('Subject description (optional)')
                        ->maxLength(2000)
                        ->rows(2)
                        ->columnSpanFull(),
                    Hidden::make('grading_profile_key')->default(CourseSpecification::GradingProfileServitechV1),
                    Hidden::make('grading_profile_version')->default(1),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),
            Section::make('Teaching & class meetings')
                ->description('Set the class schedule, delivery modes, and weekly lecture or laboratory meetings.')
                ->contained(false)
                ->schema([
                    Select::make('scheduling_treatment')
                        ->label('Class schedule')
                        ->options(CourseSpecification::schedulingTreatmentOptions())
                        ->default(CourseSpecification::SchedulingRecurring)
                        ->live()
                        ->required(),
                    CheckboxList::make('allowed_modalities')
                        ->label('Allowed delivery modes')
                        ->options(CourseSpecification::modalityOptions())
                        ->columns(2)
                        ->required(),
                    Toggle::make('same_faculty_default')
                        ->label('Same teacher for linked class meetings')
                        ->inline(false)
                        ->default(false),
                    self::classMeetings(relationship: true),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),
            Section::make('Prerequisites, corequisites and equivalencies')
                ->description('Optional subject requirements recorded from the approved curriculum.')
                ->contained(false)
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
    public static function newSubjectFields(int $columns = 2): array
    {
        $requirements = [];
        foreach (['prerequisite_ids' => 'Prerequisites', 'corequisite_ids' => 'Corequisites', 'equivalent_ids' => 'Approved equivalent subjects'] as $key => $label) {
            $requirements[] = Select::make($key)->label($label)->multiple()->searchable()->preload()->default([])->options(fn (): array => self::courseOptions());
        }

        return [
            Section::make('Subject information')->contained(false)->schema([
                TextInput::make('code')->label('Subject code')->required()->maxLength(64),
                ...self::definitionFields(),
                Select::make('academic_classification')->label('Academic classification')->options(CourseSpecification::academicClassificationOptions())->default(CourseSpecification::AcademicClassificationOrdinary)->required(),
            ])->columns($columns === 2 ? ['default' => 1, 'md' => 2] : $columns)->columnSpanFull(),
            Section::make('Teaching requirements')->contained(false)
                ->schema(self::teachingFields($columns))->columns($columns === 2 ? ['default' => 1, 'md' => 2] : $columns)->columnSpanFull(),
            Section::make('Prerequisites and equivalents')->contained(false)
                ->description('Select only requirements in the approved subject plan.')
                ->schema($requirements)->columns($columns === 2 ? ['default' => 1, 'md' => 2] : $columns)->columnSpanFull(),
        ];
    }

    public static function workbenchFields(): array
    {
        return [...self::definitionFields(), ...self::teachingFields()];
    }

    private static function definitionFields(): array
    {
        return [
            TextInput::make('title')
                ->label('Subject name')
                ->required()
                ->maxLength(255),
            TextInput::make('credit_units')
                ->label('Units')
                ->numeric()
                ->minValue(0.25)
                ->maxValue(30)
                ->step(0.25)
                ->required(),
            Hidden::make('grading_profile_key')->default(CourseSpecification::GradingProfileServitechV1),
            Hidden::make('grading_profile_version')->default(1),
        ];
    }

    private static function teachingFields(int $columns = 2): array
    {
        return [
            Select::make('scheduling_treatment')
                ->label('Class schedule')->default(CourseSpecification::SchedulingRecurring)
                ->options(CourseSpecification::schedulingTreatmentOptions())
                ->live()
                ->required(),
            CheckboxList::make('allowed_modalities')
                ->label('Allowed delivery modes')
                ->options(CourseSpecification::modalityOptions())
                ->columns(2)
                ->required(),
            Toggle::make('same_faculty_default')
                ->label('Same teacher for linked class meetings')
                ->inline(false)
                ->default(false),
            self::classMeetings(columns: $columns),
        ];
    }

    private static function classMeetings(bool $relationship = false, int $columns = 2): Repeater
    {
        $field = Repeater::make('components')->label('Class meetings')->columnSpanFull()
            ->extraAttributes(['class' => 'tala-record-repeater'])
            ->schema([
                Select::make('component_type')
                    ->label('Class format')
                    ->options(CourseComponent::typeOptions())
                    ->default(CourseComponent::TypeLecture)
                    ->required(),
                Select::make('meeting_pattern')
                    ->label('Weekly meeting pattern')
                    ->options(CourseComponent::meetingPatternOptions())
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
                    ->helperText('Auto-calculated from pattern; adjust if needed.')
                    ->required()
                    ->columnSpanFull(),
                Section::make('Room and teaching constraints')->contained(false)->collapsible()->collapsed()
                    ->schema([
                        Select::make('room_type_default')->label('Room type')->options(CourseComponent::roomTypeOptions())->nullable(),
                        RoomEquipment::select('required_room_feature_keys')->multiple()->label('Required room equipment'),
                        Select::make('modality_restriction')->label('Delivery mode for this meeting')->options(CourseSpecification::modalityOptions())->nullable(),
                        Toggle::make('requires_consecutive_block')->label('Consecutive block')->inline(false)->default(false),
                    ])->columns($columns === 2 ? ['default' => 1, 'md' => 2] : $columns)->columnSpanFull(),
                Hidden::make('same_faculty')->default(false),
                Hidden::make('sequence')->default(1),
            ])->columns($columns === 2 ? ['default' => 1, 'md' => 2] : $columns)
            ->defaultItems(1)->addActionLabel('Add class meeting')
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
}
