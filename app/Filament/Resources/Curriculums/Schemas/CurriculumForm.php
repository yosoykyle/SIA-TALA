<?php

namespace App\Filament\Resources\Curriculums\Schemas;

use App\Models\CurriculumSubject;
use App\Models\Section;
use App\Models\Subject;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Schema;

class CurriculumForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            FormSection::make('Curriculum Header')
                ->description('Define the active curriculum version for a program before section planning and scheduling demand generation.')
                ->schema([
                    Select::make('program_id')
                        ->label('Program')
                        ->relationship('program', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('version_name')
                        ->label('Version Name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('effective_year')
                        ->required()
                        ->numeric()
                        ->minLength(4)
                        ->maxLength(4),
                    Toggle::make('is_active')
                        ->label('Active Curriculum')
                        ->default(true),
                    DateTimePicker::make('activated_at')
                        ->seconds(false)
                        ->nullable(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            FormSection::make('Curriculum Subjects')
                ->description('Map subjects to the year level and curriculum period used by section planning.')
                ->schema([
                    Repeater::make('curriculumSubjects')
                        ->relationship()
                        ->schema([
                            Select::make('subject_id')
                                ->label('Subject')
                                ->options(fn (): array => self::subjectOptions())
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('year_level')
                                ->label('Year Level')
                                ->options(Section::yearLevelOptions())
                                ->searchable()
                                ->required(),
                            Select::make('semester')
                                ->label('Curriculum Period')
                                ->options(Section::curriculumPeriodOptions())
                                ->required(),
                            TextInput::make('weekly_contact_hours')
                                ->label('Weekly Contact Hours')
                                ->numeric()
                                ->minValue(0)
                                ->step(0.25)
                                ->required(),
                            Select::make('academic_subject_type')
                                ->label('Academic Subject Type')
                                ->options(CurriculumSubject::academicSubjectTypeOptions())
                                ->searchable()
                                ->required(),
                            Select::make('scheduling_group')
                                ->label('Scheduling Group')
                                ->options(CurriculumSubject::schedulingGroupOptions())
                                ->searchable()
                                ->required(),
                            Select::make('delivery_rule_override')
                                ->label('Delivery Rule Override')
                                ->options(CurriculumSubject::deliveryRuleOverrideOptions())
                                ->searchable()
                                ->nullable(),
                            TextInput::make('sort_order')
                                ->integer()
                                ->minValue(0)
                                ->default(0),
                        ])
                        ->columns(4)
                        ->defaultItems(0)
                        ->addActionLabel('Add curriculum subject')
                        ->reorderable(false),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private static function subjectOptions(): array
    {
        return Subject::query()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Subject $subject): array => [
                $subject->id => "{$subject->code} - {$subject->description}",
            ])
            ->all();
    }
}
