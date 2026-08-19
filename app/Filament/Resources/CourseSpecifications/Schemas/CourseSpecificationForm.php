<?php

namespace App\Filament\Resources\CourseSpecifications\Schemas;

use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\Term;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CourseSpecificationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Revision Identity')
                ->description('Versioned academic definition used by curricula, offerings, enrollment, scheduling, COR, grades, and history.')
                ->schema([
                    Select::make('course_id')
                        ->label('Subject Code')
                        ->options(fn (): array => self::courseOptions())
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('revision_code')
                        ->label('Revision Identifier')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('title')
                        ->label('Subject Title')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Academic Definition')
                ->schema([
                    TextInput::make('credit_units')
                        ->label('Credit Units')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.25)
                        ->required(),
                    Select::make('grading_profile_key')
                        ->label('Grading Profile')
                        ->options(CourseSpecification::gradingProfileOptions())
                        ->default(CourseSpecification::GradingProfileServitechV1)
                        ->required(),
                    TextInput::make('grading_profile_version')
                        ->integer()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                    Select::make('scheduling_treatment')
                        ->label('Scheduling Treatment')
                        ->options(CourseSpecification::schedulingTreatmentOptions())
                        ->default(CourseSpecification::SchedulingRecurring)
                        ->live()
                        ->required(),
                    CheckboxList::make('allowed_modalities')
                        ->options(CourseSpecification::modalityOptions())
                        ->columns(3)
                        ->required(),
                    Toggle::make('same_faculty_default')
                        ->label('Same Faculty Default')
                        ->helperText('Enable only when the course design requires linked components to use the same faculty member.')
                        ->default(false),
                    Select::make('effective_term_id')
                        ->label('Effective Term')
                        ->options(fn (): array => self::termOptions())
                        ->searchable()
                        ->preload()
                        ->nullable(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Course Components')
                ->description('Lecture and Laboratory rows provide contact hours and default scheduling requirements.')
                ->schema([
                    Repeater::make('components')
                        ->relationship()
                        ->schema([
                            Select::make('component_type')
                                ->options(CourseComponent::typeOptions())
                                ->required(),
                            TextInput::make('weekly_contact_hours')
                                ->numeric()
                                ->minValue(0.25)
                                ->step(0.25)
                                ->required(),
                            Select::make('meeting_pattern')
                                ->label('Weekly Meeting Pattern')
                                ->options(CourseComponent::meetingPatternOptions())
                                ->required(),
                            Select::make('room_type_default')
                                ->options(CourseComponent::roomTypeOptions())
                                ->searchable()
                                ->nullable(),
                            TagsInput::make('required_room_feature_keys')
                                ->label('Required Room Features')
                                ->helperText('Use the same uppercase feature keys maintained on Room records.'),
                            Select::make('modality_restriction')
                                ->options(CourseSpecification::modalityOptions())
                                ->nullable(),
                            Toggle::make('requires_consecutive_block')
                                ->label('Consecutive Block'),
                            Toggle::make('same_faculty')
                                ->label('Same Faculty')
                                ->default(false),
                            TextInput::make('sequence')
                                ->integer()
                                ->minValue(1)
                                ->default(1)
                                ->required(),
                        ])
                        ->columns(4)
                        ->defaultItems(1)
                        ->visible(fn (Get $get): bool => $get('scheduling_treatment') !== CourseSpecification::SchedulingExternallyArranged)
                        ->addActionLabel('Add component'),
                ])
                ->columnSpanFull(),
            Section::make('Structured Requirements')
                ->description('Store prerequisites, corequisites, and approved equivalencies as selected course identities, not runtime free text.')
                ->schema([
                    Repeater::make('requirements')
                        ->relationship()
                        ->schema([
                            Select::make('rule_type')
                                ->options(CourseRequirement::typeOptions())
                                ->default(CourseRequirement::TypePrerequisite)
                                ->required(),
                            TextInput::make('group_key')
                                ->helperText('Rows with the same group key are alternatives; different groups are combined as required groups.')
                                ->default('G1')
                                ->required()
                                ->maxLength(255),
                            Select::make('related_course_id')
                                ->label('Related Course')
                                ->options(fn (): array => self::courseOptions())
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('required_outcome')
                                ->options(CourseRequirement::requiredOutcomeOptions())
                                ->default(CourseRequirement::RequiredOutcomePassed)
                                ->nullable(),
                            TextInput::make('minimum_grade')
                                ->numeric()
                                ->step(0.25)
                                ->nullable(),
                            Toggle::make('accepts_transfer_credit')
                                ->label('Accepts Transfer Credit'),
                            TextInput::make('authority')
                                ->default('Registrar-recorded curriculum setup')
                                ->required()
                                ->maxLength(255),
                            Select::make('state')
                                ->options(CourseRequirement::stateOptions())
                                ->default(CourseRequirement::StateActive)
                                ->required(),
                            TextInput::make('sequence')
                                ->integer()
                                ->minValue(1)
                                ->default(1)
                                ->required(),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->addActionLabel('Add requirement'),
                ])
                ->columnSpanFull(),
        ]);
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
    private static function termOptions(): array
    {
        return Term::query()
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(fn (Term $term): array => [
                $term->id => collect([
                    $term->academicYear?->label,
                    $term->label,
                ])->filter()->implode(' | '),
            ])
            ->all();
    }
}
